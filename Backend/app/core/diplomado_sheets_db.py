"""Planillas de trabajo de Diplomado — fuente de verdad real en la base de
datos (no un espejo de un archivo de OneDrive). Reemplaza el ciclo manual de
"copiar filas de la planilla comercial a la pestaña del diplomado en Excel"
por una grilla editable nativa de la web.

Separado de `app.core.staging_db` (que sigue existiendo en la rama
`feature/web-native-data-management` como un borrador sincronizable con
OneDrive) porque acá el modelo es distinto a propósito: no hay
`onedrive_url`/`sheet_name` por diseño — esta tabla ES la fuente de verdad,
no un borrador de ida y vuelta con un archivo externo.

El envío real de credenciales / creación de cuentas (app.routers.excel)
sigue dependiendo hoy de leer y escribir un archivo real de OneDrive (así
es como ese código ya probado en producción decide qué filas ya están
procesadas). Conectar esta tabla a ese flujo real requiere un paso de
"espejo a OneDrive" que todavía no existe — ver el TODO en
app.routers.diplomado_sheets.process_sheet.
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
    """Conexión nueva por llamada, cerrada siempre en el finally — misma
    estrategia que app.core.jobs y app.core.staging_db, deliberadamente sin
    compartir la conexión global de app.core.database."""
    def _wrapped():
        conn = psycopg2.connect(settings.supabase_database_url)
        try:
            return fn(conn)
        finally:
            conn.close()
    return await asyncio.to_thread(_wrapped)


def init_diplomado_sheets_db():
    conn = psycopg2.connect(settings.supabase_database_url)
    try:
        cursor = conn.cursor()
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS diplomado_sheets (
                id SERIAL PRIMARY KEY,
                diplomado_name TEXT NOT NULL,
                programa_ubs TEXT,
                team_id TEXT,
                owners JSONB NOT NULL DEFAULT '[]',
                created_by TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        """)
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS diplomado_rows (
                id SERIAL PRIMARY KEY,
                sheet_id INTEGER NOT NULL REFERENCES diplomado_sheets(id) ON DELETE CASCADE,
                fecha TEXT,
                nombre_completo TEXT NOT NULL,
                cedula TEXT NOT NULL,
                correo TEXT,
                telefono TEXT,
                vendedor TEXT,
                rol TEXT NOT NULL DEFAULT 'Alumno',
                estado TEXT NOT NULL DEFAULT 'pendiente',
                error_message TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        """)
        cursor.execute("""
            CREATE INDEX IF NOT EXISTS idx_diplomado_rows_sheet
            ON diplomado_rows(sheet_id)
        """)
        cursor.execute("""
            CREATE INDEX IF NOT EXISTS idx_diplomado_rows_cedula
            ON diplomado_rows(sheet_id, cedula)
        """)
        conn.commit()
        logger.info("Diplomado sheets database initialized")
    finally:
        conn.close()


async def create_sheet(diplomado_name: str, programa_ubs: str | None, team_id: str | None,
                        owners: list[str], created_by: str) -> int:
    def _create(conn):
        cursor = conn.cursor()
        cursor.execute("""
            INSERT INTO diplomado_sheets (diplomado_name, programa_ubs, team_id, owners, created_by)
            VALUES (%s, %s, %s, %s, %s)
            RETURNING id
        """, (diplomado_name, programa_ubs or diplomado_name, team_id, json.dumps(owners), created_by))
        sheet_id = cursor.fetchone()[0]
        conn.commit()
        return sheet_id
    return await _run(_create)


async def list_sheets() -> list[dict]:
    def _list(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
        cursor.execute("""
            SELECT s.*, COUNT(r.id) AS row_count
            FROM diplomado_sheets s
            LEFT JOIN diplomado_rows r ON r.sheet_id = s.id
            GROUP BY s.id
            ORDER BY s.updated_at DESC
        """)
        return [dict(r) for r in cursor.fetchall()]
    return await _run(_list)


async def get_sheet(sheet_id: int) -> dict | None:
    def _get(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
        cursor.execute("SELECT * FROM diplomado_sheets WHERE id = %s", (sheet_id,))
        row = cursor.fetchone()
        return dict(row) if row else None
    return await _run(_get)


async def update_sheet(sheet_id: int, diplomado_name: str | None, programa_ubs: str | None,
                        team_id: str | None, owners: list[str] | None) -> None:
    def _upd(conn):
        cursor = conn.cursor()
        fields, params = [], []
        if diplomado_name is not None:
            fields.append("diplomado_name = %s"); params.append(diplomado_name)
        if programa_ubs is not None:
            fields.append("programa_ubs = %s"); params.append(programa_ubs)
        if team_id is not None:
            fields.append("team_id = %s"); params.append(team_id)
        if owners is not None:
            fields.append("owners = %s"); params.append(json.dumps(owners))
        if not fields:
            return
        fields.append("updated_at = CURRENT_TIMESTAMP")
        params.append(sheet_id)
        cursor.execute(f"UPDATE diplomado_sheets SET {', '.join(fields)} WHERE id = %s", params)
        conn.commit()
    await _run(_upd)


async def delete_sheet(sheet_id: int) -> bool:
    def _del(conn):
        cursor = conn.cursor()
        cursor.execute("DELETE FROM diplomado_sheets WHERE id = %s", (sheet_id,))
        conn.commit()
        return cursor.rowcount > 0
    return await _run(_del)


async def get_rows(sheet_id: int) -> list[dict]:
    def _get(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
        cursor.execute("""
            SELECT * FROM diplomado_rows WHERE sheet_id = %s ORDER BY id
        """, (sheet_id,))
        return [dict(r) for r in cursor.fetchall()]
    return await _run(_get)


_ROW_FIELDS = ("fecha", "nombre_completo", "cedula", "correo", "telefono", "vendedor", "rol", "estado")


async def add_row(sheet_id: int, data: dict) -> dict:
    def _add(conn):
        cursor = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)
        cols = [c for c in _ROW_FIELDS if c in data]
        vals = [data[c] for c in cols]
        if "nombre_completo" not in cols:
            cols.append("nombre_completo"); vals.append("")
        if "cedula" not in cols:
            cols.append("cedula"); vals.append("")
        placeholders = ", ".join(["%s"] * len(vals))
        cursor.execute(f"""
            INSERT INTO diplomado_rows (sheet_id, {', '.join(cols)})
            VALUES (%s, {placeholders})
            RETURNING *
        """, (sheet_id, *vals))
        row = dict(cursor.fetchone())
        cursor.execute("UPDATE diplomado_sheets SET updated_at = CURRENT_TIMESTAMP WHERE id = %s", (sheet_id,))
        conn.commit()
        return row
    return await _run(_add)


async def update_row(sheet_id: int, row_id: int, data: dict) -> bool:
    def _upd(conn):
        cursor = conn.cursor()
        cols = [c for c in _ROW_FIELDS if c in data]
        if not cols:
            return False
        set_clause = ", ".join(f"{c} = %s" for c in cols)
        vals = [data[c] for c in cols]
        cursor.execute(f"""
            UPDATE diplomado_rows SET {set_clause}, updated_at = CURRENT_TIMESTAMP
            WHERE id = %s AND sheet_id = %s
        """, (*vals, row_id, sheet_id))
        conn.commit()
        return cursor.rowcount > 0
    return await _run(_upd)


async def delete_row(sheet_id: int, row_id: int) -> bool:
    def _del(conn):
        cursor = conn.cursor()
        cursor.execute("DELETE FROM diplomado_rows WHERE id = %s AND sheet_id = %s", (row_id, sheet_id))
        conn.commit()
        return cursor.rowcount > 0
    return await _run(_del)


async def bulk_add_rows(sheet_id: int, rows: list[dict]) -> int:
    """Usado por "traspasar seleccionados": inserta varias filas nuevas de una,
    salteando cédulas que ya existen en esta planilla (evita duplicar un
    traspaso si el operador repite la acción)."""
    def _add(conn):
        cursor = conn.cursor()
        cursor.execute("SELECT cedula FROM diplomado_rows WHERE sheet_id = %s", (sheet_id,))
        existing = {r[0] for r in cursor.fetchall()}
        inserted = 0
        for data in rows:
            cedula = (data.get("cedula") or "").strip()
            if not cedula or cedula in existing:
                continue
            cols = [c for c in _ROW_FIELDS if c in data]
            vals = [data[c] for c in cols]
            placeholders = ", ".join(["%s"] * len(vals))
            cursor.execute(f"""
                INSERT INTO diplomado_rows (sheet_id, {', '.join(cols)})
                VALUES (%s, {placeholders})
            """, (sheet_id, *vals))
            existing.add(cedula)
            inserted += 1
        cursor.execute("UPDATE diplomado_sheets SET updated_at = CURRENT_TIMESTAMP WHERE id = %s", (sheet_id,))
        conn.commit()
        return inserted
    return await _run(_add)


async def get_existing_cedulas(sheet_id: int) -> set[str]:
    """Usado por el cruce automático del panel de candidatos."""
    def _get(conn):
        cursor = conn.cursor()
        cursor.execute("SELECT cedula FROM diplomado_rows WHERE sheet_id = %s", (sheet_id,))
        return {r[0] for r in cursor.fetchall() if r[0]}
    return await _run(_get)
