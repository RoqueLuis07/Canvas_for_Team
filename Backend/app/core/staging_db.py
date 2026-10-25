"""Tablas "staging": borrador editable en vivo de una planilla, pensado para
reemplazar el ciclo manual de "bajar el Excel de OneDrive, editarlo en
Excel, volver a subirlo" por una grilla editable dentro de la propia web
(edición de celdas, agregar/borrar filas, pegado tipo Excel) que se puede
sincronizar con el archivo real de OneDrive en cualquier momento (traer /
guardar), en vez de ser la única fuente de verdad.

No reemplaza `jobs` (historial de ejecuciones) ni tiene nada que ver con los
cachés de `canvas_courses`/`azure_users` — es exclusivamente el borrador que
edita un operador antes de procesar.
"""
import asyncio
import json
import logging
from datetime import datetime

import psycopg2
import psycopg2.extras

from app.core.config import settings

logger = logging.getLogger(__name__)


async def _run(fn):
    """Misma estrategia que app.core.jobs: conexión nueva por llamada,
    cerrada siempre en el finally. El staging no comparte la conexión global
    de app.core.database a propósito, para que un error acá no pueda dejar
    esa conexión compartida en estado de transacción abortada."""
    def _wrapped():
        conn = psycopg2.connect(settings.supabase_database_url)
        try:
            return fn(conn)
        finally:
            conn.close()
    return await asyncio.to_thread(_wrapped)


