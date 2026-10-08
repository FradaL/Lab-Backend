#!/usr/bin/env bash
set -euo pipefail

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly HEALTH_URL="http://127.0.0.1/up"

cd "$SCRIPT_DIR"

if [[ ! -f .env ]]; then
    echo "Missing production environment file: $SCRIPT_DIR/.env" >&2
    exit 1
fi

docker compose config --quiet
docker compose build --pull app web
docker compose up -d postgres
docker compose run --rm app php artisan migrate --force

if [[ "${SEED_DEMO_DATA:-0}" == "1" ]]; then
    docker compose --profile tools build seed
    docker compose --profile tools run --rm seed db:seed --force
fi

docker compose up -d app scheduler web

for attempt in {1..30}; do
    if docker compose exec -T web wget --quiet --tries=1 --spider "$HEALTH_URL"; then
        docker compose ps
        exit 0
    fi

    sleep 2
done

docker compose ps >&2
docker compose logs --tail=100 app scheduler web postgres >&2
echo "Health check failed after deployment" >&2
exit 1
