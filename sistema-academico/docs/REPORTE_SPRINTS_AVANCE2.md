# Reporte de Sprints — Avance 2

Entregable exigido por la cátedra para Avance 2: "Reporte de Sprints / Flujo
Kanban acumulado" (Proyecto Integrador, Programación IV, sección 8).

## Nota metodológica

El equipo adoptó Scrum desde Avance 1 (tablero Trello "Sync USIL — Canvas ↔
Teams", Product Backlog con HU-01 a HU-06 — ver informe de Avance 1,
sección 4). Durante el desarrollo de este Avance 2 **no se realizaron
ceremonias ágiles formales con acta** (Daily Standup, Sprint Review y
Sprint Retrospective tal como estaban previstas en la sección 4.4 del
informe de Avance 1).

Este reporte reconstruye el Sprint 3 — y el cierre adicional que se hizo
para dejar el Avance 2 consolidado — **a partir del historial real de
commits de `sistema-academico/`** en GitHub (rama
`php_proyecto_integrador`), que es evidencia verificable, fechada y
pública de qué se hizo y cuándo. Se prefiere esta reconstrucción honesta
antes que redactar actas de reuniones que no ocurrieron.

**Acción correctiva para Avance 3:** retomar las ceremonias formales
(Daily Standup semanal resumido, Sprint Review y Retrospective por hito)
según lo ya definido en el informe de Avance 1, y dejar su evidencia en
`/docs/ceremonias` como estaba originalmente previsto.

## Sprint 3 — Auditoría, bajas combinadas y despliegue

**Objetivo del Sprint** (según Sprint Planning de Avance 1): completar
HU-05 (auditoría) y HU-06 (bajas combinadas), y dejar el sistema
desplegado en Portainer.

**Historias cubiertas:**

| HU | Historia | Evidencia (commit) |
|---|---|---|
| HU-05 | Historial de auditoría de sincronizaciones | `0b1cdc4` — JobSincronizacion y LogAuditoria del MER de Avance 1 |
| HU-06 | Baja combinada de usuario en Canvas y Teams desde una sola pantalla | `9a0240c` — asignación de curso + alta/baja automática en Canvas y Teams (scaffold inicial) |

**Trabajo adicional del Sprint** (fuera del backlog original de Avance 1,
necesario para dejar el Avance 2 completo y entregable):

- Gestión de docentes y materiales por curso (`7d2ed45`).
- Despliegue del stack en Portainer — Avance 2 lo exige explícitamente
  como entregable (ver tabla de abajo).
- Alineación del modelo de datos con el MER exacto de Avance 1 (entidades
  `Rol`, `Subcuenta`, `EquipoTeams`, `MiembroEquipo` — `d967721`).
- Documentación completa del proyecto en el repositorio.
- Mejora de interfaz (identidad visual USIL, navegación agrupada —
  `91916be`).

### Avance día a día (reconstruido del historial de commits)

| Fecha | Qué se entregó |
|---|---|
| 12/09 | Scaffold del sistema en Laravel + Filament; servicio de asignación de curso con alta/baja automática en Canvas y Teams (HU-06); primer intento de despliegue público en Railway (contenedor único) para tener una URL de demo rápida antes de tener Portainer funcionando. |
| 25/09 | Gestión de docentes y materiales por curso, integrados a Canvas/Teams — *fecha límite oficial de Avance 2*. |
| 29/09 | Auditoría: `JobSincronizacion` y `LogAuditoria` del MER de Avance 1 (HU-05). |
| 02/10 | Consolidación final: 5 bugs reales de despliegue en Portainer encontrados y corregidos en secuencia (Dockerfile sin `ext-intl`/`ext-mbstring`, volumen que tapaba `vendor/`, permisos de `storage/` para `www-data`, `fakerphp/faker` excluido del build de producción), branding del panel, documentación completa (Arquitectura, Changelog, Guía, Evidencia de presentación), alineación del modelo de datos con las 9 entidades del MER de Avance 1, y mejora de interfaz. |

> Nota: entre el 25/09 (fecha límite oficial) y el 02/10 hubo una brecha de
> trabajo de cierre — el despliegue en Portainer, la documentación y la
> alineación del MER se terminaron después de la fecha límite nominal,
> antes de esta revisión. Transparencia total: no se ocultó ni se
> retrocedió la fecha de los commits.

## Sprint Review

Funcionalidad demostrable al cierre de este Sprint (ver también
`EVIDENCIA_PRESENTACION.md` para el detalle de cómo mostrar cada una):

- Login institucional (`/admin`).
- Listado de cursos con estado de sincronización Canvas/Teams.
- Crear curso en Canvas / crear equipo en Teams con un clic.
- Asignar docente titular (alta como profesor en Canvas + propietario en
  Teams).
- Matricular/dar de baja un alumno en Canvas y Teams desde una sola
  acción.
- Historial de auditoría (`LogAuditoria`) y de jobs de sincronización
  (`JobSincronizacion`), navegables en el panel.
- Las 9 entidades del MER de Avance 1 visibles y operando en el panel.
- Stack completo corriendo en Portainer (Laravel + Nginx + MySQL +
  Redis), accesible públicamente.

## Sprint Retrospective

| Qué funcionó | Qué mejorar | Acciones para Avance 3 |
|---|---|---|
| El patrón Contract + Null-Object permitió desarrollar y probar todo el flujo académico sin depender de credenciales reales de Canvas/Azure — ningún bloqueo de "no tengo el token". | El despliegue en Portainer tardó más de lo esperado: 5 bugs reales en secuencia (extensiones de PHP, volúmenes, permisos, dependencias de build) que solo aparecen en el entorno de contenedores, no en local. | Documentar un checklist de verificación de despliegue (ya cubierto por `.env.portainer.example` y la guía paso a paso) para no repetir la misma curva de aprendizaje en Avance 3 con HAProxy. |
| El modelo de datos ampliado (autoservicio académico) no tuvo que rehacerse para alinear con el MER formal de Avance 1 — se sumaron las entidades que faltaban sin romper nada ya construido. | No se sostuvieron las ceremonias ágiles formales (standup/review/retro) durante el sprint — este mismo reporte es la evidencia de esa falta. | Retomar el registro semanal en `/docs/ceremonias` desde el inicio de Avance 3, no al cierre. |
| Los tests (32/32) y el formateo de estilo (Pint) se mantuvieron verdes durante todo el sprint, incluso agregando 4 entidades nuevas. | — | Mantener la misma disciplina de verificación antes de cada commit. |
