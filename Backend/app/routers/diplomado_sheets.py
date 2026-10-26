"""Planillas de trabajo de Diplomado — vista real (no maqueta) para alta de
alumnos y envío de credenciales, con un panel de candidatos de solo lectura
traído de la planilla comercial maestra de OneDrive ("Datos UBS").

Diseño (ver Frontend/templates/canvas/diplomados_sheet_preview.html para la
maqueta visual original aprobada por el usuario):
  - La tabla `diplomado_sheets`/`diplomado_rows` (app.core.diplomado_sheets_db)
    ES la fuente de verdad — no hay OneDrive de por medio para el trabajo
    diario, a diferencia del flujo "staging" de la rama hermana.
  - El panel de candidatos lee "Datos UBS" en SOLO LECTURA vía Graph — nunca
    se escribe ahí, ni siquiera para marcar quién ya fue procesado. El cruce
    "ya tiene credenciales" / "falta enviar" se calcula comparando por
    cédula contra las filas que YA están en `diplomado_rows`.

TODO (fuera de alcance de esta tarea, fase posterior explícitamente pedida
por el usuario): el envío real de credenciales / creación de cuentas en
app.routers.excel (_process_diplomados_bg, send_diplomados_credentials) hoy
dependen intrínsecamente de leer y volver a escribir un archivo real de
OneDrive — esa es literalmente la forma en que esas funciones ya probadas en
producción saben qué filas ya están creadas/enviadas en corridas futuras (lo
persisten en las columnas Usuario/Contraseña/Estado del propio archivo). No
hay forma de invocar esa lógica contra datos que solo existen en esta tabla
sin antes espejarlos a un archivo real de OneDrive — que es exactamente la
función de "respaldo automático a OneDrive" que el usuario pidió como fase
aparte. Hasta que esa fase exista, POST .../process devuelve 501 con una
explicación clara en vez de simular un envío o reimplementar la lógica de
negocio de excel.py en paralelo (lo cual duplicaría código ya probado y
podría divergir de él).
"""
import io
import logging

import openpyxl
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel

from app.core import diplomado_sheets_db as db
from app.core.config import settings
from app.services import teams_client as graph
from app.routers.excel import _encode_share_url, _find_header_row_and_headers, _clean_cedula, _norm

logger = logging.getLogger(__name__)
router = APIRouter(prefix="/diplomado-sheets", tags=["Planillas de Diplomado"])


class CreateSheetRequest(BaseModel):
    diplomado_name: str
    programa_ubs: str | None = None
    team_id: str | None = None
    owners: list[str] = []


class UpdateSheetRequest(BaseModel):
    diplomado_name: str | None = None
    programa_ubs: str | None = None
    team_id: str | None = None
    owners: list[str] | None = None


class RowData(BaseModel):
    fecha: str | None = None
    nombre_completo: str | None = None
    cedula: str | None = None
    correo: str | None = None
    telefono: str | None = None
    vendedor: str | None = None
    rol: str | None = None
    estado: str | None = None


class TransferRequest(BaseModel):
    candidates: list[RowData]


async def _get_sheet_or_404(sheet_id: int) -> dict:
    sheet = await db.get_sheet(sheet_id)
    if not sheet:
        raise HTTPException(status_code=404, detail="Planilla de diplomado no encontrada.")
    return sheet


# ── CRUD de la planilla ──────────────────────────────────────────────────

@router.post("/sheets")
async def create_sheet(req: CreateSheetRequest) -> dict:
    if not req.diplomado_name.strip():
        raise HTTPException(status_code=400, detail="El nombre del diplomado es obligatorio.")
    sheet_id = await db.create_sheet(
        diplomado_name=req.diplomado_name.strip(),
        programa_ubs=(req.programa_ubs or "").strip() or None,
        team_id=(req.team_id or "").strip() or None,
        owners=req.owners,
        created_by="admin",
    )
    return await _get_sheet_or_404(sheet_id)


@router.get("/sheets")
async def list_sheets() -> list[dict]:
    return await db.list_sheets()


