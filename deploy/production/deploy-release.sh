#!/usr/bin/env bash
set -Eeuo pipefail

umask 027

readonly APP_ROOT="/opt/backend-lab"
readonly RELEASES_DIR="$APP_ROOT/releases"
readonly CURRENT_LINK="$APP_ROOT/current"
readonly RUNTIME_DIR="$APP_ROOT/runtime"
readonly RELEASE_ID="${1:-}"
readonly ARTIFACT="${2:-}"
readonly EXPECTED_CHECKSUM="${3:-}"

exec 9>"$APP_ROOT/.deploy.lock"
if ! flock -n 9; then
    echo "Another backend deployment is already running." >&2
    exit 1
fi

if [[ ! "$RELEASE_ID" =~ ^[0-9a-f]{40}-[0-9]+-[0-9]+$ ]]; then
    echo "Invalid release identifier." >&2
    exit 1
fi

if [[ ! "$EXPECTED_CHECKSUM" =~ ^[0-9a-f]{64}$ ]]; then
    echo "Invalid artifact checksum." >&2
    exit 1
fi

if [[ ! -f "$ARTIFACT" ]]; then
    echo "Release artifact not found." >&2
    exit 1
fi

actual_checksum=$(sha256sum "$ARTIFACT" | awk '{print $1}')
if [[ "$actual_checksum" != "$EXPECTED_CHECKSUM" ]]; then
    echo "Release artifact checksum mismatch." >&2
    exit 1
fi

if [[ ! -f "$RUNTIME_DIR/compose.yaml" || ! -f "$RUNTIME_DIR/.env" ]]; then
    echo "Trusted backend runtime configuration is missing." >&2
    exit 1
fi

readonly RELEASE_DIR="$RELEASES_DIR/$RELEASE_ID"
if [[ -e "$RELEASE_DIR" ]]; then
    echo "Release already exists: $RELEASE_ID" >&2
    exit 1
fi

mkdir -p "$RELEASES_DIR"
mkdir "$RELEASE_DIR"

while IFS= read -r entry; do
    if [[ "$entry" == /* || "$entry" == ".." || "$entry" == ../* || "$entry" == */../* || "$entry" == */.. ]]; then
        echo "Unsafe path in release artifact: $entry" >&2
        exit 1
    fi

    if [[ "$entry" == ".env" || "$entry" == */.env ]]; then
        echo "Release artifact must not contain environment secrets." >&2
        exit 1
    fi
done < <(tar -tzf "$ARTIFACT")

tar --no-same-owner --no-same-permissions -xzf "$ARTIFACT" -C "$RELEASE_DIR"

unexpected_entry=$(find "$RELEASE_DIR" ! -type f ! -type d -print -quit)
if [[ -n "$unexpected_entry" ]]; then
    echo "Release artifact contains an unsupported entry: $unexpected_entry" >&2
    exit 1
fi

find "$RELEASE_DIR" -type d -exec chmod 0755 {} +
find "$RELEASE_DIR" -type f -exec chmod 0644 {} +

for required_file in \
    .dockerignore \
    laravel/artisan \
    laravel/composer.json \
    laravel/composer.lock \
    deploy/production/Dockerfile; do
    if [[ ! -f "$RELEASE_DIR/$required_file" ]]; then
        echo "Release is missing $required_file." >&2
        exit 1
    fi
done

compose() {
    BACKEND_SOURCE="$RELEASE_DIR" docker compose \
        --env-file "$RUNTIME_DIR/.env" \
        -f "$RUNTIME_DIR/compose.yaml" \
        "$@"
}

previous_app_image=$(docker image inspect donqer-lab-app:local --format '{{.Id}}' 2>/dev/null || true)
previous_web_image=$(docker image inspect donqer-lab-web:local --format '{{.Id}}' 2>/dev/null || true)
deployment_started=0

rollback() {
    trap - ERR
    set +e

    if [[ "$deployment_started" == "1" && -n "$previous_app_image" && -n "$previous_web_image" ]]; then
        echo "Deployment failed; restoring the previous backend images." >&2
        docker image tag "$previous_app_image" donqer-lab-app:local
        docker image tag "$previous_web_image" donqer-lab-web:local
        compose up -d --no-deps --force-recreate app scheduler web
        echo "Application images restored. Database migrations are not rolled back automatically." >&2
    fi
}
trap rollback ERR

compose config --quiet
deployment_started=1
compose build --pull app web
compose up -d postgres
compose run --rm app php artisan migrate --force
compose up -d app scheduler web

healthy=0
for attempt in {1..30}; do
    if compose exec -T web wget --quiet --tries=1 --spider http://127.0.0.1/up; then
        healthy=1
        break
    fi

    sleep 2
done

if [[ "$healthy" != "1" ]]; then
    compose ps >&2
    compose logs --tail=100 app scheduler web postgres >&2
    echo "Backend health check failed after deployment." >&2
    false
fi

curl \
    --fail \
    --silent \
    --show-error \
    --retry 5 \
    --retry-all-errors \
    --retry-delay 2 \
    -H 'Host: api-lab.donqer.com' \
    http://127.0.0.1/up > /dev/null

next_link="$APP_ROOT/.current.$RELEASE_ID"
ln -s "$RELEASE_DIR" "$next_link"
mv -Tf "$next_link" "$CURRENT_LINK"

trap - ERR
compose ps
echo "Deployed Donqer Lab backend release $RELEASE_ID"
