"""Canvas course management endpoints."""
import asyncio
import logging
from typing import Annotated
from datetime import datetime
from fastapi.background import BackgroundTasks

from fastapi import APIRouter, HTTPException, Query
from starlette.exceptions import HTTPException as StarletteHTTPException

from app.core import cache as _cache, database
from app.core.config import settings
from app.models.canvas import (
    BulkResult,

    CanvasCourseUpdate,
)
from app.services import canvas_client as canvas

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/canvas/courses", tags=["Canvas · Courses"])
_ACCOUNT = settings.canvas_account_id

# Todos los estados posibles de un curso en Canvas LMS
_ALL_STATES = ["created", "claimed", "available", "completed", "deleted"]

# TTL del caché: 30 min (los cursos cambian poco)
_COURSES_STALE_TTL = 1800

_CACHE_KEY = "canvas:courses:all"

# La cuenta tiene ~1900 cursos, así que la traída completa (sin
# search_term) tarda varios segundos reales contra Canvas. Sin esta
# traba, cada request concurrente que encuentra el caché frío (justo
# después de un deploy, por ejemplo) dispara su propia traída completa
# en simultáneo — eso fue lo que generó los "Tiempo de espera agotado"
# en cascada vistos en producción: N requests a la vez multiplicaban la
# carga contra la API de Canvas (429 Too Many Requests) y contra la
# conexión compartida a la base de datos. Un lock por clave de caché
# hace que solo UNA traída esté en vuelo por vez; el resto espera esa
# misma traída en lugar de iniciar la suya.
_fetch_locks: dict[str, asyncio.Lock] = {}


def _course_cache_key(search_term: str | None) -> str:
    return f"{_CACHE_KEY}:{search_term or ''}"


async def _fetch_all_courses(search_term: str | None = None) -> list:
    """Obtiene TODOS los cursos de Canvas (todos los estados, con info de término).

    - state[]: todos los estados (creado, reclamado, publicado, completado, eliminado)
    - include[]: term  — adjunta el objeto de período a cada curso
    - Sin límite de registros (paginate completo)
    """
    cache_key = _course_cache_key(search_term)
    lock = _fetch_locks.setdefault(cache_key, asyncio.Lock())

    async with lock:
        # Otro request pudo haber completado la traída mientras esperábamos
        # el lock — si ya está en caché, no hace falta repetirla.
        cached = _cache.get(cache_key)
        if cached is not None:
            return cached

        try:
            params: dict = {
                "per_page": 100,
                "include[]": "term",
                "state[]": _ALL_STATES,
            }
            if search_term:
                params["search_term"] = search_term
                result = await canvas.paginate_limited(f"/accounts/{_ACCOUNT}/courses", params, max_records=100)
            else:
                result = await canvas.paginate(f"/accounts/{_ACCOUNT}/courses", params)

            await database.upsert_courses(result)
            await database.mark_synced("canvas_courses")
            _cache.set(cache_key, result, ttl=_COURSES_STALE_TTL)
            logger.info(f"Cursos cargados: {len(result)} (todos los estados)")
            return result
        except Exception as exc:
            logger.error(f"Error cargando cursos: {exc}")
            return []


async def warm_courses_cache() -> None:
    """Precalienta el caché de cursos al arrancar el contenedor.

    Se llama en el lifespan de la app, sin bloquear el arranque (fire and
    forget) — para que el primer tráfico real después de un deploy ya
    encuentre el caché tibio en vez de disparar la traída completa (y la
    estampida de requests concurrentes que eso generaba) sobre el primer
    usuario que entra.
    """
    try:
        await _fetch_all_courses()
    except Exception as exc:
        logger.warning(f"No se pudo precalentar el caché de cursos: {exc}")


@router.get("", summary="Listar todos los cursos de la cuenta (todos los estados)")
async def list_courses(
    background_tasks: BackgroundTasks,
    search_term: Annotated[str | None, Query()] = None,
    # Mantenidos por compatibilidad — el filtrado real se hace en el cliente
    state: Annotated[list[str] | None, Query()] = None,
    per_page: Annotated[int, Query(ge=1, le=100)] = 100,
):
    cache_key = _course_cache_key(search_term)
    cached = _cache.get(cache_key)

    if cached is not None:
        # Stale-while-revalidate: respuesta inmediata, refresco silencioso en background
        background_tasks.add_task(_fetch_all_courses, search_term)
        return cached

    return await _fetch_all_courses(search_term)



