"""Microsoft Teams team/group management endpoints."""
import asyncio
import re
import time
from typing import Annotated, Literal

from fastapi import APIRouter, HTTPException, Query
from pydantic import BaseModel, Field
from starlette.exceptions import HTTPException as StarletteHTTPException

from app.models.teams import (
    BulkResult,
    BulkTeamsMemberAdd,
    BulkTeamsEmailAdd,
    BulkTeamsMemberRemove,
    TeamsChannelCreate,
    TeamsTeamUpdate,
)
from app.services import teams_client as graph
from app.services.teams_client import create_team_via_group, remove_member_from_group

router = APIRouter(prefix="/teams/teams", tags=["Teams · Teams"])

# Template OData types used by the Teams provisioning API
_TEMPLATES = {
    "standard": "https://graph.microsoft.com/v1.0/teamsTemplates('standard')",
    "educationClass": "https://graph.microsoft.com/v1.0/teamsTemplates('educationClass')",
    "educationStaff": "https://graph.microsoft.com/v1.0/teamsTemplates('educationStaff')",
    "educationProfessionalLearningCommunity": "https://graph.microsoft.com/v1.0/teamsTemplates('educationProfessionalLearningCommunity')",
}


@router.get("", summary="Listar todos los Teams del tenant")
async def list_teams(
    search_term: Annotated[str | None, Query(description="Buscar por displayName")] = None,
    top: Annotated[int, Query(ge=1, le=999)] = 50,
):
    params = {
        "$top": top,
        "$select": "id,displayName,description,visibility,createdDateTime",
        "$filter": "resourceProvisioningOptions/Any(x:x eq 'Team')",
    }

    if not search_term:
        return await graph.paginate("/groups", params)

    # $search hace un match de subcadena, case-insensitive, sobre displayName.
    # startswith(displayName, ...) (lo que se usaba antes) es sensible a
    # mayúsculas y solo encuentra coincidencias desde el inicio exacto del
    # nombre — buscar una palabra intermedia (ej. "Americano") o en minúscula
    # no encontraba equipos que sí existían. $search requiere el header
    # ConsistencyLevel: eventual.
    clean_term = search_term.replace('"', "")
    params["$search"] = f'"displayName:{clean_term}"'
    return await graph.paginate_limited(
        "/groups", params, max_records=top, extra_headers={"ConsistencyLevel": "eventual"}
    )


class TeamsTeamCreateSimple(BaseModel):
    display_name: str
    description: str | None = None
    visibility: Literal["Public", "Private", "HiddenMembership"] = "Private"
    owner_id: str = Field(..., description="Azure object ID del propietario")
    template: str | None = None


@router.post("", summary="Crear un Team nuevo")
async def create_team(body: TeamsTeamCreateSimple):
    nickname = graph.safe_mail_nickname(body.display_name)
    try:
        return await create_team_via_group(
            display_name=body.display_name,
            mail_nickname=nickname,
            description=body.description or "",
            visibility=body.visibility,
            owner_ids=[body.owner_id],
        )
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))


# NOTA: /deleted debe registrarse ANTES de /{team_id} — de lo contrario
# Starlette matchea GET /deleted contra la ruta genérica /{team_id} primero
# (mismo método, registrada antes) y nunca llega a este endpoint.
@router.get("/deleted", summary="Listar grupos eliminados recuperables (papelera de 30 días)")
async def list_deleted_teams():
    return await graph.list_deleted_groups()


class RestoreAndCheckRequest(BaseModel):
    group_ids: list[str]


@router.post("/deleted/restore-and-check", summary="Restaurar grupos eliminados y contar propietarios/miembros")
async def restore_and_check_deleted(req: RestoreAndCheckRequest):
    """Restaura cada grupo de la papelera y cuenta sus propietarios y miembros
    reales, para decidir con datos si conviene dejarlo restaurado (tenía gente
    matriculada) o volver a eliminarlo (estaba vacío, era un duplicado)."""
    results = []

    for gid in req.group_ids:
        entry = {"group_id": gid, "name": None, "restored": False,
                  "owners_count": None, "members_count": None, "error": None}
        try:
            await graph.restore_deleted_group(gid)
            entry["restored"] = True
        except Exception as e:
            entry["error"] = f"No se pudo restaurar: {e}"
            results.append(entry)
            continue

        try:
            info = await graph.get(f"/groups/{gid}", params={"$select": "displayName"})
            entry["name"] = info.get("displayName")
        except Exception:
            pass

        try:
            owners = await graph.paginate(f"/groups/{gid}/owners", params={"$select": "id"})
            entry["owners_count"] = len(owners)
        except Exception as e:
            entry["error"] = f"Restaurado, pero no se pudo contar propietarios: {e}"

        try:
            members = await graph.paginate(f"/groups/{gid}/members", params={"$select": "id"})
            entry["members_count"] = len(members)
        except Exception as e:
            prev = entry["error"] + " | " if entry["error"] else ""
            entry["error"] = f"{prev}No se pudo contar miembros: {e}"

        results.append(entry)

    return results


