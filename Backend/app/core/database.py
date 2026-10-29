"""Database module - handles SQLite and PostgreSQL operations."""
import psycopg2
import psycopg2.extras
import asyncio
import logging
from datetime import datetime
from pathlib import Path

logger = logging.getLogger(__name__)

# Database file location


_conn = None
_db_lock = asyncio.Lock()

# Tiempo máximo para abrir la conexión y para que una query corra del lado
# del servidor. Sin esto, una conexión/consulta colgada (red caída, DB sin
# responder) bloquea `_db_lock` para siempre y TODA la app se cuelga, porque
# cada llamada a `database.*` pasa por este mismo lock.
_CONNECT_TIMEOUT = 5
_STATEMENT_TIMEOUT_MS = 8000
_RUN_TIMEOUT = 10  # > _STATEMENT_TIMEOUT_MS/1000, le da margen a Postgres a cortar primero

from app.core.config import settings
def _get_conn():
    global _conn
    if _conn is None or _conn.closed:
        _conn = psycopg2.connect(
            settings.supabase_database_url,
            connect_timeout=_CONNECT_TIMEOUT,
            options=f"-c statement_timeout={_STATEMENT_TIMEOUT_MS}",
        )
    return _conn

async def close_db():
    global _conn
    if _conn:
        _conn.close()
        _conn = None


async def _run(fn):
    """Ejecuta `fn(conn)` en un hilo aparte, serializado por `_db_lock`.

    Si `fn` lanza una excepción, hace rollback ANTES de propagarla. Sin esto,
    la conexión global (`_conn`) queda en estado "transacción abortada" tras
    cualquier error, y como se reutiliza en todas las llamadas siguientes,
    UNA sola consulta fallida rompía todas las operaciones de base de datos
    del resto de la app hasta reiniciar el proceso.

    Además, la ejecución entera tiene un timeout (`_RUN_TIMEOUT`): si el hilo
    no vuelve a tiempo (conexión/consulta colgada), se libera `_db_lock` igual
    (lo hace `async with` al cancelarse) y se descarta `_conn` para que la
    próxima llamada abra una conexión nueva, en vez de dejar bloqueadas todas
    las operaciones de base de datos del resto de la app indefinidamente. El
    hilo colgado se abandona (Python no puede cancelar una llamada bloqueante
    de psycopg2 a mitad de camino), pero como ya no comparte la conexión
    global con nadie más, no corrompe las siguientes llamadas.
    """
    def _wrapped():
        conn = _get_conn()
        try:
            return fn(conn)
        except Exception:
            try:
                conn.rollback()
            except Exception:
                # La conexión quedó en un estado irrecuperable (ej. se cayó
                # la red a mitad de la transacción) — se descarta para que
                # la próxima llamada abra una conexión nueva y limpia.
                global _conn
                _conn = None
            raise

    async with _db_lock:
        try:
            return await asyncio.wait_for(asyncio.to_thread(_wrapped), timeout=_RUN_TIMEOUT)
        except asyncio.TimeoutError:
            global _conn
            logger.error(f"DB operation timed out after {_RUN_TIMEOUT}s, discarding connection")
            _conn = None
            raise


async def init_db():
    """Initialize database - create tables if needed."""
    def _init(conn):
        cursor = conn.cursor()

        # Create main tables
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS canvas_courses (
                id BIGINT PRIMARY KEY,
                name TEXT NOT NULL,
                course_code TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        """)

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS canvas_users (
                id BIGINT PRIMARY KEY,
                name TEXT NOT NULL,
                email TEXT UNIQUE,
                login_id TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        """)

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS canvas_enrollments (
                id BIGINT PRIMARY KEY,
                course_id BIGINT NOT NULL,
                user_id BIGINT NOT NULL,
                role TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (course_id) REFERENCES canvas_courses(id),
                FOREIGN KEY (user_id) REFERENCES canvas_users(id)
            )
        """)

        # Migración: tablas creadas antes de este fix tenían course_id/user_id
        # como INTEGER, pero referencian canvas_courses.id/canvas_users.id que
        # son BIGINT — ensanchar INTEGER -> BIGINT es siempre seguro en
        # Postgres (nunca trunca ni falla), así que se aplica también a
        # instalaciones ya existentes.
        cursor.execute("""
            ALTER TABLE canvas_enrollments ALTER COLUMN course_id TYPE BIGINT;
            ALTER TABLE canvas_enrollments ALTER COLUMN user_id TYPE BIGINT;
        """)

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS azure_users (
                id TEXT PRIMARY KEY,
                display_name TEXT NOT NULL,
                mail TEXT UNIQUE,
                user_principal_name TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        """)

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS sync_metadata (
                id BIGINT PRIMARY KEY,
                sync_type TEXT NOT NULL,
                last_sync TIMESTAMP,
                status TEXT DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(sync_type)
            )
        """)

        # Configuración simple clave/valor editable desde la web (ej. lista de
        # CC para el Envío de Credenciales), sin necesitar variables de
        # entorno ni un redeploy para cambiarla.
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS app_settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        """)

        conn.commit()
        logger.info("Database initialized at Supabase PostgreSQL")

    # Reintenta la inicialización unas pocas veces con espera breve: si el
    # contenedor de la app arranca antes de que Supabase esté accesible
    # (carrera típica en plataformas como Railway), sin reintento las tablas
    # nunca se crean y la app queda sirviendo tráfico en estado degradado
    # permanente ("relation does not exist" en cada query) hasta reiniciar
    # manualmente. Se mantiene corto (máx. ~7s) para no arriesgar el timeout
    # de health-check de arranque de la plataforma.
    attempts = 4
    for attempt in range(1, attempts + 1):
        try:
            await _run(_init)
            return
        except Exception as e:
            if attempt == attempts:
                logger.error(f"Database initialization error tras {attempts} intentos: {e}", exc_info=True)
                raise
            wait = attempt  # 1s, 2s, 3s
            logger.warning(f"Database initialization falló (intento {attempt}/{attempts}), reintentando en {wait}s: {e}")
            await asyncio.sleep(wait)


