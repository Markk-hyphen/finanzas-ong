#!/usr/bin/env bash
#
# Deploy de una app Oliva sobre infra compartida (down + build + up).
# Reemplaza la tira de comandos manuales del runbook de CLAUDE.md.
#
# Uso:
#   ./scripts/deploy.sh            # app_name = nombre del directorio actual (ej. en ~/ong → "ong")
#   ./scripts/deploy.sh ong        # app_name explícito
#   SKIP_PULL=1 ./scripts/deploy.sh   # no hace git pull (deploy de cambios ya presentes)
#
# Corre desde la raíz del repo de la app en el VPS (ahí viven los 3 compose files).
set -euo pipefail

APP_NAME="${1:-$(basename "$PWD")}"

COMPOSE=(docker compose -p "$APP_NAME"
  -f docker-compose.yml
  -f docker-compose.prod.yml
  -f docker-compose.shared-infra.yml)

echo ">> Deploy de '$APP_NAME' desde $PWD"

if [[ "${SKIP_PULL:-0}" != "1" ]]; then
  echo ">> git pull"
  git pull
fi

# down borra los containers viejos antes de recrearlos. --remove-orphans barre
# también los containers de servicios que ya NO están en la config activa (ej.
# un scheduler que pasó a estar detrás de un profile): sin esa flag, Compose los
# ignora y quedan corriendo stale.
echo ">> down"
"${COMPOSE[@]}" down --remove-orphans

echo ">> up -d --build"
"${COMPOSE[@]}" up -d --build

echo ">> estado:"
"${COMPOSE[@]}" ps

echo ">> OK. Si la app usa cron, agregá '--profile cron' al up (ver CLAUDE.md)."
