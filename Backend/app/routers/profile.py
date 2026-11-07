import asyncio
from typing import Annotated, Any

from fastapi import APIRouter, Depends, HTTPException, Query, Request
from pydantic import BaseModel

from app.core.config import settings
from app.services import auth as auth_service
from app.services import canvas_client as canvas
from app.services import teams_client as graph

router = APIRouter(prefix="/profile", tags=["Profile"])
_ACCOUNT = settings.canvas_account_id


def get_current_user(request: Request) -> dict:
    user = auth_service.get_user_from_request(request)
    if not user:
        raise HTTPException(status_code=401, detail="No estás autenticado")
    return user


async def _find_canvas_user(identifier: str) -> tuple[dict | None, bool]:
    """Devuelve (usuario, es_match_exacto). El segundo valor indica si el
    resultado vino de un lookup exacto (sis_user_id/login_id/email) o del
    último recurso — el primer resultado de una búsqueda de texto libre sin
    ninguna coincidencia exacta — para que el caller decida si mostrarlo
    igual (con aviso) o tratarlo como "no encontrado", según el contexto."""
    if not identifier:
        return None, False

    identifier = identifier.strip()

    # Si es numérico (C.I.), busca al usuario usando sis_user_id:{cedula}
    if identifier.replace("-", "").replace(".", "").isdigit():
        try:
            return await canvas.get(f"/users/sis_user_id:{identifier}"), True
        except Exception:
            pass

    # Si contiene @ o es texto, intentamos buscar por sis_user_id:{email} primero
    if "@" in identifier:
        try:
            return await canvas.get(f"/users/sis_user_id:{identifier}"), True
        except Exception:
            pass

    try:
        users = await canvas.paginate(f"/accounts/{_ACCOUNT}/users", {"search_term": identifier, "per_page": 100})
    except Exception:
        return None, False

    local = identifier.split("@")[0] if "@" in identifier else identifier
    # Priority: exact match on login_id or email fields first
    for user in users:
        if (user.get("login_id") == identifier
                or user.get("email") == identifier
                or user.get("sis_user_id") == identifier
                or user.get("login_id") == local):
            return user, True
    return (users[0], False) if users else (None, False)


async def _canvas_enrollments(uid: str) -> tuple[list, list]:
    """Return (enriched_enrollments, groups). Groups may be empty if Canvas blocks the endpoint."""
    # All enrollment types — students, teachers, TAs, observers
    try:
        raw = await canvas.paginate(
            f"/users/{uid}/enrollments", {"per_page": 100}
        )
    except Exception:
        return [], []

    # Fetch course details in parallel
    course_ids = list({str(e.get("course_id")) for e in raw if e.get("course_id")})

    async def _get_course(cid: str) -> tuple[str, dict]:
        try:
            return cid, await canvas.get(f"/courses/{cid}")
        except Exception:
            return cid, {}

    course_results = await asyncio.gather(*[_get_course(cid) for cid in course_ids])
    courses = dict(course_results)

    enriched = []
    for e in raw:
        course = courses.get(str(e.get("course_id")), {})
        enriched.append({
            "id":              e.get("id"),
            "course_id":       e.get("course_id"),
            "course_name":     course.get("name") or e.get("course_name") or "Sin nombre",
            "course_code":     course.get("course_code") or e.get("course_code"),
            "role":            e.get("type"),
            "enrollment_state": e.get("enrollment_state"),
            "program":         _extract_program(course),
            "year":            _extract_year(course),
            "start_at":        course.get("start_at"),
            "end_at":          course.get("end_at"),
        })

    # Groups: Canvas requires user impersonation for /users/{id}/groups with admin token.
    # Try the endpoint; return empty list if it fails.
    groups: list = []
    try:
        groups = await canvas.paginate(f"/users/{uid}/groups", {"per_page": 100})
    except Exception:
        pass

    return enriched, groups


def _extract_program(course: dict) -> str:
    code = course.get("course_code") or ""
    if "-" in code:
        return code.split("-")[0]
    name = course.get("name", "")
    return name.split()[0] if name else "Sin programa"


def _extract_year(course: dict) -> str:
    start_at = course.get("start_at")
    if start_at and len(start_at) >= 4:
        return start_at[:4]
    return "Sin año"


@router.get("/me", summary="Obtener datos del usuario autenticado")
async def me(current_user: dict = Depends(get_current_user)):
    return current_user