@router.get("/by-term/{term_id}", summary="Obtener cursos por periodo académico")
async def list_courses_by_term(term_id: str, per_page: Annotated[int, Query(ge=1, le=100)] = 100):
    """Lista todos los cursos asociados a un término específico."""
    params = {
        "enrollment_term_id": term_id,
        "per_page": per_page,
        "include[]": "term",
        "state[]": _ALL_STATES,
    }
    try:
        return await canvas.paginate(f"/accounts/{_ACCOUNT}/courses", params)
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))

@router.get("/{course_id}", summary="Obtener curso por ID")
async def get_course(course_id: str):
    try:
        return await canvas.get(f"/courses/{course_id}")
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=404, detail=str(exc))





@router.put("/{course_id}", summary="Actualizar curso")
async def update_course(course_id: str, body: CanvasCourseUpdate):
    fields = body.model_dump(exclude_none=True)
    try:
        data = await canvas.put(f"/courses/{course_id}", {"course": fields})
        await database.upsert_courses([data])
        _cache.patch_list(_CACHE_KEY, course_id, data, id_field="id", action="update")
        return data
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))



from pydantic import BaseModel
from app.services import teams_client as graph

class BulkDeleteCoursesRequest(BaseModel):
    course_ids: list[str]
    delete_teams: bool = True

@router.post("/bulk-delete", summary="Eliminación masiva de cursos y equipos asociados")
async def bulk_delete_courses(req: BulkDeleteCoursesRequest):
    """
    Toma una lista de IDs de Canvas y elimina el curso en Canvas y su equipo en Teams.
    """
    result = {"succeeded": [], "failed": []}
    
    for cid in req.course_ids:
        course_name = f"Curso {cid}"
        error = ""
        # 1. Obtener nombre para buscar en Teams
        try:
            course = await canvas.get(f"/courses/{cid}")
            course_name = course.get("name", course_name)
        except Exception as e:
            error += f"Canvas Get Error: {str(e)}"
            
        # 2. Eliminar en Canvas
        if not error:
            try:
                await canvas.delete(f"/courses/{cid}", {"event": "delete"})
                await database.delete_course(cid)
                _cache.patch_list(_CACHE_KEY, cid, None, id_field="id", action="delete")
            except Exception as e:
                error += f" | Canvas Delete Error: {str(e)}"
                
        # 3. Eliminar en Teams
        if req.delete_teams:
            try:
                team_id = await graph.search_group_by_name(course_name)
                if team_id:
                    await graph.delete(f"/groups/{team_id}")
                else:
                    error += " | Teams: No se encontró el equipo asociado"
            except Exception as e:
                error += f" | Teams Delete Error: {str(e)}"
                
        if error and not error.startswith(" | Teams: No se encontró"):
            result["failed"].append({"course_id": cid, "name": course_name, "error": error.strip(" | ")})
        else:
            result["succeeded"].append({"course_id": cid, "name": course_name, "notes": error.strip(" | ")})

    return result


# ── Liberar SIS ID de cursos eliminados que bloquean su reuso ──────────────────
#
# Canvas no libera el sis_course_id cuando un curso se elimina (soft-delete):
# el curso desaparece de los listados normales, pero el SIS ID sigue
# "en uso" y bloquea crear un curso nuevo con ese mismo ID — típicamente
# al recrear una materia en un período nuevo. Esto limpia el campo SIS ID
# de esos cursos viejos (sin tocar su contenido) para liberar el ID.

class ReleaseSisIdResolveRequest(BaseModel):
    entries: list[str]