async def count_courses() -> int:
    """Count total courses."""
    try:
        def _count(conn):
            cursor = conn.cursor()
            cursor.execute("SELECT COUNT(*) FROM canvas_courses")
            return cursor.fetchone()[0]
        return await _run(_count)
    except Exception as e:
        logger.error(f"Error counting courses: {e}")
        return 0


async def count_canvas_users() -> int:
    """Count total Canvas users."""
    try:
        def _count(conn):
            cursor = conn.cursor()
            cursor.execute("SELECT COUNT(*) FROM canvas_users")
            return cursor.fetchone()[0]
        return await _run(_count)
    except Exception as e:
        logger.error(f"Error counting Canvas users: {e}")
        return 0


async def count_azure_users() -> int:
    """Count total Azure users."""
    try:
        def _count(conn):
            cursor = conn.cursor()
            cursor.execute("SELECT COUNT(*) FROM azure_users")
            return cursor.fetchone()[0]
        return await _run(_count)
    except Exception as e:
        logger.error(f"Error counting Azure users: {e}")
        return 0


def _stable_id(text: str) -> int:
    """ID numérico determinístico y estable entre corridas (a diferencia de
    Python `hash()`, que está aleatorizado por proceso salvo PYTHONHASHSEED=0),
    usado para tablas con PRIMARY KEY sin default/serial donde la clave lógica
    real es otra columna (ej: sync_metadata.sync_type)."""
    import zlib
    return zlib.crc32(text.encode()) & 0x7FFFFFFF


async def mark_synced(sync_type: str) -> bool:
    """Mark a sync as completed."""
    try:
        def _mark(conn):
            cursor = conn.cursor()
            cursor.execute("""
                INSERT INTO sync_metadata (id, sync_type, last_sync, status)
                VALUES (%s, %s, %s, 'completed')
                ON CONFLICT (sync_type) DO UPDATE SET
                    last_sync = EXCLUDED.last_sync,
                    status = EXCLUDED.status
            """, (_stable_id(sync_type), sync_type, datetime.utcnow()))
            conn.commit()
            return True
        return await _run(_mark)
    except Exception as e:
        logger.error(f"Error marking sync: {e}")
        return False


async def get_last_sync(sync_type: str) -> datetime | None:
    """Get the last sync time for a specific type."""
    try:
        def _get(conn):
            cursor = conn.cursor()
            cursor.execute("""
                SELECT last_sync FROM sync_metadata WHERE sync_type = %s
            """, (sync_type,))
            result = cursor.fetchone()
            return result[0] if result else None
        result = await _run(_get)
        if not result:
            return None
        # psycopg2 ya devuelve un datetime nativo para columnas TIMESTAMP.
        return result if isinstance(result, datetime) else datetime.fromisoformat(result)
    except Exception as e:
        logger.error(f"Error getting last sync: {e}")
        return None


async def is_stale(sync_type: str, ttl_seconds: int = 3600) -> bool:
    """Check if a sync is stale (older than TTL)."""
    try:
        last_sync = await get_last_sync(sync_type)
        if not last_sync:
            return True

        time_elapsed = (datetime.utcnow() - last_sync).total_seconds()
        return time_elapsed > ttl_seconds
    except Exception as e:
        logger.error(f"Error checking staleness: {e}")
        return True


