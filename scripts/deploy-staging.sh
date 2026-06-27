#!/usr/bin/env bash
#
# Deploy del stack de STAGING de una app Oliva sobre infra compartida.
# Análogo a deploy.sh, pero usa la imagen frankenphp_staging y un project
# name propio (-p <app>-staging) para no interferir con el stack de producción.
#
# Prerequisitos (primera vez, una sola vez por app):
#   1. Provisionar la DB de staging en el Postgres compartido:
#        ~/infra/scripts/provision-postgres.sh <app>_staging_db <app>_staging_user
#   2. Completar .env.staging (copiar de .env.staging.example y setear DATABASE_URL).
#   3. Reemplazar CHANGE-THIS-ALIAS-frontend en docker-compose.shared-infra.yml
#      (ya debería estar hecho para prod; staging comparte el alias de red de prod).
#
# Uso:
#   ./scripts/deploy-staging.sh            # app_name = nombre del directorio actual
#   ./scripts/deploy-staging.sh ong        # app_name explícito
#   SKIP_PULL=1 ./scripts/deploy-staging.sh   # no hace git pull
set -euo pipefail

APP_NAME="${1:-$(basename "$PWD")}"
STAGING_PROJECT="${APP_NAME}-staging"

COMPOSE=(docker compose -p "$STAGING_PROJECT"
  -f docker-compose.yml
  -f docker-compose.prod.yml
  -f docker-compose.shared-infra.yml
  -f docker-compose.staging.yml)

echo ">> Deploy de STAGING '$STAGING_PROJECT' desde $PWD"

if [[ "${SKIP_PULL:-0}" != "1" ]]; then
  echo ">> git pull"
  git pull
fi

echo ">> down"
COMPOSE_PROFILES=cron "${COMPOSE[@]}" down --remove-orphans

echo ">> build (target: frankenphp_staging)"
"${COMPOSE[@]}" build backend

echo ">> up -d"
"${COMPOSE[@]}" up -d

echo ">> migraciones"
docker exec "${STAGING_PROJECT}-backend-1" bin/console doctrine:migrations:migrate --no-interaction

echo ">> seeding (fixtures:load --group=staging)"
docker exec "${STAGING_PROJECT}-backend-1" bin/console doctrine:fixtures:load --group=staging --no-interaction

echo ">> estado:"
"${COMPOSE[@]}" ps

echo ">> OK — staging corriendo en project '${STAGING_PROJECT}'."
echo "   DB de staging es desechable. Para reiniciar con datos frescos:"
echo "   SKIP_PULL=1 ./scripts/deploy-staging.sh"
