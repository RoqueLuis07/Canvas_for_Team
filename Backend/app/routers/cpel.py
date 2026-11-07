"""Matriculación CPEL por Carrera.

El programa CPEL dicta las mismas materias para 3 carreras (Administración,
Marketing, Negocios), pero con dos particularidades:

1. En Canvas, cada carrera tiene su propia SUBCUENTA (no solo un sufijo en
   el nombre): la jerarquía real es
   Cuenta raíz > <Período, ej. "2026-2"> > CPEL > <Administración|Marketing|Negocios>,
   y los cursos de esa carrera viven directamente adentro de esa subcuenta.
   La API de Canvas para "listar cursos de una cuenta" NO busca recursivo
   en subcuentas hijas — hay que apuntar directo a la subcuenta de la
   carrera, no se puede filtrar por período con un simple search_term en
   la cuenta raíz (eso fue un intento anterior que no encontraba nada).
2. En Teams, las 3 carreras comparten un solo equipo por materia — el
   nombre del curso en Canvas trae el período embebido al final (ej.
   "Administración I (CPEL ADM 2026-02)"), del que se puede derivar el
   nombre del equipo compartido ("Administración I (CPEL) 2026-02") sin
   pedirle el período al usuario por separado.
"""
import asyncio
import json
import logging
import re
import unicodedata

from fastapi import APIRouter, BackgroundTasks, HTTPException, Request
from pydantic import BaseModel

from app.core.config import settings
from app.core import jobs
from app.services import canvas_client as canvas
from app.services import teams_client as graph
from app.routers.sync import resolve_user_identities

router = APIRouter(prefix="/cpel", tags=["Matriculación CPEL por Carrera"])
logger = logging.getLogger(__name__)
_ACCOUNT = settings.canvas_account_id

# Suffix esperado al final del nombre del curso: "(CPEL <CODIGO CARRERA> <PERIODO>)".
# Captura el período para poder derivar el nombre del equipo de Teams
# compartido sin pedírselo al usuario por separado.
_SUFFIX_RE = re.compile(r"\(\s*cpel\s+[a-zA-Z]+\s+([^\)]+?)\s*\)\s*$", re.IGNORECASE)


def _norm_key(s: str) -> str:
    ascii_s = unicodedata.normalize("NFKD", s or "").encode("ascii", "ignore").decode("ascii")
    return ascii_s.strip().lower()


