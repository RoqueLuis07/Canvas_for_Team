# Arquitectura técnica

Estado real del código en `php_proyecto_integrador` a cierre de Avance 2.
Ver también la [Guía Oficial del Equipo](Guia_Equipo_Sistema_Academico.pdf)
para el resumen orientado al equipo, y el
[Informe de Avance 1](Avance_1_Informe_Planificacion.pdf) para el alcance y
el MER formalmente entregado.

## Stack

| Capa | Tecnología |
|---|---|
| Framework | Laravel 13 (PHP 8.4) |
| Panel administrativo | Filament 3.3 (`/admin`) |
| Base de datos | SQLite (desarrollo local) / MySQL 8 (stack de Portainer) |
| Sesiones / caché | Base de datos (local) / Redis (stack de Portainer) |
| Integraciones | Canvas API (REST), Microsoft Graph API (Teams / Azure AD) |

## Patrón Contract + Null-Object

`App\Contracts\CanvasClient` y `TeamsClient` son interfaces. `AppServiceProvider`
inyecta `HttpCanvasClient`/`HttpTeamsClient` (llamadas reales) solo si hay
credenciales configuradas; si no, inyecta `NullCanvasClient`/`NullTeamsClient`
(simulan la operación y la registran en el log). Esto permite ejercitar todo
el flujo académico sin depender de credenciales reales de Canvas/Azure.

## Servicios de negocio (`App\Services`)

| Servicio | Responsabilidad |
|---|---|
| `CursoService` | Crea el curso en Canvas, crea el equipo en Teams, asigna al docente titular (profesor en Canvas, propietario en Teams). |
| `InscripcionMateriaService` | Matricula/da de baja/cambia a un alumno de curso. Diferencia alta predefinida (alumno nuevo, admitido por postulación) de alta manual (alumno que continúa, sujeta a prerrequisitos y cupo). |
| `MaterialService` | Publica contenido de un curso como página en Canvas. |
| `SincronizacionService` | Envuelve cada operación real contra Canvas/Teams: registra un `JobSincronizacion` (pendiente → en_proceso → completado/error) y un `LogAuditoria`. |

## Modelo de datos actual

```
Rol 1─N Persona
Subcuenta 1─N Curso
Programa 1─N PlanEstudio 1─N Materia N─N Materia (prerrequisitos)
PlanEstudio 1─N Matricula N─1 Persona
PeriodoAcademico 1─N Curso N─1 Materia
Curso 1─1 EquipoTeams
EquipoTeams 1─N MiembroEquipo N─1 Persona
Curso 1─N InscripcionMateria N─1 Matricula
Curso 1─N Material
Curso N─1 Persona (docente)
Postulacion N─1 Persona, N─1 Programa, N─1 PeriodoAcademico
JobSincronizacion, LogAuditoria — independientes, referencian al usuario (User) que disparó la operación
```

| Entidad | Qué representa |
|---|---|
| `Rol` | Rol institucional (Admin TI, Docente, Estudiante) — MER: `ROL`. |
| `Persona` | Cualquier actor: aspirante, alumno, docente o administrativo (`tipo`, `rol_id`, `email_institucional`, `estado`, `canvas_user_id`, `azure_user_id`) — MER: `USUARIO`. |
| `Subcuenta` | Subcuenta/facultad de Canvas (`canvas_account_id`, `carrera`, `sede`) — MER: `SUBCUENTA`. |
| `Programa` | Programa académico (ej. "Ingeniería en Informática") — ampliación fuera del MER formal. |
| `PlanEstudio` | Versión del plan curricular de un Programa — ampliación. |
| `Materia` | Definición curricular (código, semestre sugerido, prerrequisitos) — ampliación. |
| `PeriodoAcademico` | Período lectivo (ej. "2026-2"), con estado de inscripciones — ampliación. |
| `Postulacion` | Postulación/admisión de un aspirante a un Programa en un Período — ampliación. |
| `Matricula` | Vínculo de una Persona con un PlanEstudio en un Período — se origina o no en una Postulación admitida — ampliación (matrícula por plan, no por curso). |
| `Curso` | Oferta concreta de una Materia en un Período, con `subcuenta_id` — lo que se crea como curso en Canvas — MER: `CURSO`. |
| `InscripcionMateria` | Alta de una Matricula en un Curso, con alta/baja automática en Canvas/Teams (origen `manual` o `predefinida`) — MER: `MATRICULA` (el nombre `InscripcionMateria` se mantuvo porque `Matricula` ya estaba tomado por la entidad de plan de estudio, más amplia). |
| `EquipoTeams` | Equipo de Microsoft Teams asociado 1:1 a un Curso (`teams_group_id`, `visibilidad`, `fecha_creacion`) — MER: `EQUIPO_TEAMS`. |
| `MiembroEquipo` | Membresía de una Persona en un EquipoTeams (`rol_teams`: Owner/Member, `fecha_alta`) — MER: `MIEMBRO_EQUIPO`. |
| `Material` | Contenido académico de un Curso, publicable como página de Canvas — ampliación. |
| `JobSincronizacion` | Ciclo de vida de cada operación de sincronización disparada (tipo, estado, resultado) — MER: `JOB_SINCRONIZACION`. |
| `LogAuditoria` | Historial de auditoría: usuario, acción, entidad afectada, fecha — MER: `LOG_AUDITORIA`. |