def init_staging_db():
    """Crea las tablas si no existen. Se llama una vez al arrancar la app,
    igual que el resto de los init_*_db."""
    conn = psycopg2.connect(settings.supabase_database_url)
    try:
        cursor = conn.cursor()

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS staging_sheets (
                id SERIAL PRIMARY KEY,
                flow_type TEXT NOT NULL,
                title TEXT,
                onedrive_url TEXT,
                sheet_name TEXT,
                headers JSONB NOT NULL DEFAULT '[]',
                created_by TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        """)

        cursor.execute("""
            CREATE TABLE IF NOT EXISTS staging_rows (
                id SERIAL PRIMARY KEY,
                sheet_id INTEGER NOT NULL REFERENCES staging_sheets(id) ON DELETE CASCADE,
                row_index INTEGER NOT NULL,
                data JSONB NOT NULL DEFAULT '{}',
                status TEXT NOT NULL DEFAULT 'pendiente',
                error_message TEXT,
                version INTEGER NOT NULL DEFAULT 1,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(sheet_id, row_index)
            )
        """)

        cursor.execute("""
            CREATE INDEX IF NOT EXISTS idx_staging_rows_sheet
            ON staging_rows(sheet_id, row_index)
        """)

        conn.commit()
        logger.info("Staging database initialized")
    finally:
        conn.close()


async def create_sheet(flow_type: str, title: str, onedrive_url: str | None,
                        sheet_name: str | None, headers: list[str], created_by: str) -> int:
    def _create(conn):
        cursor = conn.cursor()
        cursor.execute("""
            INSERT INTO staging_sheets (flow_type, title, onedrive_url, sheet_name, headers, created_by)
            VALUES (%s, %s, %s, %s, %s, %s)
            RETURNING id
        """, (flow_type, title, onedrive_url, sheet_name, json.dumps(headers), created_by))
        sheet_id = cursor.fetchone()[0]
        conn.commit()
        return sheet_id
    return await _run(_create)


async def list_sheets(flow_type: str | None = None) -> list[dict]:
    def _list(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
        if flow_type:
            cursor.execute("""
                SELECT id, flow_type, title, onedrive_url, sheet_name, created_by, created_at, updated_at
                FROM staging_sheets WHERE flow_type = %s ORDER BY updated_at DESC
            """, (flow_type,))
        else:
            cursor.execute("""
                SELECT id, flow_type, title, onedrive_url, sheet_name, created_by, created_at, updated_at
                FROM staging_sheets ORDER BY updated_at DESC
            """)
        return [dict(r) for r in cursor.fetchall()]
    return await _run(_list)


async def get_sheet(sheet_id: int) -> dict | None:
    def _get(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
        cursor.execute("SELECT * FROM staging_sheets WHERE id = %s", (sheet_id,))
        row = cursor.fetchone()
        return dict(row) if row else None
    return await _run(_get)


async def delete_sheet(sheet_id: int) -> bool:
    def _del(conn):
        cursor = conn.cursor()
        cursor.execute("DELETE FROM staging_sheets WHERE id = %s", (sheet_id,))
        conn.commit()
        return cursor.rowcount > 0
    return await _run(_del)


async def update_sheet_headers(sheet_id: int, headers: list[str]) -> None:
    def _upd(conn):
        cursor = conn.cursor()
        cursor.execute("""
            UPDATE staging_sheets SET headers = %s, updated_at = %s WHERE id = %s
        """, (json.dumps(headers), datetime.utcnow(), sheet_id))
        conn.commit()
    await _run(_upd)


async def touch_sheet(sheet_id: int) -> None:
    def _touch(conn):
        cursor = conn.cursor()
        cursor.execute("UPDATE staging_sheets SET updated_at = %s WHERE id = %s", (datetime.utcnow(), sheet_id))
        conn.commit()
    await _run(_touch)


async def replace_rows(sheet_id: int, headers: list[str], rows: list[dict]) -> int:
    """Usado por "Traer desde OneDrive": tira todas las filas actuales del
    borrador y las reemplaza por lo que acaba de leer del archivo real. Es
    deliberadamente destructivo (igual que hoy "pegar" un Excel nuevo
    reemplaza al anterior) — el operador lo dispara a propósito, no es un
    merge silencioso."""
    def _replace(conn):
        cursor = conn.cursor()
        cursor.execute("DELETE FROM staging_rows WHERE sheet_id = %s", (sheet_id,))
        for idx, row_data in enumerate(rows):
            cursor.execute("""
                INSERT INTO staging_rows (sheet_id, row_index, data)
                VALUES (%s, %s, %s)
            """, (sheet_id, idx, json.dumps(row_data)))
        cursor.execute("""
            UPDATE staging_sheets SET headers = %s, updated_at = %s WHERE id = %s
        """, (json.dumps(headers), datetime.utcnow(), sheet_id))
        conn.commit()
        return len(rows)
    return await _run(_replace)


async def get_rows(sheet_id: int) -> list[dict]:
    def _get(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
        cursor.execute("""
            SELECT row_index, data, status, error_message, version, updated_at
            FROM staging_rows WHERE sheet_id = %s ORDER BY row_index
        """, (sheet_id,))
        return [dict(r) for r in cursor.fetchall()]
    return await _run(_get)


async def upsert_rows(sheet_id: int, rows: list[dict]) -> None:
    """Edición de celdas o pegado (potencialmente muchas filas a la vez,
    como al pegar un rango copiado de Excel). `rows` es una lista de
    {row_index, data} — si el row_index ya existe se pisa esa fila
    (y se resetea su estado a "pendiente" porque cambió el contenido),
    si no existe se crea."""
    def _upsert(conn):
        cursor = conn.cursor()
        for r in rows:
            cursor.execute("""
                INSERT INTO staging_rows (sheet_id, row_index, data, status, version)
                VALUES (%s, %s, %s, 'pendiente', 1)
                ON CONFLICT (sheet_id, row_index) DO UPDATE SET
                    data = EXCLUDED.data,
                    status = 'pendiente',
                    error_message = NULL,
                    version = staging_rows.version + 1,
                    updated_at = CURRENT_TIMESTAMP
            """, (sheet_id, r["row_index"], json.dumps(r["data"])))
        cursor.execute("UPDATE staging_sheets SET updated_at = %s WHERE id = %s", (datetime.utcnow(), sheet_id))
        conn.commit()
    await _run(_upsert)


async def add_row(sheet_id: int, data: dict | None = None) -> int:
    def _add(conn):
        cursor = conn.cursor()
        cursor.execute("SELECT COALESCE(MAX(row_index), -1) + 1 FROM staging_rows WHERE sheet_id = %s", (sheet_id,))
        next_idx = cursor.fetchone()[0]
        cursor.execute("""
            INSERT INTO staging_rows (sheet_id, row_index, data)
            VALUES (%s, %s, %s)
        """, (sheet_id, next_idx, json.dumps(data or {})))
        cursor.execute("UPDATE staging_sheets SET updated_at = %s WHERE id = %s", (datetime.utcnow(), sheet_id))
        conn.commit()
        return next_idx
    return await _run(_add)


async def delete_row(sheet_id: int, row_index: int) -> bool:
    def _del(conn):
        cursor = conn.cursor()
        cursor.execute("DELETE FROM staging_rows WHERE sheet_id = %s AND row_index = %s", (sheet_id, row_index))
        deleted = cursor.rowcount > 0
        cursor.execute("UPDATE staging_sheets SET updated_at = %s WHERE id = %s", (datetime.utcnow(), sheet_id))
        conn.commit()
        return deleted
    return await _run(_del)


async def set_row_status(sheet_id: int, row_index: int, status: str, error_message: str | None = None) -> None:
    """Lo usa el procesamiento en segundo plano para reflejar en la grilla,
    fila por fila, si se creó bien o dio error — reemplaza el viejo patrón
    de "pintar la celda de rojo/verde en el Excel" por un estado real."""
    def _set(conn):
        cursor = conn.cursor()
        cursor.execute("""
            UPDATE staging_rows SET status = %s, error_message = %s, updated_at = %s
            WHERE sheet_id = %s AND row_index = %s
        """, (status, error_message, datetime.utcnow(), sheet_id, row_index))
        conn.commit()
    await _run(_set)
