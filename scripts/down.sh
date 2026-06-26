#!/usr/bin/env bash
#
# Baja los servicios de una app Oliva sobre infra compartida.
# Reemplaza el `docker compose -p ... -f ... -f ... -f ... down` a mano.
#
# Uso:
#   ./scripts/down.sh         # app_name = nombre del directorio actual (ej. en ~/ong → "ong")
#   ./scripts/down.sh ong     # app_name explícito
#
# Nunca borra volúmenes (no pasa -v): la DB y los secrets quedan a salvo.
# Corre desde la raíz del repo de la app en el VPS.
set -euo pipefail

APP_NAME="${1:-$(basename "$PWD")}"

COMPOSE=(docker compose -p "$APP_NAME"
  -f docker-compose.yml
  -f docker-compose.prod.yml
  -f docker-compose.shared-infra.yml)

echo ">> down de '$APP_NAME' desde $PWD"
# --remove-orphans barre también containers de servicios que ya no están en la
# config activa (ej. un scheduler que pasó a estar detrás de un profile).
# NO se pasa -v: los volúmenes (DB, secrets) se preservan.
"${COMPOSE[@]}" down --remove-orphans

echo ">> OK, servicios de '$APP_NAME' abajo (volúmenes intactos)."
