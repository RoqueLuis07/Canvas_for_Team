# Changelog — Avance 2

Resumen de lo entregado en Avance 2 sobre lo ya presentado en Avance 1.
Historial completo y verificable en los commits de la rama
`php_proyecto_integrador`.

## Funcional

- **Gestión de docentes**: asignación de un docente titular por curso, con
  alta automática como profesor en Canvas y propietario del equipo en Teams
  (`CursoService::asignarDocente`).
- **Materiales**: contenido académico por curso, publicable como página de
  Canvas (`MaterialService`).
- **Auditoría (HU-05 de Avance 1)**: `JobSincronizacion` (ciclo de vida de
  cada operación: pendiente → en_proceso → completado/error) y
  `LogAuditoria` (quién hizo qué, sobre qué entidad, cuándo), con vistas de
  solo lectura en el panel. Toda operación real contra Canvas/Teams
  (crear curso, crear equipo, asignar docente, alta/baja de alumno) queda
  registrada automáticamente vía `SincronizacionService`.
- Redirección de `/` a `/admin` (antes mostraba la pantalla de bienvenida
  genérica de Laravel).

## Infraestructura (despliegue en Portainer)

- Corregido `ext-intl`/`ext-mbstring` faltantes en el Dockerfile del stack
  (Filament y Laravel los requieren; el build fallaba sin ellos).
- Corregido el volumen `./:/var/www/html` que tapaba el `vendor/` instalado
  durante el build — reemplazado por un volumen Docker nombrado
  (`app_code`) que Docker puebla automáticamente desde la imagen.
- `docker/nginx/Dockerfile` nuevo: hornea la configuración de Nginx dentro
  de su propia imagen en vez de montarla desde el host (el bind-mount
  puntual fallaba al desplegar vía Git en Portainer).
- Corregidos los permisos de `storage/` y `bootstrap/cache/` (quedaban
  como `root`, pero `php-fpm` corre las peticiones como `www-data` — causaba
  un 500 silencioso en toda petición HTTP).
- `fakerphp/faker` movido de `require-dev` a `require`: el build de
  producción (`composer install --no-dev`) lo excluía, y el seeder de demo
  depende de `fake()`.
- `entrypoint.sh` nuevo: genera `APP_KEY` si falta, reintenta las
  migraciones hasta que la base esté lista, cachea configuración.
- Branding del panel: "Sistema Académico USIL" en vez del genérico
  "Laravel".
- `.env.portainer.example`: plantilla de las variables que interpola
  `docker-compose.yml`.

## Documentación

- `README.md` del proyecto (reemplaza el boilerplate genérico de Laravel).
- `docs/Guia_Equipo_Sistema_Academico.pdf` — guía oficial del equipo.
- `docs/ARQUITECTURA.md` — arquitectura y modelo de datos actuales.
- `docs/Avance_1_Informe_Planificacion.pdf` — informe de Avance 1 ya entregado.

## Pendiente (identificado, no bloqueante para Avance 2)

- Realinear el modelo de datos al MER exacto de Avance 1 (`ROL`, `USUARIO`,
  `SUBCUENTA`, `CURSO`, `MATRICULA`, `EQUIPO_TEAMS`, `MIEMBRO_EQUIPO`) —
  el modelo actual es más amplio (incluye autoservicio de postulación,
  planes de estudio y materias, fuera del alcance formal de Avance 1).
- HAProxy y segunda arquitectura activa — corresponde a Avance 3.
