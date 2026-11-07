"""Registro de Actas (Servicio Técnico y Entrega de Equipos) — numeración
consecutiva por tipo+año (formato "0001-2026"), con el PDF generado y,
opcionalmente, el PDF ya firmado (escaneado) guardados junto al registro.

Sigue el mismo patrón de conexión que app.core.jobs: psycopg2 síncrono,
envuelto en asyncio.to_thread para no bloquear el event loop.
"""
import asyncio
import logging
from datetime import date, datetime

import psycopg2
import psycopg2.extras

from app.core.config import settings

logger = logging.getLogger(__name__)


_CONNECT_TIMEOUT = 5
_RUN_TIMEOUT = 10

# Conexión persistente y reusada en vez de abrir una psycopg2.connect()
# nueva en cada operación — mismo motivo que app.core.jobs/audit_log: abrir
# una conexión por llamada agotaba el límite de conexiones concurrentes del
# pooler de Supabase en modo sesión (pool_size: 15) bajo carga.
_conn = None
_db_lock = asyncio.Lock()


def _get_conn():
    global _conn
    if _conn is None or _conn.closed:
        _conn = psycopg2.connect(settings.supabase_database_url, connect_timeout=_CONNECT_TIMEOUT)
    return _conn


async def close_actas_db() -> None:
    global _conn
    if _conn is not None and not _conn.closed:
        _conn.close()
        _conn = None


async def _run(fn):
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
            logger.error(f"Actas DB operation timed out after {_RUN_TIMEOUT}s, discarding connection")
            _conn = None
            raise