# Tabla fija de equipos de Teams por defecto para el período 2026-02 —
# provista directamente por el usuario, para no depender de una búsqueda
# en vivo por nombre en cada matrícula (más rápido y 100% confiable para
# estas 32 materias conocidas). Clave: nombre base de la materia
# normalizado (sin tildes/mayúsculas). Si una materia no está acá (ej. un
# período futuro o una materia nueva), se cae al fallback de búsqueda por
# nombre en Microsoft Graph (ver get_team_id).
_TEAMS_DEFAULT_2026_02: dict[str, str] = {
    _norm_key(nombre): team_id
    for nombre, team_id in {
        "Administracion de RRHH": "651c0a3b-5d10-4a5d-902b-23c1ba047687",
        "Administracion I": "a48429b9-f69d-4708-842d-a08b482142fb",
        "Administracion II": "37ccf899-ce34-4610-b046-8889d1582e37",
        "Branding": "b0f50a6f-a700-4e63-9258-c1bd61e4fa6c",
        "Comercio Internacional": "72568207-64e8-4035-abf1-b09c77afedd3",
        "Comportamiento Organizacional en el Marketing": "0da0f95b-2b76-4e77-8f24-09f42ca0748c",
        "Comunicacion Oral y Escrita": "8245190f-da5d-4f6e-8f71-6858d258be4d",
        "Contabilidad de Costos": "a16bf034-1e48-47ca-bc82-17b882cca8db",
        "Contabilidad General": "b0a9478b-75a2-46dd-b748-e3e25d46120b",
        "Derecho Internacional": "729bf7e1-1ecc-4d77-9d44-79839aba6f59",
        "Direccion y Planeamiento Estrategico": "011e0f15-1af2-423e-a657-217b06477558",
        "English II": "da82c02a-ec0f-4e90-b3d4-94586c8f64b9",
        "Estadistica": "e7fe8b0b-5746-4cf8-80b7-5526b3de1a47",
        "Estrategia de Producto": "3c11a99f-41ef-49e9-980a-88bb4c0fb550",
        "Estrategia Empresarial": "4955b876-2454-458a-a02e-258451e29cb8",
        "Etica": "3de07d75-d660-49d3-8c85-90b7cd437e42",
        "Formulacion y Evaluacion de Proyectos": "e546df0f-306f-48f8-8bba-1e7ff214e2bc",
        "Introduccion a los Negocios": "2fbc25b8-5639-43ce-a979-72c4634289df",
        "Investigacion y Analisis de Mercado II": "a5421cc7-8419-48c9-a82d-1d04cf35efce",
        "Legislacion Comercial": "36fa2285-1b2e-4783-88be-0bad4ed25fa0",
        "Legislacion Laboral": "d0a3f535-de28-4ccd-be31-a74363687f42",
        "Liderazgo": "a5b84ac2-f4cd-45be-a178-ef146aa993f5",
        "Logistica y DFI": "1ec91efc-5745-4621-8f22-c6e93f9e331d",
        "Macroeconomia": "98f91f75-521e-455f-a343-d1832e001bd8",
        "Marketing": "049a0fd1-9b90-4d48-ba3d-f022f430bd30",
        "Marketing Digital": "e95ba7d0-e189-4c8b-88c9-db8a14b31841",
        "Matematica I": "161f0d0a-a152-477a-a6f8-fde6a6a3aa2c",
        "Matematica II": "e33c570b-db85-46e3-b264-f151467ecde6",
        "Organizacion, Sistemas y Metodos": "a8470900-a159-49e0-a16e-499ae2fc1e1d",
        "Pymes y Empresas Familiares": "94ffe240-db2c-46a9-b5af-3df4efb806f4",
        "Sistema de Informacion Gerencial": "6035f4cf-53b3-44c4-9086-98d6c45dbe42",
        "TIC": "6e49de2e-d377-4b6d-a3d0-7fe6a2f85470",
        "Transporte Internacional y Local": "250776ad-daa1-4eb8-9613-d287e118cbf4",
    }.items()
}


class SubaccountNode(BaseModel):
    id: str
    name: str
    parent_account_id: str | None = None


@router.get("/subaccounts", response_model=list[SubaccountNode], summary="Árbol completo de subcuentas de Canvas")
async def listar_subcuentas():
    """Devuelve TODAS las subcuentas (recursivo) para que el frontend arme
    los 3 selects en cascada (Período > Programa > Carrera) navegando la
    jerarquía real, en vez de adivinar nombres/formatos de período."""
    try:
        data = await canvas.paginate(
            f"/accounts/{_ACCOUNT}/sub_accounts", params={"recursive": "true", "per_page": 100},
        )
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"No se pudo obtener el árbol de subcuentas de Canvas. {e}")

    return [
        SubaccountNode(
            id=str(sa["id"]),
            name=sa.get("name") or "",
            parent_account_id=str(sa["parent_account_id"]) if sa.get("parent_account_id") else None,
        )
        for sa in (data or [])
    ]


class MateriaCandidate(BaseModel):
    course_id: str
    course_name: str
    materia_base: str
    periodo: str | None = None  # None si el nombre del curso no sigue el patrón "(CPEL <CARRERA> <PERIODO>)"


class BuscarMateriasResponse(BaseModel):
    materias: list[MateriaCandidate]


