"""Job/Task tracking system for recording all operations."""
import asyncio
import logging
from datetime import datetime
from typing import Optional
import psycopg2
import psycopg2.extras
from app.core.config import settings
from pathlib import Path

logger = logging.getLogger(__name__)


_CONNECT_TIMEOUT = 5
_STATEMENT_TIMEOUT_MS = 8000
_RUN_TIMEOUT = 10


async def _run(fn):
    """Abre una conexión nueva, ejecuta `fn(conn)` en un hilo aparte (para no
    bloquear el event loop con I/O de red síncrono) y garantiza el cierre de
    la conexión incluso si `fn` lanza una excepción a mitad de camino.

    Tiene timeout de conexión y de consulta (del lado del servidor) más un
    timeout general de la operación, para no dejar la pantalla de Historial
    de Trabajos colgada indefinidamente si la DB no responde."""
    def _wrapped():
        conn = psycopg2.connect(
            settings.supabase_database_url,
            connect_timeout=_CONNECT_TIMEOUT,
            options=f"-c statement_timeout={_STATEMENT_TIMEOUT_MS}",
        )
        try:
            return fn(conn)
        finally:
            conn.close()
    try:
        return await asyncio.wait_for(asyncio.to_thread(_wrapped), timeout=_RUN_TIMEOUT)
    except asyncio.TimeoutError:
        logger.error(f"Jobs DB operation timed out after {_RUN_TIMEOUT}s")
        raise


