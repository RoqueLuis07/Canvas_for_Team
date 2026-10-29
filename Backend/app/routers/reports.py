"""Reportes de administración: licencias huérfanas, cuentas inactivas de Teams
y verificación de envío de correo contra el buzón real de Outlook.

Algunos de estos reportes requieren permisos de Azure AD que todavía NO están
concedidos a la app registration (ver detalle en cada endpoint) — devuelven un
error claro hasta que se otorgue el permiso y el admin consent en Azure Portal.
"""
import asyncio
import logging
from fastapi import APIRouter, HTTPException, Query
from pydantic import BaseModel
from typing import Annotated

from app.core.config import settings
from app.services import canvas_client as canvas
from app.services import teams_client as graph

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/reports", tags=["Reportes"])
_ACCOUNT = settings.canvas_account_id

# Estados de curso considerados "vigentes" para efectos de matrícula — un
# curso eliminado no cuenta como membresía activa aunque el registro de
# enrollment siga existiendo en Canvas.
_ACTIVE_COURSE_STATES = ["created", "claimed", "available", "completed"]

_SCAN_SEM = asyncio.Semaphore(15)


# ── Licencias huérfanas (cuentas deshabilitadas que siguen con licencia) ────

@router.get("/orphaned-licenses", summary="Cuentas deshabilitadas que todavía tienen licencia asignada")
async def orphaned_licenses():
    users = await graph.list_disabled_users_with_licenses()
    return {
        "total": len(users),
        "users": [
            {
                "id": u.get("id"),
                "displayName": u.get("displayName"),
                "userPrincipalName": u.get("userPrincipalName"),
                "licenses": u.get("licenseNames") or [lic.get("skuId") for lic in (u.get("assignedLicenses") or [])],
            }
            for u in users
        ],
    }


class FreeLicensesIn(BaseModel):
    user_ids: list[str]


@router.post("/orphaned-licenses/free", summary="Liberar licencias de las cuentas seleccionadas")
async def free_orphaned_licenses(body: FreeLicensesIn):
    results = {"succeeded": [], "failed": []}
    for uid in body.user_ids:
        try:
            removed = await graph.remove_all_licenses(uid)
            results["succeeded"].append({"id": uid, "removed": removed})
        except Exception as exc:
            results["failed"].append({"id": uid, "error": str(exc)})
    return results


# ── Cuentas de Teams inactivas (requiere AuditLog.Read.All) ────────────────

@router.get("/inactive-teams-users", summary="Cuentas habilitadas sin inicio de sesión reciente")
async def inactive_teams_users(days: Annotated[int, Query(ge=1, le=730)] = 60):
    try:
        users = await graph.get_inactive_users(min_days_inactive=days)
    except HTTPException as exc:
        if exc.status_code == 403:
            raise HTTPException(
                status_code=403,
                detail=(
                    "Este reporte requiere el permiso de aplicación 'AuditLog.Read.All' "
                    "con consentimiento de administrador en Azure Portal (App Registrations "
                    "→ API Permissions → Add permission → Microsoft Graph → Application "
                    "permissions → AuditLog.Read.All → Grant admin consent). "
                    f"Detalle original: {exc.detail}"
                ),
            )
        raise
    return {
        "total": len(users),
        "days": days,
        "users": [
            {
                "id": u.get("id"),
                "displayName": u.get("displayName"),
                "userPrincipalName": u.get("userPrincipalName"),
                "last_signin": u.get("last_signin"),
            }
            for u in users
        ],
    }


# ── Actividad de Teams por usuario (requiere Reports.Read.All) ─────────────

@router.get("/teams-activity", summary="Reporte de actividad de Teams por usuario")
async def teams_activity(period: str = "D90"):
    if period not in ("D7", "D30", "D90", "D180"):
        raise HTTPException(status_code=400, detail="period debe ser D7, D30, D90 o D180")
    try:
        rows = await graph.get_teams_activity_report(period=period)
    except HTTPException as exc:
        if exc.status_code == 403:
            raise HTTPException(
                status_code=403,
                detail=(
                    "Este reporte requiere el permiso de aplicación 'Reports.Read.All' "
                    "con consentimiento de administrador en Azure Portal. Si ya está "
                    "concedido y sigue fallando, revisar que 'Reports concealment' esté "
                    "desactivado en el Admin Center (Settings → Org settings → Reports) "
                    "para poder ver nombres/UPN reales en vez de datos anonimizados. "
                    f"Detalle original: {exc.detail}"
                ),
            )
        raise
    return {"period": period, "total": len(rows), "rows": rows}


# ── Verificación de envío real (requiere Mail.Read sobre el buzón SMTP) ────

class VerifySentIn(BaseModel):
    to_email: str
    since_iso: str  # ISO 8601 UTC, ej: 2026-07-13T00:00:00Z


