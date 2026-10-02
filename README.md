# Proyecto Integrador — Programación IV — Grupo N.° 3

## Sistema Académico USIL: integración Canvas LMS ↔ Microsoft Teams

Panel administrativo que centraliza y automatiza la sincronización entre
**Canvas LMS** (gestión académica) y **Microsoft Teams** (identidad y
colaboración) para USIL Paraguay: alta de usuarios, creación de
cursos/equipos, matriculación combinada y auditoría de cada operación.

> ⚠️ **Rama de trabajo del equipo: `php_proyecto_integrador`.** El
> proyecto en sí vive en la carpeta **[`sistema-academico/`](sistema-academico/)**.
> El resto de los archivos en la raíz de esta rama (`Backend/`,
> `Frontend/`, etc.) pertenece a un **proyecto distinto y no
> relacionado** — un sistema en producción para USIL Paraguay en
> FastAPI, heredado automáticamente al crear esta rama desde `main` —
> y no forma parte de este trabajo ni hace falta revisarlo.

---

## Índice

1. [Equipo](#1-equipo-grupo-n-3)
2. [Problema que resuelve, paso a paso](#2-problema-que-resuelve-paso-a-paso)
3. [Arquitectura técnica, explicada](#3-arquitectura-técnica-explicada)
4. [Modelo de datos, entidad por entidad](#4-modelo-de-datos-entidad-por-entidad)
5. [Flujo de uso del sistema, paso a paso](#5-flujo-de-uso-del-sistema-paso-a-paso)
6. [Cómo levantarlo en local, paso a paso](#6-cómo-levantarlo-en-local-paso-a-paso)
7. [Cómo está desplegado, paso a paso](#7-cómo-está-desplegado-paso-a-paso)
8. [Qué deben saber los docentes](#8-qué-deben-saber-los-docentes)

---

## 1. Equipo (Grupo N.° 3)

| Rol | Integrante | C.I. | Responsabilidad |
|---|---|---|---|
| Scrum Master / Project Manager | Roque Esteche | 6.868.066 | Metodología ágil, coordinación del equipo, implementación consolidada, control de alcance. |
| Backend & Software Architect Lead | Hypatia García | 5.444.430 | Lógica de negocio en Laravel, arquitectura de datos, migraciones, diseño de la API/servicios. |
| DevOps & Cloud Engineer | María Iglesias | 7.330.668 | Dockerfile, docker-compose.yml, stack en Portainer. |
| Frontend & QA Engineer | Jesús Domínguez | 5.666.290 | Vistas del panel, pruebas funcionales. |
| Frontend & QA Engineer | Alfonzo Martínez | 4.826.141 | Vistas del panel, pruebas funcionales. |
| Frontend & QA Engineer | Claudio Ortigoza | 3.770.718 | Vistas del panel, pruebas funcionales. |

**Caso de uso:** el equipo adaptó la consigna de la cátedra (PyME o
Empresa de Paraguay) a un caso institucional real — USIL Paraguay — en
vez de un caso PyME hipotético, por tratarse de una problemática
operativa real y ya identificada. Esta decisión fue comunicada y **está
aceptada por el docente**.

## 2. Problema que resuelve, paso a paso

USIL Paraguay administra dos plataformas que hoy **no están
integradas entre sí**: Canvas LMS (cursos, matrículas, calificaciones) y
Microsoft 365/Teams (identidad institucional, correo, espacios de
colaboración). Esto obliga al personal de TI a repetir, manualmente, el
mismo trabajo dos veces por cada persona y cada curso:

1. Dar de alta a un usuario (estudiante o docente) en Canvas **y** en
   Azure Active Directory por separado.
2. Crear el equipo de Microsoft Teams correspondiente a cada curso
   publicado en Canvas, a mano.
3. Matricular al usuario en el curso de Canvas, y por separado, darlo de
   alta como miembro del Team.
4. Dar de baja al usuario en ambas plataformas al finalizar un período o
   egreso.
5. Llevar un registro de auditoría de todo esto, hoy disperso entre
   correos, planillas y el historial nativo de cada plataforma por
   separado.

Esto genera inconsistencias reales (alguien matriculado en Canvas sin
acceso al Team del curso, o viceversa), demoras, y cero trazabilidad
centralizada. El sistema resuelve cada uno de esos 5 puntos con una
acción desde un único panel, dejando además un registro auditable de
cada operación.

## 3. Arquitectura técnica, explicada

| Capa | Tecnología | Por qué |
|---|---|---|
| Framework | Laravel 13 (PHP 8.4) | Exigencia de la cátedra; ORM (Eloquent) y ecosistema de administración (Filament) maduro. |
| Panel administrativo | Filament 3.3 (`/admin`) | Genera CRUDs y formularios de administración rápido y consistente, sobre Laravel. |
| Base de datos | SQLite (desarrollo local) / MySQL 8 (Portainer) | SQLite no requiere instalar nada para desarrollar; MySQL es el motor relacional de producción exigido. |
| Sesiones / caché | Base de datos (local) / Redis (Portainer) | Redis centraliza sesiones entre contenedores — necesario si en Avance 3 hay más de un nodo de aplicación detrás de HAProxy. |
| Integraciones | Canvas API (REST), Microsoft Graph API (Teams/Azure AD) | Las dos plataformas reales que el sistema sincroniza. |

### Patrón Contract + Null-Object — paso a paso de cómo funciona

1. `App\Contracts\CanvasClient` y `TeamsClient` son **interfaces** PHP:
   definen qué operaciones existen (crear curso, matricular, crear
   equipo, etc.) sin decir cómo se ejecutan.
2. Hay dos implementaciones de cada una:
   - `HttpCanvasClient` / `HttpTeamsClient` — hacen la llamada HTTP real
     a Canvas/Microsoft Graph.
   - `NullCanvasClient` / `NullTeamsClient` — no llaman a ningún
     servicio externo: simulan el resultado (devuelven un ID falso
     consistente) y lo dejan registrado en el log.
3. `AppServiceProvider` decide **en el arranque de la aplicación** cuál
   de las dos inyectar, mirando si hay credenciales de Canvas/Azure
   configuradas en el `.env`. Si no hay, usa las `Null*`.
4. Resultado práctico: **todo el flujo académico se puede desarrollar,
   probar y hacer una demo completa sin depender de un token real de
   Canvas ni de una app registration de Azure.** Nadie del equipo
   necesitó credenciales reales para avanzar.

### Servicios de negocio (`App\Services`) — qué hace cada uno

| Servicio | Responsabilidad paso a paso |
|---|---|
| `CursoService` | 1) Crea el curso en Canvas (`crearEnCanvas`). 2) Crea el equipo en Teams (`crearEnTeams`). 3) Asigna al docente titular: lo matricula como profesor en Canvas y lo agrega como propietario (`Owner`) del equipo en Teams (`asignarDocente`). |
| `InscripcionMateriaService` | 1) Valida que el curso ya exista en Canvas y Teams. 2) Valida prerrequisitos y cupo. 3) Matricula al alumno en Canvas y lo agrega como miembro (`Member`) del equipo en Teams, en una sola acción (`inscribirManual`). También maneja bajas (`darDeBaja`) y cambios de curso. |
| `MaterialService` | Publica contenido de un curso como página de Canvas. |
| `SincronizacionService` | Envuelve **cada** llamada real a Canvas/Teams de los servicios de arriba: antes de ejecutar, crea un `JobSincronizacion` en estado `pendiente`; durante, lo pasa a `en_proceso`; al terminar, a `completado` o `error`. En paralelo, registra un `LogAuditoria` con quién disparó la operación, sobre qué entidad, y cuándo. |

## 4. Modelo de datos, entidad por entidad

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

Las **9 entidades exactas del MER formal de Avance 1** están implementadas
como tabla y modelo propios de Laravel:

| Entidad MER (Avance 1) | Tabla / Modelo Laravel | Qué representa |
|---|---|---|
| `ROL` | `roles` / `Rol` | Rol institucional: Admin TI, Docente, Estudiante. |
| `USUARIO` | `personas` / `Persona` | Cualquier actor del sistema — aspirante, alumno, docente o administrativo — con su `rol_id`, `email_institucional`, `estado`, `canvas_user_id` y `azure_user_id`. |
| `SUBCUENTA` | `subcuentas` / `Subcuenta` | Subcuenta/facultad de Canvas (`canvas_account_id`, `carrera`, `sede`). |
| `CURSO` | `cursos` / `Curso` | Oferta concreta de una materia en un período, con su `subcuenta_id` — lo que se crea como curso real en Canvas. |
| `MATRICULA` | `inscripcion_materias` / `InscripcionMateria` | Alta de una persona en un curso, con alta/baja automática en Canvas y Teams (`manual` o `predefinida`). El nombre de clase quedó `InscripcionMateria` porque `Matricula` ya estaba tomado por una entidad más amplia (ver abajo). |
| `EQUIPO_TEAMS` | `equipo_teams` / `EquipoTeams` | Equipo de Microsoft Teams asociado 1 a 1 a un curso (`teams_group_id`, `visibilidad`, `fecha_creacion`). |
| `MIEMBRO_EQUIPO` | `miembro_equipos` / `MiembroEquipo` | Membresía de una persona en un equipo de Teams (`rol_teams`: `Owner`/`Member`, `fecha_alta`). Se completa automáticamente, no es un formulario manual. |
| `JOB_SINCRONIZACION` | `job_sincronizacions` / `JobSincronizacion` | Ciclo de vida de cada operación disparada contra Canvas/Teams (`pendiente` → `en_proceso` → `completado`/`error`). |
| `LOG_AUDITORIA` | `log_auditorias` / `LogAuditoria` | Historial de auditoría: usuario, acción, entidad afectada, fecha. |

### Entidades de ampliación (más allá del MER formal)

El equipo construyó, además, un módulo de autoservicio académico que
**no reemplaza** las 9 entidades de arriba, sino que las usa por dentro:

| Entidad | Qué representa | Por qué se agregó |
|---|---|---|
| `Programa` | Programa académico (ej. "Ingeniería en Informática"). | Para poder modelar postulaciones y planes de estudio reales, no solo cursos sueltos. |
| `PlanEstudio` | Versión del plan curricular de un `Programa`. | Un programa cambia de plan de estudio con los años; esto lo versiona. |
| `Materia` | Definición curricular: código, semestre sugerido, prerrequisitos. | Separa la definición curricular (`Materia`) de su oferta concreta en un período (`Curso`). |
| `PeriodoAcademico` | Período lectivo (ej. "2026-2"), con estado de inscripciones. | Un mismo curso se repite período a período; esto evita duplicar la materia cada vez. |
| `Postulacion` | Postulación/admisión de un aspirante a un `Programa` en un período. | Modela el ingreso real de un alumno nuevo, no solo la matrícula. |
| `Matricula` | Vínculo de una persona con un `PlanEstudio` en un período (se origina o no en una `Postulacion` admitida). | Distingue "alumno nuevo" (cursos del primer semestre predefinidos) de "alumno que continúa" (elige sus cursos, sujeto a prerrequisitos). |
| `Material` | Contenido académico de un curso, publicable como página de Canvas. | Extensión directa de la gestión de curso. |

**Por qué conviven las dos cosas:** el MER formal de Avance 1 alcanza
para la problemática central (sincronización Canvas↔Teams). El equipo
decidió **ampliar en vez de reemplazar** — construir el módulo de
autoservicio académico sin tocar las 9 entidades formales — para no
arriesgar la estabilidad de lo ya entregado en Avance 1.

## 5. Flujo de uso del sistema, paso a paso

Este es el recorrido real que hace un administrador de TI en el panel:

1. **Login** en `/admin` con una cuenta institucional.
2. **Listado de cursos** (`/admin/cursos`): ve todos los cursos, con
   columnas que indican con un ícono si ya están creados en Canvas y en
   Teams.
3. **Crear en Canvas**: sobre un curso sin crear, un clic en "Crear en
   Canvas" dispara `CursoService::crearEnCanvas`. Queda registrado el
   `canvas_course_id` y un `JobSincronizacion`.
4. **Crear en Teams**: un clic en "Crear en Teams" dispara
   `CursoService::crearEnTeams`. Se crea la fila en `equipo_teams` con
   el `teams_group_id`.
5. **Asignar docente**: se elige un docente de la lista; el sistema lo
   matricula como profesor en Canvas y lo agrega como `Owner` del
   equipo de Teams, en una sola acción.
6. **Matricular alumnos**: desde "Alta en materias", se busca al alumno
   y al curso; el sistema valida que no esté ya matriculado, valida
   cupo y prerrequisitos, y lo da de alta en Canvas **y** en Teams como
   `Member`, en un solo paso.
7. **Baja**: desde la misma pantalla, dar de baja a un alumno lo
   desmatricula de Canvas y lo remueve del equipo de Teams a la vez.
8. **Auditoría**: en cualquier momento, "Jobs de sincronización" y
   "Auditoría" muestran el historial completo de qué se hizo, quién lo
   hizo, y si tuvo éxito o error.

## 6. Cómo levantarlo en local, paso a paso

```bash
# 1. Clonar el repositorio
git clone https://github.com/RoqueLuis07/Canvas_for_Team.git
cd Canvas_for_Team

# 2. Pararse en la rama del proyecto integrador
git checkout php_proyecto_integrador
cd sistema-academico

# 3. Instalar las dependencias PHP (Laravel, Filament, etc.)
composer install

# 4. Crear el archivo de configuración local
cp .env.example .env

# 5. Generar la clave de encriptación de la aplicación (APP_KEY)
php artisan key:generate

# 6. Crear las tablas y cargar datos de ejemplo (seeder)
php artisan migrate:fresh --seed

# 7. Levantar el servidor de desarrollo
php artisan serve
```

Con eso, el panel queda en `http://localhost:8000/admin`. Login de
prueba creado por el seeder: `admin@usil.edu.py` / `password`. No hace
falta ninguna credencial real de Canvas ni de Azure — el paso 4 deja
esos campos vacíos y el sistema usa los clientes simulados
automáticamente (ver sección 3).

Para correr los tests y verificar el estilo del código antes de
cualquier cambio:

```bash
php artisan test --compact                 # 32/32 tests
vendor/bin/pint --dirty --format agent      # estilo de código
```

## 7. Cómo está desplegado, paso a paso

**Stack de Portainer (Avance 2)** — `sistema-academico/docker-compose.yml`:

1. **`app`** — contenedor de Laravel + PHP-FPM, construido con imagen
   propia (`docker/php/Dockerfile`).
2. **`webserver`** — Nginx, también con imagen propia
   (`docker/nginx/Dockerfile`), expuesto en el puerto `8080`.
3. **`db`** — MySQL 8, con los datos en un volumen persistente.
4. **`redis`** — sesiones y caché centralizadas.

El código de la aplicación (incluyendo `vendor/`, generado durante el
build de la imagen) vive en un **volumen Docker nombrado** (`app_code`),
compartido entre `app` y `webserver` — no es un bind-mount del host, así
que el despliegue es reproducible clonando el repositorio desde cero en
cualquier servidor con Portainer.

Pasos para desplegarlo desde cero en Portainer:

1. Portainer → **Stacks** → **Add stack** → método de build
   **Repository**.
2. Repository URL: `https://github.com/RoqueLuis07/Canvas_for_Team.git`,
   referencia `refs/heads/php_proyecto_integrador`, compose path
   `sistema-academico/docker-compose.yml`.
3. Cargar las variables de entorno de
   `sistema-academico/.env.portainer.example` en "Environment
   variables" del stack (`APP_KEY`, contraseñas de MySQL; las de
   Canvas/Azure pueden dejarse vacías para usar los clientes
   simulados).
4. **Deploy the stack.** El `entrypoint.sh` del contenedor `app` genera
   el `APP_KEY` si falta, espera a que MySQL esté listo (reintenta
   hasta 15 veces), corre las migraciones, y recién ahí arranca
   `php-fpm`.

**Railway** — despliegue alternativo de un solo contenedor, usado para
tener una URL pública rápida de demo antes de tener el stack de
Portainer funcionando.

## 8. Qué deben saber los docentes

### Alineación con la consigna oficial del Proyecto Integrador

| Requisito de la cátedra | Estado |
|---|---|
| Laravel + MySQL/PostgreSQL + Docker/Portainer | ✅ Laravel 13 + MySQL + Portainer desplegado. |
| HAProxy, dos arquitecturas activas | Corresponde a **Avance 3** — no exigido todavía. |
| Tablero ágil digital (Scrum/Kanban) | ✅ Trello ("Sync USIL — Canvas ↔ Teams"), con Product Backlog (HU-01 a HU-06). |
| Declaración de Alcance + MER/DER + Diccionario de Datos + 3FN | ✅ Entregado en el informe de Avance 1. |
| Caso de uso: PyME o Empresa de Paraguay | USIL Paraguay (institución, no PyME) — desviación explícita, **comunicada y aceptada por el docente**. |
| Reporte de Sprints / evidencia de ceremonias (Avance 2) | ✅ `docs/REPORTE_SPRINTS_AVANCE2.md`, con nota metodológica honesta sobre cómo se reconstruyó. |
| Módulo CRUD funcional con reglas de negocio (Avance 2) | ✅ Servicios de negocio (sección 3) operando de punta a punta. |
| Sesiones centralizadas (Avance 2) | ✅ Redis. |
| Despliegue inicial en Portainer (Avance 2) | ✅ Verificado en vivo. |

### Cómo verificar cada cosa, en orden

1. **Clonar y levantar en local** (sección 6) — 5 minutos, sin
   credenciales.
2. **Entrar a `/admin`** y recorrer el flujo de uso (sección 5): crear
   curso en Canvas/Teams, asignar docente, matricular un alumno, ver el
   log de auditoría.
3. **Ver las 9 entidades del MER** navegables en el panel (menú
   agrupado: Identidad académica, Oferta académica, Matriculación,
   Canvas ↔ Teams, Auditoría).
4. **Correr `php artisan test --compact`** — 32/32 tests en verde.
5. **Revisar el historial de commits** de `sistema-academico/` en
   GitHub — es la evidencia fechada y verificable de todo el desarrollo
   (ver `docs/REPORTE_SPRINTS_AVANCE2.md` para el detalle día por día).
6. **Ver el stack desplegado en Portainer** (URL pública a cargo del
   equipo) para confirmar que corre fuera del entorno local.

### Documentación completa (`sistema-academico/docs/`)

- **[Avance_1_Informe_Planificacion.pdf](sistema-academico/docs/Avance_1_Informe_Planificacion.pdf)** — informe oficial de Avance 1 ya entregado.
- **[ARQUITECTURA.md](sistema-academico/docs/ARQUITECTURA.md)** — versión detallada de las secciones 3 y 4 de este README.
- **[CHANGELOG_AVANCE2.md](sistema-academico/docs/CHANGELOG_AVANCE2.md)** — qué se agregó en Avance 2 sobre Avance 1.
- **[REPORTE_SPRINTS_AVANCE2.md](sistema-academico/docs/REPORTE_SPRINTS_AVANCE2.md)** — Sprint Review y Retrospective de Avance 2.
- **[Guia_Equipo_Sistema_Academico.pdf](sistema-academico/docs/Guia_Equipo_Sistema_Academico.pdf)** — guía oficial para el equipo.
- **[EVIDENCIA_PRESENTACION.md](sistema-academico/docs/EVIDENCIA_PRESENTACION.md)** — este mismo checklist, versión ampliada.
- **[PROMPT_DISEÑO_INTERFAZ.md](sistema-academico/docs/PROMPT_DISEÑO_INTERFAZ.md)** — prompt para iterar la interfaz con herramientas de diseño con IA.
- **[ceremonias/](sistema-academico/docs/ceremonias/)** — evidencia de ceremonias ágiles (Avance 3).