def init_jobs_db():
    """Initialize jobs database."""
    conn = psycopg2.connect(settings.supabase_database_url)
    try:
        cursor = conn.cursor()

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS jobs (
                id SERIAL PRIMARY KEY,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                started_at TIMESTAMP,
                completed_at TIMESTAMP,
                job_type TEXT NOT NULL,
                operation TEXT NOT NULL,
                username TEXT,
                status TEXT DEFAULT 'pending',
                result_count INTEGER,
                error_count INTEGER,
                error_message TEXT,
                details TEXT,
                data_json TEXT
            )
        """)

        cursor.execute("""
            CREATE INDEX IF NOT EXISTS idx_created_at ON jobs(created_at DESC)
        """)

        cursor.execute("""
            CREATE INDEX IF NOT EXISTS idx_username ON jobs(username)
        """)

        cursor.execute("""
            CREATE INDEX IF NOT EXISTS idx_job_type ON jobs(job_type)
        """)

        cursor.execute("""
            CREATE INDEX IF NOT EXISTS idx_status ON jobs(status)
        """)

        # Limpiar trabajos colgados (zombies) de una ejecución anterior
        cursor.execute("""
            UPDATE jobs
            SET status = 'failed',
                completed_at = CURRENT_TIMESTAMP,
                error_message = 'El servidor se reinició inesperadamente durante la ejecución.'
            WHERE status IN ('pending', 'processing')
        """)

        conn.commit()
        logger.info("Jobs database initialized")
    finally:
        conn.close()


async def create_job(
    job_type: str,
    operation: str,
    username: str,
    details: str = None,
    data_json: str = None
) -> int:
    """Create a new job record.

    Returns the job ID.
    """
    try:
        def _create(conn):
            cursor = conn.cursor()
            cursor.execute("""
                INSERT INTO jobs (job_type, operation, username, details, data_json, status)
                VALUES (%s, %s, %s, %s, %s, 'pending')
                RETURNING id
            """, (job_type, operation, username, details, data_json))
            job_id = cursor.fetchone()[0]
            conn.commit()
            return job_id

        job_id = await _run(_create)
        logger.info(f"Job created: ID={job_id}, type={job_type}, operation={operation}")
        return job_id

    except Exception as e:
        logger.error(f"Error creating job: {e}")
        return None


async def start_job(job_id: int):
    """Mark a job as started."""
    try:
        def _start(conn):
            cursor = conn.cursor()
            cursor.execute("""
                UPDATE jobs
                SET status = 'processing', started_at = CURRENT_TIMESTAMP
                WHERE id = %s
            """, (job_id,))
            conn.commit()

        await _run(_start)
        logger.debug(f"Job {job_id} started")

    except Exception as e:
        logger.error(f"Error starting job: {e}")


async def complete_job(
    job_id: int,
    result_count: int = 0,
    error_count: int = 0,
    error_message: str = None
):
    """Mark a job as completed."""
    try:
        status = "completed" if error_count == 0 else "completed_with_errors"

        def _complete(conn):
            cursor = conn.cursor()
            cursor.execute("""
                UPDATE jobs
                SET status = %s,
                    completed_at = CURRENT_TIMESTAMP,
                    result_count = %s,
                    error_count = %s,
                    error_message = %s
                WHERE id = %s
            """, (status, result_count, error_count, error_message, job_id))
            conn.commit()

        await _run(_complete)
        logger.info(f"Job {job_id} completed: {result_count} success, {error_count} errors")

    except Exception as e:
        logger.error(f"Error completing job: {e}")


async def fail_job(job_id: int, error_message: str):
    """Mark a job as failed."""
    try:
        def _fail(conn):
            cursor = conn.cursor()
            cursor.execute("""
                UPDATE jobs
                SET status = 'failed',
                    completed_at = CURRENT_TIMESTAMP,
                    error_message = %s
                WHERE id = %s
            """, (error_message, job_id))
            conn.commit()

        await _run(_fail)
        logger.warning(f"Job {job_id} failed: {error_message}")

    except Exception as e:
        logger.error(f"Error failing job: {e}")


async def get_jobs(
    limit: int = 100,
    offset: int = 0,
    job_type: str = None,
    username: str = None,
    status: str = None,
    date_from: str = None,
    date_to: str = None
) -> dict:
    """Get jobs with filters."""
    try:
        def _get(conn):
            cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)

            where_clauses = []
            params = []

            if job_type:
                where_clauses.append("job_type = %s")
                params.append(job_type)

            if username:
                where_clauses.append("username = %s")
                params.append(username)

            if status:
                where_clauses.append("status = %s")
                params.append(status)

            if date_from:
                where_clauses.append("DATE(created_at) >= %s")
                params.append(date_from)

            if date_to:
                where_clauses.append("DATE(created_at) <= %s")
                params.append(date_to)

            where_clause = " WHERE " + " AND ".join(where_clauses) if where_clauses else ""

            cursor.execute(f"SELECT COUNT(*) FROM jobs{where_clause}", params)
            total = cursor.fetchone()[0]

            cursor.execute(
                f"""
                SELECT * FROM jobs
                {where_clause}
                ORDER BY created_at DESC
                LIMIT %s OFFSET %s
                """,
                params + [limit, offset]
            )

            jobs = []
            for row in cursor.fetchall():
                jobs.append({
                    "id": row["id"],
                    "created_at": row["created_at"],
                    "started_at": row["started_at"],
                    "completed_at": row["completed_at"],
                    "job_type": row["job_type"],
                    "operation": row["operation"],
                    "username": row["username"],
                    "status": row["status"],
                    "result_count": row["result_count"],
                    "error_count": row["error_count"],
                    "error_message": row["error_message"],
                    "details": row["details"],
                    "data_json": row["data_json"]
                })

            return {
                "jobs": jobs,
                "total": total,
                "limit": limit,
                "offset": offset
            }

        return await _run(_get)

    except Exception as e:
        logger.error(f"Error retrieving jobs: {e}")
        return {"jobs": [], "total": 0, "limit": limit, "offset": offset}


async def get_jobs_stats(date_from: str = None, date_to: str = None) -> dict:
    """Get job statistics."""
    try:
        def _stats(conn):
            cursor = conn.cursor()

            where_clause = ""
            params = []

            if date_from or date_to:
                clauses = []
                if date_from:
                    clauses.append("DATE(created_at) >= %s")
                    params.append(date_from)
                if date_to:
                    clauses.append("DATE(created_at) <= %s")
                    params.append(date_to)
                where_clause = " WHERE " + " AND ".join(clauses)

            cursor.execute(
                f"""
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'completed_with_errors' THEN 1 ELSE 0 END) as with_errors,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'pending' OR status = 'processing' THEN 1 ELSE 0 END) as pending,
                    SUM(COALESCE(result_count, 0)) as total_results,
                    SUM(COALESCE(error_count, 0)) as total_errors
                FROM jobs
                {where_clause}
                """,
                params
            )

            row = cursor.fetchone()

            cursor.execute(
                f"""
                SELECT job_type, COUNT(*) as count, SUM(COALESCE(result_count, 0)) as results
                FROM jobs
                {where_clause}
                GROUP BY job_type
                ORDER BY count DESC
                """,
                params
            )

            by_type = {}
            for row_type in cursor.fetchall():
                by_type[row_type[0]] = {"count": row_type[1], "results": row_type[2]}

            cursor.execute(
                f"""
                SELECT username, COUNT(*) as count, SUM(COALESCE(result_count, 0)) as results
                FROM jobs
                {where_clause}
                GROUP BY username
                ORDER BY count DESC
                """,
                params
            )

            by_user = {}
            for row_user in cursor.fetchall():
                by_user[row_user[0]] = {"count": row_user[1], "results": row_user[2]}

            return {
                "total_jobs": row[0],
                "completed": row[1],
                "with_errors": row[2],
                "failed": row[3],
                "pending": row[4],
                "total_results": row[5],
                "total_errors": row[6],
                "by_type": by_type,
                "by_user": by_user
            }

        return await _run(_stats)

    except Exception as e:
        logger.error(f"Error getting job stats: {e}")
        return {}


async def get_job(job_id: int) -> dict:
    """Get a specific job by ID."""
    try:
        def _get(conn):
            cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
            cursor.execute("SELECT * FROM jobs WHERE id = %s", (job_id,))
            row = cursor.fetchone()
            if not row:
                return None
            return {
                "id": row["id"],
                "created_at": row["created_at"],
                "started_at": row["started_at"],
                "completed_at": row["completed_at"],
                "job_type": row["job_type"],
                "operation": row["operation"],
                "username": row["username"],
                "status": row["status"],
                "result_count": row["result_count"],
                "error_count": row["error_count"],
                "error_message": row["error_message"],
                "details": row["details"],
                "data_json": row["data_json"]
            }
        return await _run(_get)
    except Exception as e:
        logger.error(f"Error getting job {job_id}: {e}")
        return None


async def update_job_progress(job_id: int, result_count: int, error_count: int, data_json: str = None):
    """Update progress for a running job without completing it."""
    try:
        def _update(conn):
            cursor = conn.cursor()
            if data_json is not None:
                cursor.execute("""
                    UPDATE jobs
                    SET result_count = %s, error_count = %s, data_json = %s
                    WHERE id = %s
                """, (result_count, error_count, data_json, job_id))
            else:
                cursor.execute("""
                    UPDATE jobs
                    SET result_count = %s, error_count = %s
                    WHERE id = %s
                """, (result_count, error_count, job_id))
            conn.commit()
        await _run(_update)
    except Exception as e:
        logger.error(f"Error updating job progress {job_id}: {e}")