@router.post("/verify-email-sent", summary="Confirmar en Outlook (Enviados) si un correo realmente salió")
async def verify_email_sent(body: VerifySentIn):
    try:
        found = await graph.search_sent_email(body.to_email, body.since_iso)
    except HTTPException as exc:
        if exc.status_code == 403:
            raise HTTPException(
                status_code=403,
                detail=(
                    "Esta verificación requiere el permiso de aplicación 'Mail.Read' "
                    "(o 'Mail.ReadBasic.All') con consentimiento de administrador, con "
                    "acceso al buzón configurado en SMTP_FROM. Otorgarlo en Azure Portal "
                    "→ App Registrations → API Permissions → Add permission → Microsoft "
                    "Graph → Application permissions → Mail.Read → Grant admin consent. "
                    f"Detalle original: {exc.detail}"
                ),
            )
        raise
    return {"to_email": body.to_email, "found_in_sent_items": found}


# ── Cuentas sin uso (ni en cursos de Canvas ni en equipos de Teams) ────────

async def _canvas_enrolled_user_ids() -> set[int]:
    """IDs de usuario de Canvas con al menos una matrícula vigente,
    recorriendo todos los cursos de la cuenta."""
    courses = await canvas.paginate(
        f"/accounts/{_ACCOUNT}/courses",
        {"per_page": 100, "state[]": _ACTIVE_COURSE_STATES},
    )
    enrolled: set[int] = set()

    async def _scan(course_id):
        async with _SCAN_SEM:
            try:
                enrollments = await canvas.paginate(
                    f"/courses/{course_id}/enrollments", {"per_page": 100}
                )
                for e in enrollments:
                    uid = e.get("user_id")
                    if uid:
                        enrolled.add(uid)
            except Exception as exc:
                logger.warning(f"No se pudo leer matrículas del curso {course_id}: {exc}")

    await asyncio.gather(*[_scan(c["id"]) for c in courses if c.get("id")])
    return enrolled


async def _teams_member_user_ids() -> set[str]:
    """IDs de Azure AD (objectId) que son miembro de al menos un Team."""
    teams = await graph.paginate("/groups", {
        "$filter": "resourceProvisioningOptions/Any(x:x eq 'Team')",
        "$select": "id",
        "$top": 999,
    })
    members: set[str] = set()

    async def _scan(team_id):
        async with _SCAN_SEM:
            try:
                team_members = await graph.paginate(f"/teams/{team_id}/members")
                for m in team_members:
                    uid = m.get("userId")
                    if uid:
                        members.add(uid)
            except Exception as exc:
                logger.warning(f"No se pudo leer miembros del equipo {team_id}: {exc}")

    await asyncio.gather(*[_scan(t["id"]) for t in teams if t.get("id")])
    return members


async def _azure_users_with_signin() -> tuple[list[dict], bool]:
    """Lista todos los usuarios de Azure AD. Intenta incluir signInActivity
    (requiere el permiso de aplicación 'AuditLog.Read.All', que puede no
    estar concedido); si Graph devuelve 403 por ese campo, reintenta sin él.

    Returns:
        (users, signin_available)
    """
    select_with_signin = "id,displayName,userPrincipalName,mail,accountEnabled,createdDateTime,signInActivity"
    try:
        users = await graph.paginate("/users", {"$select": select_with_signin, "$top": 999})
        return users, True
    except HTTPException as exc:
        if exc.status_code in (403, 400):
            logger.warning(f"signInActivity no disponible (falta AuditLog.Read.All): {exc.detail}")
        else:
            raise
    select_basic = "id,displayName,userPrincipalName,mail,accountEnabled,createdDateTime"
    users = await graph.paginate("/users", {"$select": select_basic, "$top": 999})
    return users, False