async def upsert_courses(courses: list) -> int:
    """Upsert courses into database. Returns count of inserted/updated."""
    try:
        def _upsert(conn):
            cursor = conn.cursor()
            count = 0

            for course in courses:
                try:
                    cursor.execute("""
                        INSERT INTO canvas_courses (id, name, course_code, updated_at)
                        VALUES (%s, %s, %s, %s)
                        ON CONFLICT (id) DO UPDATE SET
                            name = EXCLUDED.name,
                            course_code = EXCLUDED.course_code,
                            updated_at = EXCLUDED.updated_at
                    """, (
                        course.get('id'),
                        course.get('name', ''),
                        course.get('course_code', ''),
                        datetime.utcnow()
                    ))
                    count += 1
                except Exception as ce:
                    conn.rollback()
                    logger.warning(f"Skipping course {course.get('id')}: {ce}")

            conn.commit()
            return count
        return await _run(_upsert)
    except Exception as e:
        logger.error(f"Error upserting courses: {e}")
        return 0


async def upsert_canvas_users(users: list) -> int:
    """Upsert Canvas users into database. Returns count of inserted/updated."""
    try:
        def _upsert(conn):
            cursor = conn.cursor()
            count = 0

            for user in users:
                try:
                    cursor.execute("""
                        INSERT INTO canvas_users (id, name, email, login_id, updated_at)
                        VALUES (%s, %s, %s, %s, %s)
                        ON CONFLICT (id) DO UPDATE SET
                            name = EXCLUDED.name,
                            email = EXCLUDED.email,
                            login_id = EXCLUDED.login_id,
                            updated_at = EXCLUDED.updated_at
                    """, (
                        user.get('id'),
                        user.get('name', ''),
                        user.get('email') or None,
                        user.get('login_id', ''),
                        datetime.utcnow()
                    ))
                    count += 1
                except Exception as e:
                    conn.rollback()
                    logger.warning(f"Skipping user {user.get('id')}: {e}")
                    continue

            conn.commit()
            return count
        return await _run(_upsert)
    except Exception as e:
        logger.error(f"Error upserting Canvas users: {e}")
        return 0


async def get_canvas_users() -> list:
    """Return all Canvas users from local DB."""
    try:
        def _get(conn):
            cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
            cursor.execute("SELECT id, name, email, login_id FROM canvas_users ORDER BY name")
            return [dict(r) for r in cursor.fetchall()]
        return await _run(_get)
    except Exception as e:
        logger.error(f"Error getting Canvas users: {e}")
        return []


async def search_canvas_users(term: str, limit: int = 1000) -> list:
    """Search Canvas users in local DB by name, email or login_id."""
    try:
        def _search(conn):
            cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
            pattern = f"%{term}%"
            cursor.execute("""
                SELECT id, name, email, login_id FROM canvas_users
                WHERE name LIKE %s OR email LIKE %s OR login_id LIKE %s
                ORDER BY name LIMIT %s
            """, (pattern, pattern, pattern, limit))
            return [dict(r) for r in cursor.fetchall()]
        return await _run(_search)
    except Exception as e:
        logger.error(f"Error searching Canvas users: {e}")
        return []


async def delete_canvas_user(user_id: str) -> bool:
    """Delete a Canvas user from local DB."""
    try:
        def _del(conn):
            cursor = conn.cursor()
            cursor.execute("DELETE FROM canvas_users WHERE id = %s", (user_id,))
            conn.commit()
        await _run(_del)
        return True
    except Exception as e:
        logger.error(f"Error deleting Canvas user {user_id}: {e}")
        return False


async def get_courses() -> list:
    """Return all courses from local DB."""
    try:
        def _get(conn):
            cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
            cursor.execute("SELECT id, name, course_code FROM canvas_courses ORDER BY name")
            return [dict(r) for r in cursor.fetchall()]
        return await _run(_get)
    except Exception as e:
        logger.error(f"Error getting courses: {e}")
        return []


async def delete_course(course_id: str) -> bool:
    """Delete a course from local DB."""
    try:
        def _del(conn):
            cursor = conn.cursor()
            cursor.execute("DELETE FROM canvas_courses WHERE id = %s", (course_id,))
            conn.commit()
        await _run(_del)
        return True
    except Exception as e:
        logger.error(f"Error deleting course {course_id}: {e}")
        return False