@router.get("/materias", response_model=BuscarMateriasResponse, summary="Cursos de Canvas dentro de la subcuenta de una carrera")
async def buscar_materias_cpel(subaccount_id: str):
    try:
        courses = await canvas.paginate(
            f"/accounts/{subaccount_id}/courses",
            params={"state[]": ["available", "unpublished", "created", "claimed"]},
        )
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"No se pudo obtener los cursos de esa subcuenta. {e}")

    materias: list[MateriaCandidate] = []
    for c in courses or []:
        name = c.get("name") or ""
        # El primer match (antes de recortar nada) es siempre el sufijo más
        # a la derecha — de ahí sale el período real del curso.
        m = _SUFFIX_RE.search(name)
        periodo = m.group(1).strip() if m else None
        # Un curso reciclado de un período anterior puede haber quedado con
        # MÁS de un sufijo "(CPEL ... ...)" pegado (ej. "Materia (CPEL ADM
        # 2025-01) (CPEL ADM 2026-02)") si se retagueó sin limpiar el
        # nombre viejo. Se recorta en bucle hasta que no quede ninguno al
        # final, no solo el último — si no, el sufijo viejo queda pegado al
        # nombre base y nunca matchea la tabla de equipos de Teams.
        base = name
        while _SUFFIX_RE.search(base):
            base = _SUFFIX_RE.sub("", base).strip()
        materias.append(MateriaCandidate(course_id=str(c["id"]), course_name=name, materia_base=base or name, periodo=periodo))

    materias.sort(key=lambda m: m.materia_base)
    return BuscarMateriasResponse(materias=materias)


class MateriaSeleccionada(BaseModel):
    course_id: str
    course_name: str
    materia_base: str
    periodo: str | None = None


class AlumnoCPEL(BaseModel):
    nombre: str
    cedula: str


class CPELEnrollRequest(BaseModel):
    carrera_label: str = ""  # solo informativo, para el historial de trabajos
    materias: list[MateriaSeleccionada]
    alumnos: list[AlumnoCPEL]


MAX_CPEL_ENROLL = 500  # tope de seguridad sobre alumnos × materias combinado


@router.post("/enroll", summary="Matricular un lote de alumnos de una carrera en las materias CPEL seleccionadas")
async def enroll_cpel(req: CPELEnrollRequest, bg_tasks: BackgroundTasks, request: Request) -> dict:
    if not req.materias:
        raise HTTPException(status_code=400, detail="No seleccionaste ninguna materia.")
    if not req.alumnos:
        raise HTTPException(status_code=400, detail="No cargaste ningún alumno.")

    total_ops = len(req.materias) * len(req.alumnos)
    if total_ops > MAX_CPEL_ENROLL:
        raise HTTPException(
            status_code=400,
            detail=f"Demasiadas matrículas a la vez ({len(req.alumnos)} alumnos × {len(req.materias)} materias = {total_ops}). Máximo {MAX_CPEL_ENROLL}. Dividí en tandas más chicas.",
        )

    job_id = await jobs.create_job(job_type="cpel_carrera_masivo", operation="import", username=jobs.username_from_request(request))
    bg_tasks.add_task(_process_cpel_enroll_bg, job_id, req)
    return {"status": "success", "job_id": job_id, "message": "Matriculación CPEL iniciada en segundo plano."}


