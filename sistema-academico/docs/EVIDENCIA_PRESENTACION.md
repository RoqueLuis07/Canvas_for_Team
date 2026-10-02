# Guía de evidencia para la presentación (Avance 1 + Avance 2)

Checklist de lo que hay que mostrar al docente y dónde encontrarlo. Todo
vive en la rama **`php_proyecto_integrador`** del repositorio
`https://github.com/RoqueLuis07/Canvas_for_Team`.

## 1. Repositorio y documentación

- Rama de trabajo: `php_proyecto_integrador`.
- `README.md` — punto de entrada, arquitectura, puesta en marcha.
- `docs/Avance_1_Informe_Planificacion.pdf` — informe oficial de Avance 1
  (alcance, MER, metodología) ya entregado.
- `docs/ARQUITECTURA.md` — estado real del código, con la tabla de
  alineación exacta MER (Avance 1) ↔ implementación (sección "Alineación
  con el MER de Avance 1").
- `docs/CHANGELOG_AVANCE2.md` — qué se agregó en Avance 2 sobre Avance 1.
- `docs/Guia_Equipo_Sistema_Academico.pdf` — guía para el equipo (reglas
  de la rama, cómo levantar el proyecto).

## 2. Modelo de datos (MER) — mostrar que está completo

Las nueve entidades del diccionario de datos del Avance 1 existen como
tabla y modelo Eloquent propios. Para mostrarlo en vivo:

1. Entrar a `/admin` con `admin@usil.edu.py` / `password`.
2. En el menú lateral están disponibles: **Roles**, **Personas**
   (= USUARIO), **Subcuentas**, **Cursos (Canvas/Teams)**, **Alta en
   materias** (= MATRICULA), **Equipo Teams**, **Miembro Equipos**, **Jobs
   de sincronización**, **Auditoría** — las nueve entidades del MER,
   navegables y con datos de ejemplo cargados por el seeder.
3. Alternativa sin levantar el panel: `php artisan migrate:fresh --seed`
   y luego `php artisan tinker` para consultar cualquiera de los modelos
   (`\App\Models\EquipoTeams::with('miembros.persona')->get()`, por
   ejemplo) y mostrar que las relaciones están pobladas.

Mapeo exacto entidad MER → tabla/modelo: ver `docs/ARQUITECTURA.md`,
sección "Alineación con el MER de Avance 1".

## 3. Historias de usuario (HU-01 a HU-06, Avance 1) — evidencia funcional

| HU | Qué pide | Dónde se ve |
|---|---|---|
| HU-01 | Login institucional | `/admin/login` — Filament, sesión con expiración por inactividad (config de sesión Laravel). |
| HU-02 | Listado de cursos sincronizados, con estado | `/admin/cursos` — columnas Canvas/Teams (íconos), período, subcuenta. |
| HU-03 | Crear Team desde un curso con un clic | `/admin/cursos` → acción "Crear en Teams" sobre un curso sin equipo; registra `teams_group_id` y crea el `EquipoTeams`. |
| HU-04 | Matricular en curso + Team a la vez, sin duplicar | `InscripcionMateriaService::inscribirManual` — valida duplicados (`assertNoInscritoAun`) y da de alta en ambas plataformas en una sola operación. |
| HU-05 | Historial de auditoría | `/admin/log-auditorias` y `/admin/job-sincronizacions` — toda operación real queda registrada vía `SincronizacionService`. |
| HU-06 | Baja combinada desde una pantalla | `InscripcionMateriaService::darDeBaja` — da de baja en Canvas y Teams y lo refleja en el log. |

## 4. Entorno funcionando sin credenciales reales

El sistema corre de punta a punta sin tokens de Canvas/Azure gracias al
patrón Null-Object (`App\Providers\AppServiceProvider`): cualquiera del
equipo o el docente puede clonar, instalar y probar el flujo completo sin
pedir credenciales. Para demostrarlo:

```bash
git clone https://github.com/RoqueLuis07/Canvas_for_Team.git
cd Canvas_for_Team/sistema-academico
git checkout php_proyecto_integrador
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

Entrar a `http://localhost:8000/admin` con `admin@usil.edu.py` / `password`.

## 5. Despliegue en Portainer (Avance 2)

- `docker-compose.yml` + `docker/php/Dockerfile` + `docker/nginx/Dockerfile`
  — stack Laravel + Nginx + MySQL + Redis.
- Mostrar el stack corriendo (4 contenedores en estado "running") y el
  login funcionando en la URL pública del stack.
- Detalle paso a paso: `README.md`, sección "Despliegue en Portainer".

## 6. Calidad de código — evidencia objetiva

```bash
php artisan test --compact        # 32/32 tests pasando
vendor/bin/pint --test --format agent   # sin problemas de estilo
php artisan route:list --except-vendor  # rutas del panel, consistentes
```

## 7. Historial de commits como bitácora

El historial de `php_proyecto_integrador` documenta, en orden, todo el
trabajo de Avance 2: corrección del stack de Portainer (Dockerfile,
volumen nombrado, permisos `www-data`, `fakerphp/faker`), branding del
panel, documentación, y la alineación final con el MER de Avance 1
(entidades `Rol`, `Subcuenta`, `EquipoTeams`, `MiembroEquipo`). Cada
commit es evidencia verificable en GitHub de una tarea concreta — no hace
falta capturas de pantalla adicionales para demostrar avance incremental.

## 8. Qué queda explícitamente fuera de este entregable

- HAProxy y balanceo entre dos arquitecturas activas → Avance 3.
- Benchmarking de RPS/latencia → Avance 4.
- Limpieza del contenido de otro proyecto (ajeno al integrador) que
  todavía convive en la raíz de esta rama — cosmético, no afecta a lo
  evaluado en `sistema-academico/`.