### Alineación con el MER de Avance 1

Las nueve entidades del MER entregado en Avance 1 (`ROL`, `USUARIO`,
`SUBCUENTA`, `CURSO`, `MATRICULA`, `EQUIPO_TEAMS`, `MIEMBRO_EQUIPO`,
`JOB_SINCRONIZACION`, `LOG_AUDITORIA`) están todas implementadas, como
tabla y modelo propios, en `php_proyecto_integrador`:

| Entidad MER (Avance 1) | Tabla / Modelo Laravel |
|---|---|
| `ROL` | `roles` / `App\Models\Rol` |
| `USUARIO` | `personas` / `App\Models\Persona` |
| `SUBCUENTA` | `subcuentas` / `App\Models\Subcuenta` |
| `CURSO` | `cursos` / `App\Models\Curso` |
| `MATRICULA` | `inscripcion_materias` / `App\Models\InscripcionMateria` |
| `EQUIPO_TEAMS` | `equipo_teams` / `App\Models\EquipoTeams` |
| `MIEMBRO_EQUIPO` | `miembro_equipos` / `App\Models\MiembroEquipo` |
| `JOB_SINCRONIZACION` | `job_sincronizacions` / `App\Models\JobSincronizacion` |
| `LOG_AUDITORIA` | `log_auditorias` / `App\Models\LogAuditoria` |

`EquipoTeams` y `MiembroEquipo` se completan automáticamente desde
`CursoService` (al crear el equipo en Teams, y al asignar al docente como
`Owner`) y desde `InscripcionMateriaService` (al matricular/dar de baja a
un alumno, como `Member`) — no son formularios manuales aislados, reflejan
el mismo flujo de sincronización real.

El modelo sigue siendo **más amplio** que el MER formal: conserva el
módulo de autoservicio académico (`Programa`, `PlanEstudio`, `Materia`,
`Postulacion`, `Matricula` como matrícula por plan de estudio) construido
en el Avance 2, que excede el alcance de Avance 1 pero no lo contradice —
las nueve entidades formales existen y operan dentro de él. Esta decisión
(ampliar en vez de reemplazar) se mantuvo explícitamente para no arriesgar
la estabilidad de lo ya entregado.

## Despliegue

- **Local / desarrollo:** `php artisan serve` + SQLite, sin Docker.
- **Portainer (Avance 2):** `docker-compose.yml` — contenedores `app`
  (Laravel + PHP-FPM, imagen propia vía `docker/php/Dockerfile`),
  `webserver` (Nginx, imagen propia vía `docker/nginx/Dockerfile`), `db`
  (MySQL 8), `redis`. Código y `vendor/` viven en un volumen Docker
  nombrado (`app_code`) poblado automáticamente desde la imagen — no es un
  bind-mount del host, para que el despliegue sea reproducible desde
  cualquier clon del repositorio.
- **Railway:** despliegue alternativo de un solo contenedor
  (`Dockerfile` + `railway.json` en la raíz de `sistema-academico/`), con
  `php artisan serve` directo — usado para tener una URL pública rápida
  antes de tener el stack de Portainer funcionando.