@router.get("/sheets/{sheet_id}")
async def get_sheet(sheet_id: int) -> dict:
    sheet = await _get_sheet_or_404(sheet_id)
    rows = await db.get_rows(sheet_id)
    return {"sheet": sheet, "rows": rows}


@router.patch("/sheets/{sheet_id}")
async def update_sheet(sheet_id: int, req: UpdateSheetRequest) -> dict:
    await _get_sheet_or_404(sheet_id)
    await db.update_sheet(sheet_id, req.diplomado_name, req.programa_ubs, req.team_id, req.owners)
    return await _get_sheet_or_404(sheet_id)


@router.delete("/sheets/{sheet_id}")
async def delete_sheet(sheet_id: int) -> dict:
    deleted = await db.delete_sheet(sheet_id)
    if not deleted:
        raise HTTPException(status_code=404, detail="Planilla de diplomado no encontrada.")
    return {"status": "deleted"}


# ── Filas de la planilla de trabajo ──────────────────────────────────────

@router.post("/sheets/{sheet_id}/rows")
async def add_row(sheet_id: int, req: RowData) -> dict:
    await _get_sheet_or_404(sheet_id)
    if not (req.nombre_completo or "").strip() and not (req.cedula or "").strip():
        # Fila vacía agregada desde el botón "Agregar alumno" — se permite,
        # se completa a mano y se guarda cuando el operador escribe algo.
        return await db.add_row(sheet_id, {})
    return await db.add_row(sheet_id, req.model_dump(exclude_none=True))


@router.patch("/sheets/{sheet_id}/rows/{row_id}")
async def update_row(sheet_id: int, row_id: int, req: RowData) -> dict:
    await _get_sheet_or_404(sheet_id)
    updated = await db.update_row(sheet_id, row_id, req.model_dump(exclude_none=True))
    if not updated:
        raise HTTPException(status_code=404, detail="Fila no encontrada.")
    return {"status": "ok"}


@router.delete("/sheets/{sheet_id}/rows/{row_id}")
async def delete_row(sheet_id: int, row_id: int) -> dict:
    await _get_sheet_or_404(sheet_id)
    deleted = await db.delete_row(sheet_id, row_id)
    if not deleted:
        raise HTTPException(status_code=404, detail="Fila no encontrada.")
    return {"status": "deleted"}


# ── Candidatos (solo lectura, desde la planilla comercial de OneDrive) ───

