"""Audit logging system to track all web service activities."""
import asyncio
import logging
import json
from datetime import datetime
from pathlib import Path
import psycopg2
import psycopg2.extras
from app.core.config import settings

logger = logging.getLogger(__name__)


_CONNECT_TIMEOUT = 5
_STATEMENT_TIMEOUT_MS = 8000
_RUN_TIMEOUT = 10

# Conexión persistente y reusada (en vez de abrir una psycopg2.connect()
# nueva en cada llamada) — log_activity() corre en el middleware de CADA
# request de la app, así que abrir una conexión nueva por request agotaba
# en minutos el límite de conexiones concurrentes del pooler de Supabase en
# modo sesión (FATAL: max clients reached in session mode, pool_size: 15),
# causando 503 en cascada en requests sin relación alguna con auditoría
# (por ejemplo, reportes largos que corren mientras otras pestañas siguen
# generando tráfico de fondo). Mismo patrón que ya usa app.core.database.
_conn = None
_db_lock = asyncio.Lock()


def _get_conn():
    global _conn
    if _conn is None or _conn.closed:
        _conn = psycopg2.connect(
            settings.supabase_database_url,
            connect_timeout=_CONNECT_TIMEOUT,
            options=f"-c statement_timeout={_STATEMENT_TIMEOUT_MS}",
        )
    return _conn


async def close_audit_db() -> None:
    global _conn
    if _conn is not None and not _conn.closed:
        _conn.close()
        _conn = None


async def _run(fn):
    """Ejecuta `fn(conn)` sobre la conexión persistente, en un hilo aparte
    (para no bloquear el event loop) y serializado por `_db_lock`. Si `fn`
    lanza una excepción, hace rollback antes de propagarla — sin esto, la
    conexión global queda en estado "transacción abortada" tras cualquier
    error y rompe todas las llamadas siguientes. Si la operación entera no
    vuelve a tiempo, se descarta la conexión para que la próxima llamada
    abra una nueva en vez de quedar todo el middleware de auditoría
    colgado indefinidamente."""
    def _wrapped():
        conn = _get_conn()
        try:
            return fn(conn)
        except Exception:
            try:
                conn.rollback()
            except Exception:
                global _conn
                _conn = None
            raise

    async with _db_lock:
        try:
            return await asyncio.wait_for(asyncio.to_thread(_wrapped), timeout=_RUN_TIMEOUT)
        except asyncio.TimeoutError:
            global _conn
            logger.error(f"Audit DB operation timed out after {_RUN_TIMEOUT}s, discarding connection")
            _conn = None
            raise


def init_audit_db():
    """Initialize audit database."""
    conn = psycopg2.connect(settings.supabase_database_url)
    try:
        cursor = conn.cursor()

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS audit_logs (
                id SERIAL PRIMARY KEY,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                username TEXT,
                endpoint TEXT,
                method TEXT,
                status_code INTEGER,
                ip_address TEXT,
                user_agent TEXT,
                details TEXT
            )
        """)

        # get_audit_logs() ordena por timestamp DESC y filtra por username/endpoint
        # en cada carga de /ui/audit, y la tabla crece con cada request de la app
        # (vía el middleware de auditoría) — sin estos índices, esas consultas
        # escanean la tabla completa cada vez.
        cursor.execute("CREATE INDEX IF NOT EXISTS idx_audit_timestamp ON audit_logs(timestamp DESC)")
        cursor.execute("CREATE INDEX IF NOT EXISTS idx_audit_username ON audit_logs(username)")
        cursor.execute("CREATE INDEX IF NOT EXISTS idx_audit_endpoint ON audit_logs(endpoint)")

        conn.commit()
        logger.info("Audit database initialized")
    finally:
        conn.close()


async def log_activity(
    username: str,
    endpoint: str,
    method: str,
    status_code: int,
    ip_address: str,
    user_agent: str,
    details: str = None
):
    """Log an activity to the audit database."""
    try:
        def _log(conn):
            cursor = conn.cursor()
            cursor.execute("""
                INSERT INTO audit_logs (username, endpoint, method, status_code, ip_address, user_agent, details)
                VALUES (%s, %s, %s, %s, %s, %s, %s)
            """, (username, endpoint, method, status_code, ip_address, user_agent, details))
            conn.commit()

        await _run(_log)
        logger.debug(f"Audit log: {username} {method} {endpoint} -> {status_code}")

    except Exception as e:
        logger.error(f"Error logging activity: {e}")


async def get_audit_logs(
    limit: int = 100, offset: int = 0, username: str = None, endpoint: str = None,
    date_from: str = None, date_to: str = None,
):
    """Get audit logs from database, optionally filtered by username/endpoint (partial match) y rango de fechas (YYYY-MM-DD, inclusive)."""
    try:
        def _get(conn):
            cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)

            where_clauses = []
            params = []
            if username:
                where_clauses.append("username ILIKE %s")
                params.append(f"%{username}%")
            if endpoint:
                where_clauses.append("endpoint ILIKE %s")
                params.append(f"%{endpoint}%")
            if date_from:
                where_clauses.append("timestamp >= %s")
                params.append(f"{date_from} 00:00:00")
            if date_to:
                where_clauses.append("timestamp <= %s")
                params.append(f"{date_to} 23:59:59")
            where_sql = f"WHERE {' AND '.join(where_clauses)}" if where_clauses else ""

            cursor.execute(f"SELECT COUNT(*) FROM audit_logs {where_sql}", params)
            total = cursor.fetchone()[0]

            cursor.execute(f"""
                SELECT * FROM audit_logs
                {where_sql}
                ORDER BY timestamp DESC
                LIMIT %s OFFSET %s
            """, params + [limit, offset])

            logs = []
            for row in cursor.fetchall():
                logs.append({
                    "id": row["id"],
                    "timestamp": row["timestamp"],
                    "username": row["username"],
                    "endpoint": row["endpoint"],
                    "method": row["method"],
                    "status_code": row["status_code"],
                    "ip_address": row["ip_address"],
                    "user_agent": row["user_agent"],
                    "details": row["details"]
                })

            return {
                "logs": logs,
                "total": total,
                "limit": limit,
                "offset": offset
            }

        return await _run(_get)

    except Exception as e:
        logger.error(f"Error retrieving audit logs: {e}")
        return {"logs": [], "total": 0, "limit": limit, "offset": offset}


async def clear_old_logs(days: int = 90):
    """Clear audit logs older than N days."""
    try:
        def _clear(conn):
            cursor = conn.cursor()
            cursor.execute("""
                DELETE FROM audit_logs
                WHERE timestamp < NOW() - (%s * INTERVAL '1 day')
            """, (days,))
            deleted = cursor.rowcount
            conn.commit()
            return deleted

        deleted = await _run(_clear)
        logger.info(f"Cleared {deleted} old audit logs (older than {days} days)")
        return deleted

    except Exception as e:
        logger.error(f"Error clearing old logs: {e}")
        return 0