async def _process_cpel_enroll_bg(job_id: int, req: CPELEnrollRequest):
    await jobs.start_job(job_id)

    success_count = 0
    error_count = 0
    results: list[dict] = []

    # El equipo de Teams es compartido entre las 3 carreras (mismo nombre
    # base, sin sufijo de carrera) — se resuelve UNA sola vez por materia,
    # no una vez por alumno, para no repetir la misma búsqueda cientos de
    # veces.
    team_id_cache: dict[str, str | None] = {}

    async def get_team_id(materia: MateriaSeleccionada) -> tuple[str | None, str]:
        team_name = f"{materia.materia_base} (CPEL) {materia.periodo}" if materia.periodo else f"{materia.materia_base} (CPEL)"

        # Tabla fija primero (conocida, 100% confiable para el período
        # 2026-02) — solo se recurre a la búsqueda en vivo por nombre si la
        # materia no está en la tabla (período nuevo, materia agregada
        # después, etc.).
        if materia.periodo == "2026-02":
            fixed_id = _TEAMS_DEFAULT_2026_02.get(_norm_key(materia.materia_base))
            if fixed_id:
                return fixed_id, team_name

        if team_name in team_id_cache:
            return team_id_cache[team_name], team_name
        tid = await graph.search_group_by_name(team_name)
        team_id_cache[team_name] = tid
        return tid, team_name

    async def process_one(alumno: AlumnoCPEL, materia: MateriaSeleccionada):
        nonlocal success_count, error_count
        try:
            canvas_user_id, teams_upn = await resolve_user_identities(alumno.cedula)
        except Exception as e:
            error_count += 1
            results.append({"alumno": alumno.nombre, "materia": materia.materia_base, "status": f"❌ {e}"})
            return

        # Se valida "¿ya está matriculado/agregado?" a partir de la respuesta
        # de Canvas/Teams (que ya lo informan como error de duplicado) en vez
        # de una consulta previa aparte — evita una llamada extra a la API
        # por alumno y es el mismo criterio ya usado en el resto del
        # sistema. Es habitual que un alumno de CPEL ya haya sido
        # matriculado a mano antes (avisos individuales) — acá NUNCA se
        # duplica: si ya estaba, se lo marca como tal y se sigue de largo,
        # tanto en Canvas como en Teams, de forma independiente.
        row_errors = []
        canvas_status = None
        try:
            await canvas.post(f"/courses/{materia.course_id}/enrollments", {
                "enrollment": {
                    "user_id": canvas_user_id,
                    "type": "StudentEnrollment",
                    "enrollment_state": "active",
                    "notify": False,
                },
            })
            canvas_status = "matriculado"
        except Exception as e:
            msg = str(e)
            if "already" in msg.lower() or "enrolled" in msg.lower():
                canvas_status = "ya estaba matriculado"
            else:
                row_errors.append(f"Canvas: {msg}")

        team_id, team_name = await get_team_id(materia)
        teams_status = None
        if not team_id:
            row_errors.append(f"Teams: no se encontró el equipo '{team_name}' — revisar el nombre en Teams")
        else:
            try:
                await graph.post(f"/teams/{team_id}/members", {
                    "@odata.type": "#microsoft.graph.aadUserConversationMember",
                    "roles": [],
                    "user@odata.bind": f"https://graph.microsoft.com/v1.0/users('{teams_upn}')",
                })
                teams_status = "agregado"
            except Exception as e:
                err_str = str(e).lower()
                if "already exist" in err_str or "request_badrequest" in err_str:
                    teams_status = "ya era miembro"
                else:
                    row_errors.append(f"Teams: {e}")

        if row_errors:
            error_count += 1
            results.append({"alumno": alumno.nombre, "materia": materia.materia_base, "status": f"❌ {' | '.join(row_errors)}"})
        else:
            success_count += 1
            results.append({
                "alumno": alumno.nombre,
                "materia": materia.materia_base,
                "status": f"✅ Canvas: {canvas_status} | Teams: {teams_status}",
            })

    pairs = [(a, m) for a in req.alumnos for m in req.materias]
    batch_size = 5
    for i in range(0, len(pairs), batch_size):
        chunk = pairs[i:i + batch_size]
        await asyncio.gather(*(process_one(a, m) for a, m in chunk))
        await jobs.update_job_progress(
            job_id, success_count, error_count,
            data_json=json.dumps({"total_to_process": len(pairs), "processed": success_count + error_count, "results": results}),
        )

    if error_count and success_count == 0:
        await jobs.fail_job(job_id, f"Todas las matrículas fallaron ({error_count}).")
    else:
        await jobs.complete_job(job_id, success_count, error_count)
