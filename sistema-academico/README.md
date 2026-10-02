# Sistema Académico — Canvas LMS ↔ Microsoft Teams

Panel administrativo (Laravel 13 + Filament 3.3) que centraliza y automatiza la
sincronización entre Canvas LMS y Microsoft Teams para USIL Paraguay: alta de
usuarios, creación de cursos/equipos, matriculación combinada y auditoría de
cada operación.

Proyecto Integrador — **Programación IV**. Grupo N.° 3 — Canvas LMS / Microsoft Teams.

## Documentación

- **[Guía Oficial del Equipo](docs/Guia_Equipo_Sistema_Academico.pdf)** — qué es
  la plataforma, arquitectura, reglas de la rama de trabajo, cómo levantar el
  proyecto local y qué falta por rol. Léela antes de tocar el código.
- Rama de trabajo del equipo: **`php_proyecto_integrador`** — no se pushea ni
  se hace merge hacia otra rama del repositorio.

## Arquitectura

- **Framework:** Laravel 13 (PHP 8.4), SQLite en desarrollo local / MySQL en
  el stack de Portainer.
- **Panel administrativo:** Filament 3.3 (`/admin`).
- **Integraciones:** Canvas API (REST) y Microsoft Graph API (Teams / Azure
  AD). Detrás de un patrón Contract + Null-Object (`App\Contracts\CanvasClient`
  / `TeamsClient`): sin credenciales configuradas, el sistema usa clientes
  simulados automáticamente — **no hace falta ningún token real para
  desarrollar**.
- **Despliegue (Avance 2):** stack de Docker Compose pensado para Portainer
  (`docker-compose.yml`) — Laravel + Nginx + MySQL + Redis.

## Puesta en marcha local

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

Panel en `http://localhost:8000/admin`. Login de prueba creado por el seeder:
`admin@usil.edu.py` / `password`.

## Despliegue en Portainer (Avance 2)

Ver `.env.portainer.example` para las variables que espera
`docker-compose.yml`. Resumen:

1. Portainer → Stacks → Add stack → Build method **Repository**.
2. Repository URL: `https://github.com/RoqueLuis07/Canvas_for_Team.git`,
   referencia `refs/heads/php_proyecto_integrador`, compose path
   `sistema-academico/docker-compose.yml`.
3. Cargar las variables de `.env.portainer.example` en "Environment variables".
4. Deploy the stack.

Más detalle paso a paso en la Guía Oficial del Equipo.

## Tests

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent   # antes de cada commit con cambios PHP
```