@router.get("/{team_id}", summary="Obtener Team por ID")
async def get_team(team_id: str):
    try:
        return await graph.get(f"/teams/{team_id}")
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=404, detail=str(exc))




@router.patch("/{team_id}", summary="Actualizar Team")
async def update_team(team_id: str, body: TeamsTeamUpdate):
    fields = body.model_dump(exclude_none=True)
    mapping = {"display_name": "displayName", "description": "description", "visibility": "visibility"}
    payload = {mapping[k]: v for k, v in fields.items() if k in mapping}
    try:
        return await graph.patch(f"/teams/{team_id}", payload)
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))


@router.delete("/{team_id}", summary="Eliminar Team")
async def delete_team(team_id: str):
    try:
        await graph.delete(f"/groups/{team_id}")
        return {"deleted": team_id}
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))


# ── Eliminación masiva por ID o nombre ─────────────────────────────────────────

_UUID_RE = re.compile(r'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$', re.IGNORECASE)


class TeamsResolveRequest(BaseModel):
    entries: list[str] = Field(..., description="IDs de grupo/Team o nombres de equipo, uno por entrada")


@router.post("/resolve", summary="Resolver Teams por ID o nombre (previsualización antes de eliminar)")
async def resolve_teams(req: TeamsResolveRequest):
    """Acepta una mezcla de IDs de grupo/Team y nombres de equipo, y devuelve
    para cada entrada si se encontró el equipo real y su ID/nombre resuelto —
    para que el usuario confirme antes de eliminar en lote."""
    seen: set[str] = set()

    async def resolve_one(raw: str):
        entry = raw.strip()
        if not entry or entry in seen:
            return None
        seen.add(entry)

        if _UUID_RE.match(entry):
            name = await graph.get_group_name_by_id(entry)
            return {"input": entry, "team_id": entry, "name": name, "found": name is not None}
        else:
            team_id = await graph.search_group_by_name(entry)
            name = await graph.get_group_name_by_id(team_id) if team_id else None
            return {"input": entry, "team_id": team_id, "name": name or entry, "found": team_id is not None}

    results = await asyncio.gather(*(resolve_one(e) for e in req.entries))
    return [r for r in results if r is not None]


class BulkDeleteTeamsRequest(BaseModel):
    team_ids: list[str]


@router.post("/bulk-delete", summary="Eliminación masiva de Teams por ID")
async def bulk_delete_teams(req: BulkDeleteTeamsRequest):
    """Toma una lista de IDs de grupo/Team (ya resueltos vía /resolve) y los elimina en lote."""
    result = {"succeeded": [], "failed": []}

    async def delete_one(team_id: str):
        name = await graph.get_group_name_by_id(team_id) or team_id
        try:
            await graph.delete(f"/groups/{team_id}")
            result["succeeded"].append({"team_id": team_id, "name": name})
        except Exception as e:
            result["failed"].append({"team_id": team_id, "name": name, "error": str(e)})

    for tid in req.team_ids:
        await delete_one(tid)

    return result


# ── Members ───────────────────────────────────────────────────────────────────

@router.get("/{team_id}/members", summary="Listar miembros del Team")
async def list_members(team_id: str):
    return await graph.paginate(f"/teams/{team_id}/members")