@router.get("/sheets/{sheet_id}/candidates")
async def get_candidates(sheet_id: int) -> dict:
    """Lee 'Datos UBS' en SOLO LECTURA (nunca escribe ahí), filtra por el
    programa de este diplomado, y cruza por cédula contra las filas que ya
    están en la planilla de trabajo real. No requiere que el archivo tenga
    ninguna marca manual — el cruce lo hace el sistema."""
    sheet = await _get_sheet_or_404(sheet_id)

    if not settings.comercial_onedrive_url:
        raise HTTPException(
            status_code=400,
            detail="No hay una planilla comercial configurada (COMERCIAL_ONEDRIVE_URL en el .env). "
                   "Pedile al administrador que la configure para poder ver candidatos.",
        )

    encoded_url = _encode_share_url(settings.comercial_onedrive_url)
    try:
        contents = await graph.get_raw(f"/shares/{encoded_url}/driveItem/content")
    except Exception as e:
        raise HTTPException(status_code=502, detail=f"No se pudo leer la planilla comercial desde OneDrive. {e}")

    try:
        wb = openpyxl.load_workbook(io.BytesIO(contents), read_only=True, data_only=True)
    except Exception:
        raise HTTPException(status_code=502, detail="La planilla comercial descargada no es un Excel válido.")

    sheet_name = settings.comercial_sheet_name
    if sheet_name not in wb.sheetnames:
        wb.close()
        raise HTTPException(status_code=502, detail=f"La pestaña '{sheet_name}' no existe en la planilla comercial.")

    ws = wb[sheet_name]
    header_row_idx, headers, _raw = _find_header_row_and_headers(ws)
    if not header_row_idx:
        wb.close()
        raise HTTPException(status_code=502, detail="No se encontró la fila de encabezados en 'Datos UBS'.")

    def col(*keys):
        for k in keys:
            for h, idx in headers.items():
                if _norm(k) in h:
                    return idx
        return None

    col_nombre = col("nombre completo", "nombre")
    col_cedula = col("cedula", "cédula")
    col_correo = col("email", "correo")
    col_telefono = col("telefono", "celular")
    col_vendedor = col("asesor", "vendedor")
    col_programa = col("programa ubs", "programa")
    col_situacion = col("situacion")

    if not col_nombre or not col_cedula or not col_programa:
        wb.close()
        raise HTTPException(status_code=502, detail="No se encontraron las columnas esperadas (Nombre/Cédula/Programa) en 'Datos UBS'.")

    programa_filtro = _norm(sheet.get("programa_ubs") or sheet["diplomado_name"])
    existing_cedulas = await db.get_existing_cedulas(sheet_id)

    candidates = []
    for row_idx in range(header_row_idx + 1, ws.max_row + 1):
        nombre = str(ws.cell(row=row_idx, column=col_nombre).value or "").strip()
        cedula = _clean_cedula(str(ws.cell(row=row_idx, column=col_cedula).value or "").strip())
        programa = str(ws.cell(row=row_idx, column=col_programa).value or "").strip()
        if not nombre or not cedula or not programa:
            continue
        # Coincidencia parcial normalizada: los nombres de programa en Datos
        # UBS suelen traer sufijos de fecha ("... - 12 Octubre") que no
        # necesariamente coinciden carácter a carácter con el nombre del
        # diplomado en el sistema.
        if programa_filtro not in _norm(programa) and _norm(programa) not in programa_filtro:
            continue
        candidates.append({
            "nombre_completo": nombre,
            "cedula": cedula,
            "correo": str(ws.cell(row=row_idx, column=col_correo).value or "").strip() if col_correo else "",
            "telefono": str(ws.cell(row=row_idx, column=col_telefono).value or "").strip() if col_telefono else "",
            "vendedor": str(ws.cell(row=row_idx, column=col_vendedor).value or "").strip() if col_vendedor else "",
            "programa_ubs": programa,
            "situacion": str(ws.cell(row=row_idx, column=col_situacion).value or "").strip() if col_situacion else "",
            "ya_tiene_credenciales": cedula in existing_cedulas,
        })
    wb.close()

    return {"candidates": candidates, "programa_filtro": sheet.get("programa_ubs") or sheet["diplomado_name"]}


@router.post("/sheets/{sheet_id}/transfer")
async def transfer_candidates(sheet_id: int, req: TransferRequest) -> dict:
    """Copia los candidatos elegidos a la planilla de trabajo real (en la
    base de datos). Nunca toca la planilla comercial de origen."""
    await _get_sheet_or_404(sheet_id)
    from datetime import date
    today = date.today().strftime("%d/%m/%Y")
    rows = []
    for c in req.candidates:
        data = c.model_dump(exclude_none=True)
        data.setdefault("fecha", today)
        data.setdefault("rol", "Alumno")
        data.setdefault("estado", "pendiente")
        rows.append(data)
    inserted = await db.bulk_add_rows(sheet_id, rows)
    return {"status": "ok", "inserted": inserted}


# ── Procesar / enviar credenciales ───────────────────────────────────────

@router.post("/sheets/{sheet_id}/process")
async def process_sheet(sheet_id: int) -> dict:
    """Ver el TODO al principio del archivo: el procesamiento real todavía
    depende de un archivo de OneDrive real, que esta planilla (a propósito)
    no tiene. Devuelve 501 con una explicación clara en vez de simular un
    envío o fallar de forma confusa."""
    await _get_sheet_or_404(sheet_id)
    raise HTTPException(
        status_code=501,
        detail=(
            "El envío real de credenciales todavía no está conectado desde esta planilla. "
            "La lógica de creación de cuentas / envío de correos (ya probada en producción) "
            "depende de leer y guardar un archivo real de OneDrive — eso es lo que decide qué "
            "alumnos ya fueron procesados en corridas futuras. Falta construir el 'espejo "
            "automático a OneDrive' que el usuario pidió como fase aparte antes de poder "
            "conectar este botón de forma segura, sin reimplementar esa lógica por separado."
        ),
    )
