#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="ai-provider-for-codex"
EXCLUDE_FILE="${ROOT_DIR}/scripts/release-exclude.txt"
DIST_DIR="${DIST_DIR:-$(dirname "${ROOT_DIR}")/plugin-builds}"
STAGING_DIR="$(mktemp -d)"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "${ROOT_DIR}/plugin.php" | head -n 1)"

cleanup() {
	rm -rf "${STAGING_DIR}"
}
trap cleanup EXIT

if [[ -z "${VERSION}" ]]; then
	echo "Could not determine plugin version from plugin.php" >&2
	exit 1
fi

mkdir -p "${DIST_DIR}"
TARGET_DIR="${STAGING_DIR}/${SLUG}"
OUTPUT_ZIP="${DIST_DIR}/${SLUG}-${VERSION}.zip"

if ! command -v rsync >/dev/null 2>&1; then
	echo "This script requires rsync." >&2
	exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
	echo "This script requires zip." >&2
	exit 1
fi

rsync -a \
	--delete \
	--exclude-from="${EXCLUDE_FILE}" \
	"${ROOT_DIR}/" \
	"${TARGET_DIR}/"

(
	cd "${STAGING_DIR}"
	rm -f "${OUTPUT_ZIP}"
	zip -qr "${OUTPUT_ZIP}" "${SLUG}"
)

echo "Created ${OUTPUT_ZIP}"