@router.get("/unused-accounts", summary="Detectar cuentas sin uso (sin cursos/equipos ni actividad)")
async def unused_accounts():
    """Detecta cuentas institucionales (Canvas y/o Azure AD/Teams) que no
    están agregadas a ningún curso de Canvas ni a ningún equipo de Teams —
    buenas candidatas a depurar por no estar en uso.

    Recorre TODOS los cursos y equipos de la cuenta (no una muestra), por lo
    que puede demorar según el tamaño del tenant.
    """
    try:
        (
            canvas_users,
            canvas_enrolled_ids,
            azure_result,
            team_member_ids,
        ) = await asyncio.gather(
            canvas.paginate(f"/accounts/{_ACCOUNT}/users", {"per_page": 100, "include[]": "last_login"}),
            _canvas_enrolled_user_ids(),
            _azure_users_with_signin(),
            _teams_member_user_ids(),
        )
        azure_users, signin_available = azure_result
    except Exception as e:
        logger.error(f"Error escaneando cuentas sin uso: {e}")
        raise HTTPException(status_code=500, detail=f"Error al escanear cuentas: {e}")

    combined: dict[str, dict] = {}

    for u in canvas_users:
        key = (u.get("login_id") or u.get("email") or "").strip().lower()
        if not key:
            continue
        combined.setdefault(key, {})["canvas"] = {
            "id": u.get("id"),
            "name": u.get("name") or u.get("short_name"),
            "login_id": u.get("login_id"),
            "sis_user_id": u.get("sis_user_id"),
            "last_login": u.get("last_login"),
            "in_course": u.get("id") in canvas_enrolled_ids,
        }

    for u in azure_users:
        key = (u.get("userPrincipalName") or u.get("mail") or "").strip().lower()
        if not key:
            continue
        last_signin = (u.get("signInActivity") or {}).get("lastSignInDateTime")
        combined.setdefault(key, {})["azure"] = {
            "id": u.get("id"),
            "name": u.get("displayName"),
            "upn": u.get("userPrincipalName"),
            "account_enabled": u.get("accountEnabled"),
            "created": u.get("createdDateTime"),
            "last_signin": last_signin,
            "in_team": u.get("id") in team_member_ids,
        }

    unused = []
    for email, entry in combined.items():
        c = entry.get("canvas")
        a = entry.get("azure")
        canvas_unused = (c is None) or (not c["in_course"])
        azure_unused = (a is None) or (not a["in_team"])
        if not (canvas_unused and azure_unused):
            continue

        never_logged_canvas = c is None or not c["last_login"]
        if a is None:
            never_logged_azure = None  # sin cuenta en Teams
        elif not signin_available:
            never_logged_azure = None  # no se pudo verificar (falta permiso)
        else:
            never_logged_azure = not a["last_signin"]

        unused.append({
            "email": email,
            "canvas": c,
            "azure": a,
            "never_logged_canvas": never_logged_canvas,
            "never_logged_azure": never_logged_azure,
        })

    unused.sort(key=lambda x: x["email"])

    return {
        "signin_activity_available": signin_available,
        "total_canvas_users": len(canvas_users),
        "total_azure_users": len(azure_users),
        "total_unused": len(unused),
        "accounts": unused,
    }


@router.get(
    "/single-platform-accounts",
    summary="Diagnóstico: cuentas que solo existen en Canvas o solo en Teams/Azure AD",
)
async def single_platform_accounts():
    """Solo lectura — no crea, modifica ni elimina nada.

    Cruza TODOS los usuarios de Canvas y de Azure AD/Teams por correo
    institucional y cuenta cuántos existen en una sola de las dos
    plataformas (vs. en ambas). Es el chequeo de la precondición que el
    equipo dejó pendiente en BACKLOG.md para "Reconciliación automática
    Canvas ↔ Teams" y "Detección de drift": esas automatizaciones asumen
    hoy que todo el mundo necesita cuenta en ambas plataformas, y antes de
    tocar esa lógica hace falta confirmar si institucionalmente ya existen
    casos de una sola plataforma (y en qué volumen).

    No recorre cursos ni equipos (a diferencia de /unused-accounts), así
    que es más rápido — solo lista de usuarios de cada plataforma.
    """
    try:
        canvas_users, azure_result = await asyncio.gather(
            canvas.paginate(f"/accounts/{_ACCOUNT}/users", {"per_page": 100}),
            _azure_users_with_signin(),
        )
        azure_users, signin_available = azure_result
    except Exception as e:
        logger.error(f"Error cruzando cuentas por plataforma: {e}")
        raise HTTPException(status_code=500, detail=f"Error al cruzar cuentas: {e}")

    combined: dict[str, dict] = {}

    for u in canvas_users:
        key = (u.get("login_id") or u.get("email") or "").strip().lower()
        if not key:
            continue
        combined.setdefault(key, {})["canvas"] = {
            "id": u.get("id"),
            "name": u.get("name") or u.get("short_name"),
            "login_id": u.get("login_id"),
            "sis_user_id": u.get("sis_user_id"),
        }

    for u in azure_users:
        key = (u.get("userPrincipalName") or u.get("mail") or "").strip().lower()
        if not key:
            continue
        combined.setdefault(key, {})["azure"] = {
            "id": u.get("id"),
            "name": u.get("displayName"),
            "upn": u.get("userPrincipalName"),
            "account_enabled": u.get("accountEnabled"),
        }

    only_canvas, only_azure = [], []
    both_count = 0
    for email, entry in combined.items():
        c, a = entry.get("canvas"), entry.get("azure")
        if c and not a:
            only_canvas.append({"email": email, **c})
        elif a and not c:
            only_azure.append({"email": email, **a})
        else:
            both_count += 1

    only_canvas.sort(key=lambda x: x["email"])
    only_azure.sort(key=lambda x: x["email"])

    return {
        "total_canvas_users": len(canvas_users),
        "total_azure_users": len(azure_users),
        "total_both_platforms": both_count,
        "total_only_canvas": len(only_canvas),
        "total_only_azure": len(only_azure),
        # Muestra acotada para no devolver un payload gigante en tenants
        # grandes — los totales de arriba ya responden la pregunta de
        # "¿existe el caso?"; esto es para poder mirar ejemplos concretos.
        "only_canvas_sample": only_canvas[:100],
        "only_azure_sample": only_azure[:100],
    }