async def upsert_azure_users(users: list) -> int:
    """Upsert Azure AD users into local DB."""
    try:
        def _upsert(conn):
            cursor = conn.cursor()
            count = 0
            for user in users:
                try:
                    cursor.execute("""
                        INSERT INTO azure_users
                            (id, display_name, mail, user_principal_name, updated_at)
                        VALUES (%s, %s, %s, %s, %s)
                        ON CONFLICT (id) DO UPDATE SET
                            display_name = EXCLUDED.display_name,
                            mail = EXCLUDED.mail,
                            user_principal_name = EXCLUDED.user_principal_name,
                            updated_at = EXCLUDED.updated_at
                    """, (
                        user.get('id'),
                        user.get('displayName', ''),
                        user.get('mail') or None,
                        user.get('userPrincipalName', ''),
                        datetime.utcnow()
                    ))
                    count += 1
                except Exception as ue:
                    conn.rollback()
                    logger.warning(f"Skipping Azure user {user.get('id')}: {ue}")
            conn.commit()
            return count
        return await _run(_upsert)
    except Exception as e:
        logger.error(f"Error upserting Azure users: {e}")
        return 0


async def get_azure_users(search: str | None = None) -> list:
    """Return Azure AD users from local DB, optionally filtered by search term."""
    try:
        def _get(conn):
            cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
            if search:
                q = f"%{search.lower()}%"
                cursor.execute("""
                    SELECT id, display_name, mail, user_principal_name
                    FROM azure_users
                    WHERE lower(display_name) LIKE %s
                       OR lower(user_principal_name) LIKE %s
                       OR lower(coalesce(mail,'')) LIKE %s
                    ORDER BY display_name
                """, (q, q, q))
            else:
                cursor.execute("""
                    SELECT id, display_name, mail, user_principal_name
                    FROM azure_users ORDER BY display_name
                """)
            return [dict(r) for r in cursor.fetchall()]
        return await _run(_get)
    except Exception as e:
        logger.error(f"Error getting Azure users: {e}")
        return []


async def delete_azure_user(user_id: str) -> bool:
    """Delete an Azure AD user from local DB."""
    try:
        def _del(conn):
            cursor = conn.cursor()
            cursor.execute("DELETE FROM azure_users WHERE id = %s", (user_id,))
            conn.commit()
        await _run(_del)
        return True
    except Exception as e:
        logger.error(f"Error deleting Azure user {user_id}: {e}")
        return False


async def upsert_enrollments(enrollments: list) -> int:
    """Upsert enrollments into local DB."""
    try:
        def _upsert(conn):
            cursor = conn.cursor()
            count = 0
            for e in enrollments:
                try:
                    cursor.execute("""
                        INSERT INTO canvas_enrollments
                            (id, course_id, user_id, role)
                        VALUES (%s, %s, %s, %s)
                        ON CONFLICT (id) DO UPDATE SET
                            course_id = EXCLUDED.course_id,
                            user_id = EXCLUDED.user_id,
                            role = EXCLUDED.role
                    """, (
                        e.get('id'),
                        e.get('course_id'),
                        e.get('user_id'),
                        e.get('type', e.get('role', '')),
                    ))
                    count += 1
                except Exception as ue:
                    conn.rollback()
                    logger.warning(f"Skipping enrollment {e.get('id')}: {ue}")
            conn.commit()
            return count
        return await _run(_upsert)
    except Exception as e:
        logger.error(f"Error upserting enrollments: {e}")
        return 0


async def delete_enrollment(enrollment_id: str) -> bool:
    """Delete an enrollment from local DB."""
    try:
        def _del(conn):
            cursor = conn.cursor()
            cursor.execute("DELETE FROM canvas_enrollments WHERE id = %s", (enrollment_id,))
            conn.commit()
        await _run(_del)
        return True
    except Exception as e:
        logger.error(f"Error deleting enrollment {enrollment_id}: {e}")
        return False


async def get_setting(key: str) -> str | None:
    """Read a value from app_settings. Returns None if not set."""
    try:
        def _get(conn):
            cursor = conn.cursor()
            cursor.execute("SELECT value FROM app_settings WHERE key = %s", (key,))
            row = cursor.fetchone()
            return row[0] if row else None
        return await _run(_get)
    except Exception as e:
        logger.error(f"Error getting setting '{key}': {e}")
        return None


async def set_setting(key: str, value: str) -> bool:
    """Upsert a value into app_settings."""
    try:
        def _set(conn):
            cursor = conn.cursor()
            cursor.execute("""
                INSERT INTO app_settings (key, value, updated_at)
                VALUES (%s, %s, %s)
                ON CONFLICT (key) DO UPDATE SET
                    value = EXCLUDED.value,
                    updated_at = EXCLUDED.updated_at
            """, (key, value, datetime.utcnow()))
            conn.commit()
            return True
        return await _run(_set)
    except Exception as e:
        logger.error(f"Error setting '{key}': {e}")
        return False
