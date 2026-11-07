"""Audit log endpoints."""
import logging
from fastapi import APIRouter, HTTPException, Query
from typing import Annotated

from app.core import audit_log

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/audit", tags=["Audit Logs"])


def _map_action(method: str, endpoint: str) -> str:
    """Traduce método HTTP + endpoint a una acción legible (create/delete/...).
    Compartida entre /audit/recent (actividad del dashboard) y /audit/logs
    (Registro de Auditoría), para que ambas vistas usen la misma categoría
    de acción por fila en vez de que cada una la recalcule por su cuenta."""
    ep = (endpoint or "").lower()
    if "/auth/" in ep:           return "login"
    if "/excel/" in ep:          return "import_bulk"
    if method == "POST":         return "create"
    if method in ("PUT", "PATCH"): return "update"
    if method == "DELETE":       return "delete"
    return "update"


@router.get("/recent", summary="Actividad reciente para el dashboard")
async def get_recent_activity(
    limit: Annotated[int, Query(ge=1, le=200)] = 30,
):
    """Returns recent audit entries in the format expected by the dashboard."""
    import time as _time
    from datetime import datetime

    def _to_ts(timestamp_str: str) -> int:
        try:
            dt = datetime.fromisoformat(timestamp_str.replace("Z", "+00:00"))
            return int(dt.timestamp())
        except Exception:
            return int(_time.time())

    try:
        result = await audit_log.get_audit_logs(limit=limit, offset=0)
        entries = [
            {
                "action":   _map_action(log.get("method", ""), log.get("endpoint", "")),
                "resource": log.get("endpoint", "—"),
                "status":   "ok" if 200 <= (log.get("status_code") or 200) < 400 else "error",
                "detail":   log.get("details") or "",
                "user":     log.get("username") or "sistema",
                "ts":       _to_ts(log.get("timestamp", "")),
            }
            for log in result.get("logs", [])
        ]
        return {"entries": entries, "total": result.get("total", 0)}
    except Exception as exc:
        logger.error(f"Error getting recent activity: {exc}")
        raise HTTPException(status_code=500, detail="Error retrieving recent activity")


@router.get("/logs", summary="Obtener logs de auditoría")
async def get_audit_logs(
    limit: Annotated[int, Query(ge=1, le=5000)] = 50,
    offset: Annotated[int, Query(ge=0)] = 0,
    username: Annotated[str | None, Query()] = None,
    endpoint: Annotated[str | None, Query()] = None,
    date_from: Annotated[str | None, Query(description="Fecha mínima, formato YYYY-MM-DD")] = None,
    date_to: Annotated[str | None, Query(description="Fecha máxima, formato YYYY-MM-DD")] = None,
):
    """Get audit logs with pagination, optionally filtered by username/endpoint/fecha.

    Cada fila trae además `action` (ver _map_action) — la misma categoría
    legible que ya usa /audit/recent para el dashboard, así el Registro de
    Auditoría puede mostrar "Eliminar" en vez de solo "DELETE /canvas/..."."""
    try:
        result = await audit_log.get_audit_logs(
            limit=limit, offset=offset, username=username, endpoint=endpoint,
            date_from=date_from, date_to=date_to,
        )
        for log in result.get("logs", []):
            log["action"] = _map_action(log.get("method", ""), log.get("endpoint", ""))
        return result
    except Exception as exc:
        logger.error(f"Error retrieving audit logs: {exc}")
        raise HTTPException(status_code=500, detail="Error retrieving audit logs")


@router.get("/logs/user/{username}", summary="Obtener logs de un usuario específico")
async def get_user_audit_logs(
    username: str,
    limit: Annotated[int, Query(ge=1, le=500)] = 50,
    offset: Annotated[int, Query(ge=0)] = 0,
):
    """Get audit logs for a specific user."""
    try:
        # Get all logs and filter by username
        result = await audit_log.get_audit_logs(limit=limit + offset, offset=0)

        user_logs = [log for log in result["logs"] if log["username"] == username]

        return {
            "logs": user_logs[offset:offset + limit],
            "total": len(user_logs),
            "limit": limit,
            "offset": offset,
            "username": username
        }
    except Exception as exc:
        logger.error(f"Error retrieving user audit logs: {exc}")
        raise HTTPException(status_code=500, detail="Error retrieving audit logs")


@router.delete("/logs/cleanup", summary="Limpiar logs antiguos (admin)")
async def cleanup_old_logs(days: Annotated[int, Query(ge=1)] = 90):
    """Delete audit logs older than N days."""
    try:
        deleted = await audit_log.clear_old_logs(days=days)
        return {
            "deleted": deleted,
            "days": days,
            "message": f"Deleted {deleted} audit logs older than {days} days"
        }
    except Exception as exc:
        logger.error(f"Error cleaning up audit logs: {exc}")
        raise HTTPException(status_code=500, detail="Error cleaning up audit logs")


@router.get("/stats", summary="Estadísticas de actividad")
async def get_audit_stats():
    """Get activity statistics."""
    try:
        result = await audit_log.get_audit_logs(limit=10000, offset=0)
        logs = result["logs"]

        # Count by method
        method_counts = {}
        user_counts = {}
        endpoint_counts = {}

        for log in logs:
            method = log.get("method", "Unknown")
            username = log.get("username", "Unknown")
            endpoint = log.get("endpoint", "Unknown")

            method_counts[method] = method_counts.get(method, 0) + 1
            user_counts[username] = user_counts.get(username, 0) + 1
            endpoint_counts[endpoint] = endpoint_counts.get(endpoint, 0) + 1

        return {
            "total_requests": result["total"],
            "by_method": method_counts,
            "by_user": user_counts,
            "by_endpoint": endpoint_counts,
            "unique_users": len(user_counts),
            "unique_endpoints": len(endpoint_counts)
        }
    except Exception as exc:
        logger.error(f"Error getting audit stats: {exc}")
        raise HTTPException(status_code=500, detail="Error getting audit statistics")