@router.post("/{team_id}/members/bulk-add", summary="Añadir miembros de forma conjunta")
async def bulk_add_members(team_id: str, body: BulkTeamsMemberAdd) -> BulkResult:
    # Graph supports up to 20 members per batch via addMembers action
    result = BulkResult()
    BATCH_SIZE = 20

    members_payload = [
        {
            "@odata.type": "#microsoft.graph.aadUserConversationMember",
            "roles": [m.role] if m.role == "owner" else [],
            "user@odata.bind": f"https://graph.microsoft.com/v1.0/users('{m.user_id}')",
        }
        for m in body.members
    ]

    for i in range(0, len(members_payload), BATCH_SIZE):
        batch = members_payload[i : i + BATCH_SIZE]
        try:
            resp = await graph.post(f"/teams/{team_id}/members/add", {"values": batch})
            added = resp.get("value", [])
            errors = resp.get("error", [])
            result.succeeded.extend(added)
            result.failed.extend(errors)
        except Exception as exc:
            for m in body.members[i : i + BATCH_SIZE]:
                result.failed.append({"user_id": m.user_id, "error": str(exc)})

    return result


@router.post("/{team_id}/members/bulk-add-emails", summary="Añadir miembros por lista de correos")
async def bulk_add_members_by_email(team_id: str, body: BulkTeamsEmailAdd) -> BulkResult:
    result = BulkResult()
    BATCH_SIZE = 20

    # 1. Lookup emails in Azure AD to get Object IDs
    user_ids = []
    
    async def _lookup(email: str):
        try:
            # First try userPrincipalName or mail directly
            # Often mailNickname is also supported, but upn is safer
            user = await graph.get(f"/users/{email.strip()}", params={"$select": "id,mail,userPrincipalName"})
            user_ids.append({"email": email, "id": user["id"]})
        except Exception as exc:
            # If not found by direct ID/UPN, try searching by mail
            try:
                search_res = await graph.get("/users", params={"$filter": f"mail eq '{email.strip()}'", "$select": "id"})
                if search_res.get("value") and len(search_res["value"]) > 0:
                    user_ids.append({"email": email, "id": search_res["value"][0]["id"]})
                else:
                    result.failed.append({"input": email, "error": "Usuario no encontrado en Azure AD"})
            except Exception as e:
                result.failed.append({"input": email, "error": "Usuario no encontrado en Azure AD"})

    # Run lookups concurrently
    await asyncio.gather(*[_lookup(email) for email in set(body.emails) if email.strip()])

    if not user_ids:
        return result

    # 2. Add them in batches
    members_payload = [
        {
            "@odata.type": "#microsoft.graph.aadUserConversationMember",
            "roles": [body.role] if body.role == "owner" else [],
            "user@odata.bind": f"https://graph.microsoft.com/v1.0/users('{u['id']}')",
        }
        for u in user_ids
    ]

    for i in range(0, len(members_payload), BATCH_SIZE):
        batch = members_payload[i : i + BATCH_SIZE]
        try:
            resp = await graph.post(f"/teams/{team_id}/members/add", {"values": batch})
            added = resp.get("value", [])
            errors = resp.get("error", [])
            
            # Map back to email if possible for better frontend reporting
            for success in added:
                result.succeeded.append(success)
            for err in errors:
                result.failed.append({"input": "Lote", "error": str(err)})
        except Exception as exc:
            for u in user_ids[i : i + BATCH_SIZE]:
                result.failed.append({"input": u["email"], "error": str(exc)})

    return result


class BulkGroupMemberAddByEmail(BaseModel):
    emails: list[str]


