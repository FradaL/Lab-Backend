#!/usr/bin/env bash
set -Eeuo pipefail

umask 077

readonly APP_ROOT="/opt/backend-lab"
read -r command release_id checksum extra <<< "${SSH_ORIGINAL_COMMAND:-}"

if [[ "$command" != "deploy" || -n "${extra:-}" ]]; then
    echo "This SSH key only accepts Donqer Lab backend deployments." >&2
    exit 1
fi

if [[ ! "$release_id" =~ ^[0-9a-f]{40}-[0-9]+-[0-9]+$ ]]; then
    echo "Invalid release identifier." >&2
    exit 1
fi

if [[ ! "$checksum" =~ ^[0-9a-f]{64}$ ]]; then
    echo "Invalid artifact checksum." >&2
    exit 1
fi

artifact=$(mktemp "/tmp/donqer-lab-backend-${release_id}.XXXXXX.tar.gz")
cleanup() {
    rm -f "$artifact"
}
trap cleanup EXIT HUP INT TERM

cat > "$artifact"

if (( $(stat -c '%s' "$artifact") > 209715200 )); then
    echo "Release artifact exceeds the 200 MiB limit." >&2
    exit 1
fi

actual_checksum=$(sha256sum "$artifact" | awk '{print $1}')
if [[ "$actual_checksum" != "$checksum" ]]; then
    echo "Uploaded artifact checksum mismatch." >&2
    exit 1
fi

"$APP_ROOT/bin/deploy-release" "$release_id" "$artifact" "$checksum"