@router.get("/canvas", summary="Obtener perfil Canvas del usuario")
async def canvas_profile(
    program: Annotated[str | None, Query()] = None,
    year: Annotated[str | None, Query()] = None,
    current_user: dict = Depends(get_current_user),
):
    email = current_user["email"]
    # "/profile/canvas" muestra las notas/inscripciones del usuario
    # AUTENTICADO — acá NUNCA se acepta un match no-exacto (mostraría las
    # calificaciones de otra persona), a diferencia del buscador admin de
    # abajo donde un humano revisa el resultado.
    canvas_user, is_exact = await _find_canvas_user(email)
    if not canvas_user or not is_exact:
        raise HTTPException(status_code=404, detail="Usuario Canvas no encontrado")

    user_id = str(canvas_user.get("id"))
    enrollments, groups = await _canvas_enrollments(user_id)

    if program or year:
        enrollments = [
            e for e in enrollments
            if (not program or program.lower() in str(e["program"]).lower())
            and (not year or year == e["year"])
        ]

    return {
        "canvas_user": {"id": user_id, "name": canvas_user.get("name"), "email": canvas_user.get("email")},
        "enrollments": enrollments,
        "groups": groups,
    }


_GRAPH_USER_SELECT = (
    "id,displayName,mail,userPrincipalName,department,jobTitle,"
    "accountEnabled,officeLocation,mobilePhone,businessPhones,usageLocation"
)


async def _find_graph_user(email: str) -> dict | None:
    """Try multiple identifier formats to find a user in Azure AD."""
    for identifier in [email, email.split("@")[0]]:
        try:
            return await graph.get(f"/users/{identifier}", {"$select": _GRAPH_USER_SELECT})
        except Exception:
            pass
    try:
        result = await graph.get("/users", {
            "$filter": f"mail eq '{email}' or userPrincipalName eq '{email}'",
            "$select": _GRAPH_USER_SELECT,
            "$top": 1,
        })
        users = result.get("value", []) if isinstance(result, dict) else []
        return users[0] if users else None
    except Exception:
        return None


async def _graph_user_to_dict(az_user: dict) -> dict:
    phones = az_user.get("businessPhones") or []
    return {
        "id":                 az_user.get("id"),
        "displayName":        az_user.get("displayName"),
        "mail":               az_user.get("mail"),
        "userPrincipalName":  az_user.get("userPrincipalName"),
        "department":         az_user.get("department"),
        "jobTitle":           az_user.get("jobTitle"),
        "officeLocation":     az_user.get("officeLocation"),
        "mobilePhone":        az_user.get("mobilePhone"),
        "phone":              phones[0] if phones else None,
        "usageLocation":      az_user.get("usageLocation"),
        "accountEnabled":     az_user.get("accountEnabled"),
    }


async def _teams_with_channels(user_id: str) -> list[dict]:
    """Return joined teams, each with a (possibly empty) channels list."""
    try:
        raw_teams = await graph.paginate(f"/users/{user_id}/joinedTeams")
    except Exception:
        return []

    async def _get_channels(team: dict) -> dict:
        try:
            channels = await graph.paginate(f"/teams/{team['id']}/channels")
            ch = [{"id": c.get("id"), "displayName": c.get("displayName"),
                   "membershipType": c.get("membershipType", "standard")} for c in channels]
        except Exception:
            ch = []
        return {
            "id":          team.get("id"),
            "displayName": team.get("displayName"),
            "description": team.get("description"),
            "visibility":  team.get("visibility"),
            "channels":    ch,
        }

    return list(await asyncio.gather(*[_get_channels(t) for t in raw_teams]))


@router.get("/teams", summary="Obtener perfil Teams del usuario")
async def teams_profile(current_user: dict = Depends(get_current_user)):
    email = current_user["email"]
    az_user = await _find_graph_user(email)
    if not az_user:
        raise HTTPException(status_code=404, detail=f"Usuario '{email}' no encontrado en Azure AD")

    teams = await _teams_with_channels(az_user["id"])

    return {
        "teams_user": await _graph_user_to_dict(az_user),
        "teams":      teams,
    }