@router.post("/{group_id}/members/bulk-add-to-group", summary="Agregar usuarios existentes como miembros de un grupo (Team o grupo de seguridad/Microsoft 365 sin correo habilitado)")
async def bulk_add_members_to_any_group(group_id: str, body: BulkGroupMemberAddByEmail) -> BulkResult:
    """A diferencia de bulk-add/bulk-add-emails (que usan la API de miembros de
    conversación de Teams, /teams/{id}/members/add, y solo funcionan si el grupo
    tiene Teams provisionado), esto usa la API genérica de miembros de grupo
    (/groups/{id}/members/$ref) — funciona para Teams y para grupos de
    Microsoft 365 o de seguridad sin correo habilitado.

    NO funciona para grupos habilitados para correo (listas de distribución,
    grupos de seguridad con correo): Graph API no permite gestionar su
    membresía en absoluto — Microsoft exige Exchange Online PowerShell
    (Add-DistributionGroupMember) para esos casos, algo fuera del alcance de
    Graph y de esta app."""
    result = BulkResult()

    async def add_one(raw_email: str):
        email = raw_email.strip()
        if not email:
            return
        try:
            search_res = await graph.get(
                "/users",
                params={"$filter": f"mail eq '{email}' or userPrincipalName eq '{email}'", "$select": "id"},
            )
            users = search_res.get("value", [])
            if not users:
                result.failed.append({"input": email, "error": "Usuario no encontrado en Azure AD"})
                return
            user_id = users[0]["id"]
        except Exception as e:
            result.failed.append({"input": email, "error": f"Error buscando usuario: {e}"})
            return

        try:
            # Agregar una referencia a un miembro existente requiere el
            # sufijo /$ref — sin él, Graph interpreta el POST como un
            # intento de CREAR un recurso nuevo bajo la colección
            # "members" y responde 400 "Unsupported resource type
            # 'DirectoryObject' for operation 'Create'".
            await graph.post(
                f"/groups/{group_id}/members/$ref",
                {"@odata.id": f"https://graph.microsoft.com/v1.0/directoryObjects/{user_id}"},
            )
            result.succeeded.append({"input": email})
        except Exception as e:
            msg = str(e)
            msg_lower = msg.lower()
            if "already exist" in msg_lower:
                result.succeeded.append({"input": email, "note": "ya era miembro"})
            elif "mail-enabled" in msg_lower or "distribution list" in msg_lower:
                result.failed.append({
                    "input": email,
                    "error": (
                        "Este grupo es una lista de distribución o un grupo de seguridad "
                        "con correo habilitado — Microsoft Graph no permite gestionar su "
                        "membresía en absoluto (es un límite de la API, no de esta app). "
                        "Hay que agregarlo desde Exchange Online o el centro de "
                        "administración de Microsoft 365."
                    ),
                })
            else:
                result.failed.append({"input": email, "error": msg})

    await asyncio.gather(*(add_one(e) for e in body.emails))
    return result


@router.delete("/{team_id}/members/{membership_id}", summary="Quitar miembro del Team")
async def remove_member(team_id: str, membership_id: str):
    try:
        await graph.delete(f"/teams/{team_id}/members/{membership_id}")
        return {"removed": membership_id}
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))


class RemoveMemberByUpnRequest(BaseModel):
    group_id: str
    upn: str


@router.post("/remove-member-by-upn", summary="Quitar un miembro de un grupo/Team por su UPN, sin necesitar el membership_id")
async def remove_member_by_upn(body: RemoveMemberByUpnRequest):
    """Atajo para pantallas que solo tienen el correo institucional de la
    persona (ej. la tabla editable de Diplomados) y no el membership_id que
    exige /{team_id}/members/{membership_id} — resuelve el UPN a su ID de
    Azure AD y quita la membresía a nivel de grupo M365 (no deshabilita ni
    borra la cuenta, solo la saca de ese equipo puntual)."""
    try:
        user = await graph.get(f"/users/{body.upn}", params={"$select": "id"})
        user_id = user.get("id") if user else None
        if not user_id:
            raise HTTPException(status_code=404, detail=f"No se encontró un usuario en Azure AD con UPN '{body.upn}'.")
        removed = await remove_member_from_group(body.group_id, user_id)
        return {"removed": removed}
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))


@router.post("/{team_id}/members/bulk-remove", summary="Quitar miembros de forma conjunta")
async def bulk_remove_members(team_id: str, body: BulkTeamsMemberRemove) -> BulkResult:
    result = BulkResult()

    # Retrieve current members to map user_id → membership_id
    try:
        members = await graph.paginate(f"/teams/{team_id}/members")
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))

    id_to_membership = {m.get("userId"): m.get("id") for m in members}

    async def _remove(user_id: str):
        membership_id = id_to_membership.get(user_id)
        if not membership_id:
            result.failed.append({"user_id": user_id, "error": "Member not found in team"})
            return
        try:
            await graph.delete(f"/teams/{team_id}/members/{membership_id}")
            result.succeeded.append({"user_id": user_id, "removed": True})
        except Exception as exc:
            result.failed.append({"user_id": user_id, "error": str(exc)})

    await asyncio.gather(*[_remove(uid) for uid in body.user_ids])
    return result


# ── Channels ─────────────────────────────────────────────────────────────────

@router.get("/{team_id}/channels", summary="Listar canales del Team")
async def list_channels(team_id: str):
    return await graph.paginate(f"/teams/{team_id}/channels")


@router.post("/{team_id}/channels", status_code=201, summary="Crear canal en el Team")
async def create_channel(team_id: str, body: TeamsChannelCreate):
    payload = {
        "displayName": body.display_name,
        "description": body.description or "",
        "membershipType": body.membership_type,
    }
    try:
        return await graph.post(f"/teams/{team_id}/channels", payload)
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))

