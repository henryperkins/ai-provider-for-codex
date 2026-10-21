#!/usr/bin/env bash
# Offline qualification only. Inputs are local, checksum-verified release assets.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SIDECAR="$(cd "$HERE/../.." && pwd)"
: "${CODEX_BIN:?Set CODEX_BIN to the official 0.162.1 Linux x86_64 musl executable}"
: "${CODEX_CODE_MODE_HOST_BIN:?Set CODEX_CODE_MODE_HOST_BIN to the matching code-mode host executable}"
: "${MODELS_JSON:?Set MODELS_JSON to models-manager/models.json from the 0.162.1 source tag}"
CODEX_BIN="$(realpath "$CODEX_BIN")"
CODEX_CODE_MODE_HOST_BIN="$(realpath "$CODEX_CODE_MODE_HOST_BIN")"
MODELS_JSON="$(realpath "$MODELS_JSON")"

# Hashes of the executables extracted from the official, digest-verified archives.
printf '%s  %s\n' \
  628e35487d888a28f0427fc37501da529dd3d81fcbad7a2c3421c1e8670d92e1 "$CODEX_BIN" \
  ec71830690a7c0024916ee0c99fbdc635bc6e747ff665ea4f03a8389bbcb6b30 "$CODEX_CODE_MODE_HOST_BIN" \
  943ca7fe1d19ed019054158303f0dbbd80b3bef43b3aa425a71aa7cb3fb52c2b "$MODELS_JSON" \
  | sha256sum --check

IMAGE="${PROBE_IMAGE:-node:24-bookworm}"
# Resolve a local image first; starting the offline run never pulls an image.
IMAGE_ID="$(docker image inspect "$IMAGE" --format '{{.Id}}')"
OUT="${PROBE_OUT:-$(mktemp -d "${TMPDIR:-/tmp}/codex-tool-surface.XXXXXXXX")}"
mkdir -p "$OUT"
OUT="$(realpath "$OUT")"
CONTAINER="codex-tool-probe-$(id -u)-$$"
cleanup() {
  docker rm --force "$CONTAINER" >/dev/null 2>&1 || true
}
# docker run --rm can outlive a terminated CLI; always remove our named container.
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

docker run --rm --pull never --name "$CONTAINER" --network none \
  --memory 3g --pids-limit 128 --read-only --cap-drop ALL \
  --security-opt no-new-privileges --user "$(id -u):$(id -g)" \
  --tmpfs /tmp:rw,nosuid,size=1g,mode=1777 \
  -e HOME=/tmp -e PYTHONDONTWRITEBYTECODE=1 -e PYTHONUNBUFFERED=1 \
  -e PATH=/codex:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
  -e SSL_CERT_FILE=/tmp/probe-tls/ca.crt -e PROBE_IMAGE_ID="$IMAGE_ID" \
  --mount "type=bind,source=$SIDECAR/app,target=/sidecar/app,readonly" \
  --mount "type=bind,source=$HERE,target=/harness,readonly" \
  --mount "type=bind,source=$CODEX_BIN,target=/codex/codex,readonly" \
  --mount "type=bind,source=$CODEX_CODE_MODE_HOST_BIN,target=/codex/codex-code-mode-host,readonly" \
  --mount "type=bind,source=$MODELS_JSON,target=/inputs/models.json,readonly" \
  --mount "type=bind,source=$OUT,target=/out" \
  "$IMAGE" bash -c '
    set -euo pipefail
    mkdir -p /tmp/probe-tls
    openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
      -subj "/CN=Codex offline probe CA" \
      -addext "basicConstraints=critical,CA:TRUE" \
      -keyout /tmp/probe-tls/ca.key -out /tmp/probe-tls/ca.crt >/dev/null 2>&1
    openssl req -new -newkey rsa:2048 -nodes -subj "/CN=localhost" \
      -addext "subjectAltName=IP:127.0.0.1,DNS:localhost" \
      -keyout /tmp/probe-tls/server.key -out /tmp/probe-tls/server.csr >/dev/null 2>&1
    openssl x509 -req -days 1 -copy_extensions copy \
      -in /tmp/probe-tls/server.csr -CA /tmp/probe-tls/ca.crt \
      -CAkey /tmp/probe-tls/ca.key -CAcreateserial \
      -out /tmp/probe-tls/server.crt >/dev/null 2>&1
    python3 -I /harness/probe.py
  '

printf 'Probe evidence: %s\n' "$OUT"
