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
Programa 1─N PlanEstudio 1─N Materia N─N Materia (prerrequisitos)
PlanEstudio 1─N Matricula N─1 Persona
PeriodoAcademico 1─N Curso N─1 Materia
Curso 1─N InscripcionMateria N─1 Matricula
Curso 1─N Material
Curso N─1 Persona (docente)
Postulacion N─1 Persona, N─1 Programa, N─1 PeriodoAcademico
JobSincronizacion, LogAuditoria — independientes, referencian al usuario (User) que disparó la operación
```

| Entidad | Qué representa |
|---|---|
| `Persona` | Cualquier actor: aspirante, alumno, docente o administrativo (`tipo`, `canvas_user_id`, `azure_user_id`). |
| `Programa` | Programa académico (ej. "Ingeniería en Informática"). |
| `PlanEstudio` | Versión del plan curricular de un Programa. |
| `Materia` | Definición curricular (código, semestre sugerido, prerrequisitos). |
| `PeriodoAcademico` | Período lectivo (ej. "2026-2"), con estado de inscripciones. |
| `Postulacion` | Postulación/admisión de un aspirante a un Programa en un Período. |
| `Matricula` | Vínculo de una Persona con un PlanEstudio en un Período — se origina o no en una Postulación admitida. |
| `Curso` | Oferta concreta de una Materia en un Período — lo que se crea como curso en Canvas y equipo en Teams. |
| `InscripcionMateria` | Alta de una Matricula en un Curso, con alta/baja automática en Canvas/Teams (origen `manual` o `predefinida`). |
| `Material` | Contenido académico de un Curso, publicable como página de Canvas. |
| `JobSincronizacion` | Ciclo de vida de cada operación de sincronización disparada (tipo, estado, resultado). |
| `LogAuditoria` | Historial de auditoría: usuario, acción, entidad afectada, fecha. |

> **Nota:** el MER entregado en Avance 1 (`ROL`, `USUARIO`, `SUBCUENTA`,
> `CURSO`, `MATRICULA`, `EQUIPO_TEAMS`, `MIEMBRO_EQUIPO`,
> `JOB_SINCRONIZACION`, `LOG_AUDITORIA`) es más acotado que el modelo actual
> — este último creció con un módulo de autoservicio académico (postulación,
> planes de estudio, materias) que excede ese alcance formal. La
> realineación al MER exacto de Avance 1 está identificada como pendiente
> (ver Guía Oficial del Equipo, sección 8) y no se ejecutó todavía para no
> arriesgar la estabilidad del Avance 2 ya entregado.

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
