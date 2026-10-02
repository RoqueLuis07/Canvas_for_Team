import re
from typing import Literal
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel
import logging
from app.services import canvas_client as canvas
from app.services import teams_client as graph
from app.core.config import settings

router = APIRouter(prefix="/egreso", tags=["Desvinculación"])
logger = logging.getLogger(__name__)
_ACCOUNT = settings.canvas_account_id


class DeleteAccountRequest(BaseModel):
    identifier: str
    """Correo institucional O SIS user ID (cédula) — se busca por cualquiera
    de los dos, no hace falta saber cuál es."""
    platform: Literal["canvas", "teams", "both"] = "both"


@router.post("/delete-account", summary="Eliminar/suspender una cuenta por correo o SIS, en Canvas y/o Teams")
async def delete_account(body: DeleteAccountRequest):
    """Apartado de baja de un solo paso: se le da un correo institucional O
    un SIS user ID (cédula), y la plataforma (Canvas, Teams, o ambas), sin
    necesidad de saber de antemano en cuál de las dos existe la cuenta.

    Comportamiento por plataforma (igual al de /egreso/suspend):
    - Canvas: elimina la cuenta de la subcuenta (DELETE real).
    - Teams: deshabilita la cuenta en Azure AD y le quita las licencias
      asignadas (reversible — no borra el usuario de Entra ID).
    """
    identifier = body.identifier.strip()
    if not identifier:
        raise HTTPException(status_code=400, detail="Se requiere un correo o SIS user ID")

    res = {"identifier": identifier, "platform": body.platform, "canvas": "skipped", "teams": "skipped"}
    resolved_email = None

    if body.platform in ("canvas", "both"):
        try:
            match = await canvas.find_user_exact(_ACCOUNT, identifier)
            if match:
                await canvas.delete(f"/accounts/{_ACCOUNT}/users/{match['id']}")
                res["canvas"] = "deleted"
                resolved_email = match.get("email") or match.get("login_id")
            else:
                res["canvas"] = "not_found"
        except Exception as e:
            logger.error(f"Error eliminando en Canvas: {e}")
            res["canvas"] = f"error: {e}"

    if body.platform in ("teams", "both"):
        target_email = resolved_email or (identifier if "@" in identifier else None)
        if not target_email:
            res["teams"] = "skipped (sin correo — usá el correo institucional o buscá primero por Canvas)"
        else:
            try:
                t_user = await graph.get_user_by_upn_exact(target_email, select="id")
                if t_user and t_user.get("id"):
                    t_user_id = t_user["id"]
                    await graph.patch(f"/users/{t_user_id}", {"accountEnabled": False})
                    res["teams"] = "suspended"
                    try:
                        removed = await graph.remove_all_licenses(t_user_id)
                        res["license"] = f"removed ({len(removed)})" if removed else "none"
                    except Exception as le:
                        res["license"] = f"error: {le}"
                else:
                    res["teams"] = "not_found"
            except Exception as te:
                logger.error(f"Error eliminando en Teams: {te}")
                res["teams"] = f"error: {te}"

    return res

class FindDuplicatesRequest(BaseModel):
    cedulas: list[str]