def init_actas_db():
    conn = psycopg2.connect(settings.supabase_database_url)
    try:
        cursor = conn.cursor()

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS actas_contadores (
                tipo TEXT NOT NULL,
                anio INTEGER NOT NULL,
                ultimo_numero INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (tipo, anio)
            )
        """)

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS actas_servicio_tecnico (
                id SERIAL PRIMARY KEY,
                numero TEXT NOT NULL UNIQUE,
                anio INTEGER NOT NULL,
                numero_secuencial INTEGER NOT NULL,
                fecha DATE NOT NULL,
                hora TEXT,
                departamento_area TEXT,
                ubicacion_oficina TEXT,
                persona_reporta TEXT,
                tipo_equipo TEXT,
                tipo_equipo_otro TEXT,
                marca TEXT,
                modelo TEXT,
                nro_serie TEXT,
                falla_motivo TEXT,
                trabajo_realizado TEXT,
                repuestos_insumos TEXT,
                tecnico_responsable TEXT,
                encargado_ti_nombre TEXT,
                encargado_ti_ci TEXT,
                usuario_nombre TEXT,
                usuario_ci TEXT,
                pdf_generado BYTEA,
                pdf_firmado BYTEA,
                estado TEXT NOT NULL DEFAULT 'generado',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_by TEXT
            )
        """)
        cursor.execute("CREATE INDEX IF NOT EXISTS idx_ast_created_at ON actas_servicio_tecnico(created_at DESC)")

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS actas_entrega_equipo (
                id SERIAL PRIMARY KEY,
                numero TEXT NOT NULL UNIQUE,
                anio INTEGER NOT NULL,
                numero_secuencial INTEGER NOT NULL,
                fecha_emision DATE NOT NULL,
                entrega_nombre TEXT,
                entrega_ci TEXT,
                entrega_cargo TEXT,
                recibe_nombre TEXT,
                recibe_ci TEXT,
                recibe_cargo TEXT,
                insumo TEXT,
                marca TEXT,
                modelo TEXT,
                nro_serie TEXT,
                especificaciones TEXT,
                accesorios TEXT[],
                accesorios_otros TEXT,
                estado_equipo TEXT,
                observaciones_entrega TEXT,
                fecha_devolucion DATE,
                motivo_devolucion TEXT,
                estado_equipo_devolucion TEXT,
                observaciones_devolucion TEXT,
                pdf_generado BYTEA,
                pdf_firmado BYTEA,
                estado TEXT NOT NULL DEFAULT 'generado',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_by TEXT
            )
        """)
        cursor.execute("CREATE INDEX IF NOT EXISTS idx_aee_created_at ON actas_entrega_equipo(created_at DESC)")

        conn.commit()
        logger.info("Actas database initialized")
    finally:
        conn.close()


async def siguiente_numero(tipo: str, anio: int | None = None) -> tuple[str, int, int]:
    """Incrementa atómicamente el contador de (tipo, año) y devuelve
    (numero_formateado "0001-2026", numero_secuencial, anio). El upsert con
    ON CONFLICT es atómico a nivel de fila en Postgres — dos requests
    concurrentes nunca reciben el mismo número."""
    anio = anio or date.today().year

    def _incr(conn):
        cursor = conn.cursor()
        cursor.execute("""
            INSERT INTO actas_contadores (tipo, anio, ultimo_numero)
            VALUES (%s, %s, 1)
            ON CONFLICT (tipo, anio)
            DO UPDATE SET ultimo_numero = actas_contadores.ultimo_numero + 1
            RETURNING ultimo_numero
        """, (tipo, anio))
        n = cursor.fetchone()[0]
        conn.commit()
        return n

    n = await _run(_incr)
    return f"{n:04d}-{anio}", n, anio


# ── Servicio Técnico ─────────────────────────────────────────────────────

_AST_FIELDS = [
    "fecha", "hora", "departamento_area", "ubicacion_oficina", "persona_reporta",
    "tipo_equipo", "tipo_equipo_otro", "marca", "modelo", "nro_serie",
    "falla_motivo", "trabajo_realizado", "repuestos_insumos", "tecnico_responsable",
    "encargado_ti_nombre", "encargado_ti_ci", "usuario_nombre", "usuario_ci",
]


async def crear_acta_servicio_tecnico(numero: str, num_seq: int, anio: int, data: dict, pdf_bytes: bytes, created_by: str) -> dict:
    """Inserta la acta con un (numero, num_seq, anio) YA obtenido de
    siguiente_numero() — separado de la asignación del número porque el
    PDF necesita el número real para poder generarse ANTES de insertar la
    fila (el número no puede depender del contenido del PDF que a su vez
    depende del número)."""

    def _create(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        cols = ["numero", "anio", "numero_secuencial"] + _AST_FIELDS + ["pdf_generado", "created_by"]
        vals = [numero, anio, num_seq] + [data.get(f) for f in _AST_FIELDS] + [psycopg2.Binary(pdf_bytes), created_by]
        placeholders = ", ".join(["%s"] * len(vals))
        cursor.execute(
            f"INSERT INTO actas_servicio_tecnico ({', '.join(cols)}) VALUES ({placeholders}) RETURNING *",
            vals,
        )
        row = dict(cursor.fetchone())
        conn.commit()
        return row

    return await _run(_create)


async def actualizar_acta_servicio_tecnico(acta_id: int, data: dict, pdf_bytes: bytes | None) -> dict | None:
    def _update(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        set_parts = [f"{f} = %s" for f in _AST_FIELDS]
        vals = [data.get(f) for f in _AST_FIELDS]
        if pdf_bytes is not None:
            set_parts.append("pdf_generado = %s")
            vals.append(psycopg2.Binary(pdf_bytes))
        set_parts.append("updated_at = CURRENT_TIMESTAMP")
        vals.append(acta_id)
        cursor.execute(
            f"UPDATE actas_servicio_tecnico SET {', '.join(set_parts)} WHERE id = %s RETURNING *",
            vals,
        )
        row = cursor.fetchone()
        conn.commit()
        return dict(row) if row else None

    return await _run(_update)


async def subir_pdf_firmado_servicio_tecnico(acta_id: int, pdf_bytes: bytes) -> dict | None:
    def _upd(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        cursor.execute("""
            UPDATE actas_servicio_tecnico
            SET pdf_firmado = %s, estado = 'firmado', updated_at = CURRENT_TIMESTAMP
            WHERE id = %s RETURNING *
        """, (psycopg2.Binary(pdf_bytes), acta_id))
        row = cursor.fetchone()
        conn.commit()
        return dict(row) if row else None

    return await _run(_upd)


async def listar_actas_servicio_tecnico(limit: int = 50, offset: int = 0, search: str = "") -> dict:
    def _list(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        where = ""
        params = []
        if search:
            where = """WHERE numero ILIKE %s OR usuario_nombre ILIKE %s OR persona_reporta ILIKE %s
                       OR marca ILIKE %s OR modelo ILIKE %s"""
            like = f"%{search}%"
            params = [like] * 5
        cursor.execute(f"SELECT COUNT(*) FROM actas_servicio_tecnico {where}", params)
        total = cursor.fetchone()["count"]
        cursor.execute(
            f"""SELECT id, numero, fecha, hora, departamento_area, persona_reporta, tipo_equipo,
                       marca, modelo, tecnico_responsable, estado, created_at
                FROM actas_servicio_tecnico {where}
                ORDER BY created_at DESC LIMIT %s OFFSET %s""",
            params + [limit, offset],
        )
        rows = [dict(r) for r in cursor.fetchall()]
        return {"items": rows, "total": total}

    return await _run(_list)


async def obtener_acta_servicio_tecnico(acta_id: int) -> dict | None:
    def _get(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        cursor.execute("SELECT * FROM actas_servicio_tecnico WHERE id = %s", (acta_id,))
        row = cursor.fetchone()
        return dict(row) if row else None

    return await _run(_get)


async def eliminar_acta_servicio_tecnico(acta_id: int) -> bool:
    def _del(conn):
        cursor = conn.cursor()
        cursor.execute("DELETE FROM actas_servicio_tecnico WHERE id = %s", (acta_id,))
        deleted = cursor.rowcount > 0
        conn.commit()
        return deleted

    return await _run(_del)


# ── Entrega de Equipos ───────────────────────────────────────────────────

_AEE_FIELDS = [
    "fecha_emision", "entrega_nombre", "entrega_ci", "entrega_cargo", "recibe_nombre", "recibe_ci", "recibe_cargo",
    "insumo", "marca", "modelo", "nro_serie", "especificaciones", "accesorios", "accesorios_otros",
    "estado_equipo", "observaciones_entrega",
    "fecha_devolucion", "motivo_devolucion", "estado_equipo_devolucion", "observaciones_devolucion",
]


async def crear_acta_entrega_equipo(numero: str, num_seq: int, anio: int, data: dict, pdf_bytes: bytes, created_by: str) -> dict:
    def _create(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        cols = ["numero", "anio", "numero_secuencial"] + _AEE_FIELDS + ["pdf_generado", "created_by"]
        vals = [numero, anio, num_seq] + [data.get(f) for f in _AEE_FIELDS] + [psycopg2.Binary(pdf_bytes), created_by]
        placeholders = ", ".join(["%s"] * len(vals))
        cursor.execute(
            f"INSERT INTO actas_entrega_equipo ({', '.join(cols)}) VALUES ({placeholders}) RETURNING *",
            vals,
        )
        row = dict(cursor.fetchone())
        conn.commit()
        return row

    return await _run(_create)


async def actualizar_acta_entrega_equipo(acta_id: int, data: dict, pdf_bytes: bytes | None) -> dict | None:
    def _update(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        set_parts = [f"{f} = %s" for f in _AEE_FIELDS]
        vals = [data.get(f) for f in _AEE_FIELDS]
        if pdf_bytes is not None:
            set_parts.append("pdf_generado = %s")
            vals.append(psycopg2.Binary(pdf_bytes))
        set_parts.append("updated_at = CURRENT_TIMESTAMP")
        vals.append(acta_id)
        cursor.execute(
            f"UPDATE actas_entrega_equipo SET {', '.join(set_parts)} WHERE id = %s RETURNING *",
            vals,
        )
        row = cursor.fetchone()
        conn.commit()
        return dict(row) if row else None

    return await _run(_update)


async def subir_pdf_firmado_entrega_equipo(acta_id: int, pdf_bytes: bytes) -> dict | None:
    def _upd(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        cursor.execute("""
            UPDATE actas_entrega_equipo
            SET pdf_firmado = %s, estado = 'firmado', updated_at = CURRENT_TIMESTAMP
            WHERE id = %s RETURNING *
        """, (psycopg2.Binary(pdf_bytes), acta_id))
        row = cursor.fetchone()
        conn.commit()
        return dict(row) if row else None

    return await _run(_upd)


async def listar_actas_entrega_equipo(limit: int = 50, offset: int = 0, search: str = "") -> dict:
    def _list(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        where = ""
        params = []
        if search:
            where = """WHERE numero ILIKE %s OR recibe_nombre ILIKE %s OR entrega_nombre ILIKE %s
                       OR insumo ILIKE %s OR marca ILIKE %s OR modelo ILIKE %s"""
            like = f"%{search}%"
            params = [like] * 6
        cursor.execute(f"SELECT COUNT(*) FROM actas_entrega_equipo {where}", params)
        total = cursor.fetchone()["count"]
        cursor.execute(
            f"""SELECT id, numero, fecha_emision, entrega_nombre, recibe_nombre, insumo, marca, modelo,
                       estado, created_at
                FROM actas_entrega_equipo {where}
                ORDER BY created_at DESC LIMIT %s OFFSET %s""",
            params + [limit, offset],
        )
        rows = [dict(r) for r in cursor.fetchall()]
        return {"items": rows, "total": total}

    return await _run(_list)


async def obtener_acta_entrega_equipo(acta_id: int) -> dict | None:
    def _get(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.RealDictCursor)
        cursor.execute("SELECT * FROM actas_entrega_equipo WHERE id = %s", (acta_id,))
        row = cursor.fetchone()
        return dict(row) if row else None

    return await _run(_get)


async def eliminar_acta_entrega_equipo(acta_id: int) -> bool:
    def _del(conn):
        cursor = conn.cursor()
        cursor.execute("DELETE FROM actas_entrega_equipo WHERE id = %s", (acta_id,))
        deleted = cursor.rowcount > 0
        conn.commit()
        return deleted

    return await _run(_del)
