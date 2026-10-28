#!/usr/bin/env bash
set +x
set -euo pipefail
cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.."
if [[ -z "${DONQER_EXC_DEMO_OWNER_PASSWORD:-}" ]]; then
    read -r -s -p 'Contraseña DEMO (mínimo 12 caracteres; reutiliza la original): ' DONQER_EXC_DEMO_OWNER_PASSWORD < /dev/tty
    printf '\n'
fi
if (( ${#DONQER_EXC_DEMO_OWNER_PASSWORD} < 12 )); then
    printf 'La contraseña DEMO necesita al menos 12 caracteres.\n' >&2
    exit 1
fi
export DONQER_EXC_DEMO_OWNER_PASSWORD
trap 'unset DONQER_EXC_DEMO_OWNER_PASSWORD' EXIT
docker compose -p donqer-exc-demo -f deploy/demo-exc/compose.yaml up -d
docker compose -p donqer-exc-demo -f deploy/demo-exc/compose.yaml exec -T api php artisan migrate --no-interaction
docker compose -p donqer-exc-demo -f deploy/demo-exc/compose.yaml exec -T api php artisan db:seed --class=DemoDevelopmentSeeder --no-interaction
printf 'DEMO disponible en http://localhost:8086. Credenciales: el secreto introducido y los correos de docs/sprint-2/DEMO-development-seeders.md.\n'