@router.post("/find-duplicates", summary="Diagnóstico: cuentas duplicadas en Teams/Azure AD por cédula")
async def find_duplicates(body: FindDuplicatesRequest):
    """Para una lista de cédulas (columna CI de la planilla, por ejemplo),
    busca cuántas cuentas de Azure AD/Teams tienen esa cédula guardada en
    `postalCode` (campo que el alta de credenciales usa para guardarla) —
    si hay más de una, es una cuenta duplicada real de la misma persona.

    También se informa la cuenta de Canvas de esa cédula como referencia,
    aunque ahí un duplicado real es prácticamente imposible: Canvas
    rechaza crear un segundo usuario con el mismo SIS user ID.

    Esto NO borra ni modifica nada — es solo para armar la lista de qué
    cuentas de Teams revisar/eliminar a mano con /egreso/delete-account.
    """
    results = []
    for raw in body.cedulas:
        cedula = re.sub(r"[.\-\s]", "", (raw or "").strip())
        if not cedula:
            continue

        canvas_match = None
        try:
            c_user = await canvas.get(f"/accounts/{_ACCOUNT}/users/sis_user_id:{cedula}")
            if c_user:
                canvas_match = {"id": c_user.get("id"), "email": c_user.get("email") or c_user.get("login_id")}
        except Exception:
            pass

        teams_matches = []
        try:
            # postalCode no está indexado para $filter por defecto en Graph;
            # si la tenant no lo permite, cae a una búsqueda $search más
            # amplia por el propio texto de la cédula.
            res = await graph.get(
                "/users",
                params={
                    "$filter": f"postalCode eq '{cedula}'",
                    "$select": "id,displayName,userPrincipalName,createdDateTime,accountEnabled",
                },
            )
            teams_matches = res.get("value", []) if res else []
        except Exception as e:
            results.append({"cedula": cedula, "canvas": canvas_match, "teams": [], "error": str(e)})
            continue

        results.append({
            "cedula": cedula,
            "canvas": canvas_match,
            "teams": teams_matches,
            "duplicate": len(teams_matches) > 1,
        })

    return {
        "total": len(results),
        "duplicates_found": sum(1 for r in results if r.get("duplicate")),
        "results": results,
    }


@router.post("/suspend", summary="Suspender cuenta de Canvas y MS Teams")
async def suspend_user(sys_user_id: str, email: str = None):
    """
    Suspende a un usuario en Canvas y MS Teams.
    """
    sys_user_id = sys_user_id.strip()
    if not sys_user_id:
        raise HTTPException(status_code=400, detail="Se requiere el ID del usuario")
    
    res = {"sys_user_id": sys_user_id, "canvas": "skipped", "teams": "skipped"}
    
    canvas_email = None
    
    # 1. Canvas: Buscar y Suspender o Eliminar (Soft Delete)
    try:
        c_user = await canvas.get(f"/accounts/{_ACCOUNT}/users/sis_user_id:{sys_user_id}")
        user_id = c_user.get("id")
        if user_id:
            await canvas.delete(f"/accounts/{_ACCOUNT}/users/{user_id}")
            res["canvas"] = "suspended"
            canvas_email = c_user.get("email")
    except Exception as e:
        if "404" in str(e):
            res["canvas"] = "not_found"
        else:
            logger.error(f"Error suspendiendo en Canvas: {e}")
            res["canvas"] = f"error: {e}"
            
    # 2. Teams: Buscar por el email recuperado de Canvas, o por el provisto directamente
    target_email = canvas_email or (email.strip() if email else None)
    
    if target_email:
        try:
            # En Teams (Graph), buscamos por UPN
            teams_users = await graph.get("/users", params={"$filter": f"userPrincipalName eq '{target_email}'"})
            if teams_users and isinstance(teams_users.get("value"), list) and len(teams_users["value"]) > 0:
                t_user_id = teams_users["value"][0]["id"]
                # Deshabilitar cuenta en Azure AD
                await graph.patch(f"/users/{t_user_id}", {"accountEnabled": False})
                res["teams"] = "suspended"
                # Liberar licencias: una cuenta deshabilitada no debe seguir
                # ocupando un asiento pago.
                try:
                    removed = await graph.remove_all_licenses(t_user_id)
                    res["license"] = f"removed ({len(removed)})" if removed else "none"
                except Exception as le:
                    res["license"] = f"error: {le}"
            else:
                res["teams"] = "not_found"
        except Exception as te:
            logger.error(f"Error suspendiendo en Teams: {te}")
            res["teams"] = f"error: {te}"
    else:
        res["teams"] = "skipped (no_email)"
            
    return res