@router.post("/release-sis-id/resolve", summary="Resolver cursos por SIS ID o ID de Canvas (previsualización)")
async def resolve_release_sis_id(req: ReleaseSisIdResolveRequest):
    seen: set[str] = set()

    async def resolve_one(raw: str):
        entry = raw.strip()
        if not entry or entry in seen:
            return None
        seen.add(entry)

        course = None
        try:
            course = await canvas.get(f"/courses/sis_course_id:{entry}")
        except Exception:
            pass
        if not course:
            try:
                course = await canvas.get(f"/courses/{entry}")
            except Exception:
                pass

        if not course:
            return {"input": entry, "course_id": None, "name": None, "current_sis_id": None,
                    "workflow_state": None, "found": False}

        return {
            "input": entry,
            "course_id": str(course.get("id")),
            "name": course.get("name"),
            "current_sis_id": course.get("sis_course_id"),
            "workflow_state": course.get("workflow_state"),
            "found": True,
        }

    results = await asyncio.gather(*(resolve_one(e) for e in req.entries))
    return [r for r in results if r is not None]


class ReleaseSisIdRequest(BaseModel):
    course_ids: list[str]


@router.post("/release-sis-id", summary="Liberar el SIS ID de una lista de cursos (sin tocar su contenido)")
async def release_sis_id(req: ReleaseSisIdRequest):
    result = {"succeeded": [], "failed": []}

    for cid in req.course_ids:
        name = f"Curso {cid}"
        try:
            course = await canvas.get(f"/courses/{cid}")
            name = course.get("name", name)
        except Exception as e:
            result["failed"].append({"course_id": cid, "name": name, "error": f"No se pudo obtener el curso: {e}"})
            continue

        try:
            await canvas.put(f"/courses/{cid}", {"course": {"sis_course_id": ""}})
            result["succeeded"].append({"course_id": cid, "name": name})
        except Exception as e:
            result["failed"].append({"course_id": cid, "name": name, "error": str(e)})

    return result


@router.delete("/{course_id}", summary="Eliminar / concluir curso")
async def delete_course(
    course_id: str,
    event: Annotated[str, Query(description="delete | conclude")] = "conclude",
):
    try:
        result = await canvas.delete(f"/courses/{course_id}", {"event": event})
        await database.delete_course(course_id)
        _cache.patch_list(_CACHE_KEY, course_id, None, id_field="id", action="delete")
        return result
    except StarletteHTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))