@router.get("/lookup", summary="Ver perfil completo de cualquier usuario")
async def lookup_user_profile(
    email: Annotated[str | None, Query(description="Email, login o C.I. del usuario")] = None,
    canvas_id: Annotated[str | None, Query(description="ID Canvas del usuario")] = None,
):
    if not email and not canvas_id:
        raise HTTPException(status_code=400, detail="Especificá email (o C.I.) o canvas_id")

    result: dict[str, Any] = {}
    identifier = email

    # ── Canvas ────────────────────────────────────────────────────────────────
    canvas_user = None
    canvas_is_exact = True
    if canvas_id:
        try:
            canvas_user = await canvas.get(f"/users/{canvas_id}/profile")
        except Exception:
            pass
    if canvas_user is None and identifier:
        canvas_user, canvas_is_exact = await _find_canvas_user(identifier)

    if canvas_user:
        uid = str(canvas_user.get("id"))
        enrollments, groups = await _canvas_enrollments(uid)
        # Derive email for Teams lookup — prefer any field that looks like an email
        if not identifier or "@" not in identifier:
            for _field in ("primary_email", "email", "login_id", "sis_user_id"):
                _val = canvas_user.get(_field) or ""
                if "@" in _val:
                    identifier = _val
                    break
        result["canvas"] = {
            "user": {
                "id": uid,
                "name": canvas_user.get("name"),
                "email": canvas_user.get("primary_email") or canvas_user.get("email"),
                "login_id": canvas_user.get("login_id"),
                "sis_user_id": canvas_user.get("sis_user_id"),
                "avatar_url": canvas_user.get("avatar_url"),
            },
            # No hubo coincidencia EXACTA por sis_user_id/login_id/email —
            # esto es el resultado más parecido de una búsqueda de texto
            # libre, puede ser una persona distinta. El frontend debe
            # mostrar esto de forma visible antes de que se actúe sobre
            # este usuario.
            "fuzzy_match": not canvas_is_exact,
            "enrollments": enrollments,
            "groups": [{"id": g.get("id"), "name": g.get("name"),
                        "course_id": g.get("course_id")} for g in groups],
        }
    else:
        result["canvas"] = None

    # ── Teams ─────────────────────────────────────────────────────────────────
    teams_email = identifier if identifier and "@" in identifier else None
    if teams_email:
        az_user = await _find_graph_user(teams_email)
        if az_user:
            teams_list = await _teams_with_channels(az_user["id"])
            result["teams"] = {
                "user": await _graph_user_to_dict(az_user),
                "teams": teams_list,
            }
        else:
            result["teams"] = None
    else:
        result["teams"] = None

    if not result.get("canvas") and not result.get("teams"):
        raise HTTPException(status_code=404, detail=f"No se encontraron registros para '{identifier}' ni en Canvas ni en Teams.")

    return result


class ResetPasswordPayload(BaseModel):
    password: str


@router.post("/teams/reset-password", summary="Restablecer contraseña de Teams")
async def reset_password(body: ResetPasswordPayload, current_user: dict = Depends(get_current_user)):
    if len(body.password) < 8:
        raise HTTPException(status_code=400, detail="La contraseña debe tener al menos 8 caracteres.")

    email = current_user["email"]
    try:
        user = await graph.get(f"/users/{email}")
    except HTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=502, detail=f"No se pudo consultar el usuario en Azure AD: {exc}")

    if not user or not user.get("id"):
        raise HTTPException(status_code=404, detail=f"No se encontró el usuario '{email}' en Azure AD.")

    payload = {
        "passwordProfile": {
            "forceChangePasswordNextSignIn": False,
            "password": body.password,
        }
    }
    return await graph.patch(f"/users/{user['id']}", payload)


class DraftEmailRequest(BaseModel):
    to_email: str
    subject: str
    html_body: str
    cc: list[str] | None = None


@router.post("/mail/draft", summary="Crear un borrador en el Outlook del admin logueado (sin enviarlo)")
async def create_my_draft(body: DraftEmailRequest, current_user: dict = Depends(get_current_user)):
    """Crea el correo como borrador en la bandeja de Borradores del admin
    que está logueado ahora mismo (current_user["email"]) — nunca lo envía,
    queda ahí para que lo revise y lo mande él mismo. Usa las mismas
    credenciales de aplicación (Mail.Send + Mail.ReadWrite con
    consentimiento de administrador) que ya usa el resto del sistema para
    enviar correos de credenciales."""
    try:
        draft = await graph.create_draft_email(
            mailbox=current_user["email"], subject=body.subject,
            html_body=body.html_body, to_email=body.to_email, cc=body.cc,
        )
    except HTTPException as exc:
        if exc.status_code == 403:
            raise HTTPException(
                status_code=403,
                detail=(
                    "Falta el permiso de aplicación 'Mail.ReadWrite' (con consentimiento de administrador) "
                    "en Azure Portal → App Registrations → API Permissions. Detalle original: " + str(exc.detail)
                ),
            )
        raise
    except Exception as exc:
        raise HTTPException(status_code=500, detail=f"No se pudo crear el borrador: {exc}")
    return {"status": "success", "id": draft.get("id"), "web_link": draft.get("webLink")}
