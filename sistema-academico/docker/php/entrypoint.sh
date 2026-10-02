#!/bin/sh
# Entrypoint del stack de Portainer (Arquitectura A, Avance 2): espera a
# que MySQL acepte conexiones (docker-compose "depends_on" solo espera a
# que el contenedor arranque, no a que la base esté lista), genera
# APP_KEY si no vino seteada por variable de entorno, corre las
# migraciones (idempotente, seguro en cada reinicio del contenedor) y
# recién ahí arranca el proceso real (php-fpm).
set -e

if [ -z "$APP_KEY" ]; then
  echo "[entrypoint] APP_KEY no está seteada — generando una (deberías fijarla como variable de entorno del stack para que no cambie en cada reinicio)."
  php artisan key:generate --force
fi

# "depends_on" en docker-compose solo espera a que el contenedor de MySQL
# arranque, no a que acepte conexiones — reintenta la migración (idempotente)
# hasta que la base esté realmente lista.
echo "[entrypoint] Corriendo migraciones (reintenta hasta que la base esté lista)..."
attempt=0
until php artisan migrate --force; do
  attempt=$((attempt + 1))
  if [ "$attempt" -ge 15 ]; then
    echo "[entrypoint] La base de datos no respondió tras 15 intentos — abortando."
    exit 1
  fi
  echo "[entrypoint] Base de datos todavía no lista, reintentando en 2s (intento $attempt/15)..."
  sleep 2
done

echo "[entrypoint] Cacheando configuración..."
php artisan config:cache

exec "$@"