@router.get("/{course_id}/attendance", summary="Obtener matriz de asistencias en formato planilla")
async def get_attendance(course_id: str):
    """Obtiene matriz de asistencias desde Canvas Roll Call Attendance.

    Devuelve los datos en formato planilla Excel:
    - Nombre del curso e instructor
    - Fechas de asistencia registradas
    - Matriz de estudiantes x fechas con estados de asistencia
    """
    logger.info(f"GET /courses/{course_id}/attendance - obteniendo asistencias")

    try:
        # 1. Obtener información del curso
        course = await canvas.get(f"/courses/{course_id}")
        course_name = course.get("name", f"Curso {course_id}")

        # 2. Obtener instructor del curso
        enrollments_all = await canvas.paginate_limited(
            f"/courses/{course_id}/enrollments",
            {"per_page": 100, "type": "TeacherEnrollment"},
            max_records=100
        )
        instructor_name = ""
        if enrollments_all:
            teacher = enrollments_all[0].get("user", {})
            instructor_name = teacher.get("name", "")

        # 3. Obtener estudiantes activos
        enrollments = await canvas.paginate_limited(
            f"/courses/{course_id}/enrollments",
            {"per_page": 100},
            max_records=1000
        )

        student_enrollments = [
            e for e in enrollments
            if e.get("type") == "StudentEnrollment" and
               e.get("enrollment_state") in ["active", "completed"]
        ]
        logger.info(f"Filtrados {len(student_enrollments)} estudiantes")

        students_map = {}
        student_ids = set()
        for enrollment in student_enrollments:
            user_id = enrollment.get("user_id")
            user = enrollment.get("user", {})
            if user_id:
                students_map[user_id] = {
                    "id": user_id,
                    "name": user.get("name", f"Usuario {user_id}"),
                    "login": user.get("login", ""),
                }
                student_ids.add(user_id)

        # 4. Obtener todas las asignaciones
        assignments = await canvas.paginate_limited(
            f"/courses/{course_id}/assignments",
            {"per_page": 100},
            max_records=200
        )
        logger.info(f"Obtuvo {len(assignments)} asignaciones")

        # 5. Buscar la asignación de Roll Call Attendance
        attendance_assignment = None
        for assignment in assignments:
            name = assignment.get("name", "").lower()
            if "attendance" in name or "roll call" in name or "asistencia" in name:
                attendance_assignment = assignment
                logger.info(f"Encontrada asignacion de asistencia: {assignment.get('name')} (ID: {assignment.get('id')})")
                break

        # 6. Obtener submisiones de asistencia
        attendance_by_student_date = {}  # {user_id: {date: score}}
        submission_dates = []

        if attendance_assignment:
            assignment_id = attendance_assignment.get("id")

            submissions = await canvas.paginate_limited(
                f"/courses/{course_id}/assignments/{assignment_id}/submissions",
                {"per_page": 100},
                max_records=1000
            )
            logger.info(f"Obtuvo {len(submissions)} submisiones de attendance")

            for submission in submissions:
                user_id = submission.get("user_id")
                if not user_id or user_id not in student_ids:
                    continue

                try:
                    score = submission.get("score")
                    submitted_at = submission.get("submitted_at") or submission.get("updated_at")

                    if submitted_at and isinstance(submitted_at, str):
                        date_str = submitted_at.split("T")[0]
                        if date_str not in submission_dates:
                            submission_dates.append(date_str)

                        # Guardar el score/porcentaje por estudiante y fecha
                        if str(user_id) not in attendance_by_student_date:
                            attendance_by_student_date[str(user_id)] = {}

                        attendance_by_student_date[str(user_id)][date_str] = score
                        logger.debug(f"Usuario {user_id}: score={score}% en {date_str}")
                except Exception as e:
                    logger.error(f"Error procesando submission {user_id}: {e}")

        # 7. Ordenar fechas
        attendance_dates = sorted(submission_dates) if submission_dates else []

        # 8. Construir matriz de asistencia en formato planilla
        # Formato: {student_id: {date1: status, date2: status, ...}}
        attendance_matrix = {}
        for user_id in student_ids:
            attendance_matrix[str(user_id)] = {}

            # Si tiene datos de score, convertir a estado (P/A/L/E)
            if str(user_id) in attendance_by_student_date:
                dates_data = attendance_by_student_date[str(user_id)]
                for date in attendance_dates:
                    score = dates_data.get(date)
                    if score is not None:
                        # Convertir score a estado de asistencia
                        # Usar umbral inteligente: >= 80% = Presente
                        if isinstance(score, (int, float)):
                            attendance_matrix[str(user_id)][date] = "P" if score >= 80 else "A"
                        else:
                            attendance_matrix[str(user_id)][date] = str(score)
                    else:
                        attendance_matrix[str(user_id)][date] = ""

        logger.info(f"Matriz con {len(attendance_matrix)} estudiantes x {len(attendance_dates)} fechas")

        # Formato compatible con el template del frontend
        # dates: {date_str: date_str} — objeto para Object.keys()
        # attendance: {student_id: {date: status}}
        # students: [{id, name, login}]
        dates_obj = {d: d for d in attendance_dates}

        return {
            "course": {
                "id": int(course_id),
                "name": course_name,
                "instructor": instructor_name,
            },
            "dates": dates_obj,
            "attendance": attendance_matrix,
            "students": list(students_map.values()),
            "summary": {
                "total_students": len(student_ids),
                "total_dates": len(attendance_dates),
                "has_attendance_data": bool(attendance_assignment),
            }
        }

    except StarletteHTTPException:
        raise
    except (ValueError, TypeError, KeyError) as exc:
        logger.error(f"Error en attendance (tipo incorrecto): {exc}", exc_info=True)
        raise HTTPException(status_code=400, detail=f"Error procesando datos: {str(exc)}")
    except Exception as exc:
        logger.error(f"Error inesperado en attendance: {exc}", exc_info=True)
        raise HTTPException(status_code=500, detail=f"Error obteniendo asistencias: {str(exc)}")

