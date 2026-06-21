#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="ai-provider-for-codex"
EXCLUDE_FILE="${ROOT_DIR}/scripts/release-exclude.txt"
DIST_DIR="${DIST_DIR:-$(dirname "${ROOT_DIR}")/plugin-builds}"
STAGING_DIR="$(mktemp -d)"
UNTRACKED_EXCLUDE_FILE=""

cleanup() {
	rm -rf "${STAGING_DIR}"
	[[ -n "${UNTRACKED_EXCLUDE_FILE}" ]] && rm -f "${UNTRACKED_EXCLUDE_FILE}"
}
trap cleanup EXIT

# WordPress.org rejects submissions when the main file's Version: header does
# not match readme.txt's Stable tag, so guard against drift here.
HEADER_VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "${ROOT_DIR}/ai-provider-for-codex.php" | head -n 1)"
README_STABLE="$(sed -n 's/^Stable tag:[[:space:]]*//p' "${ROOT_DIR}/readme.txt" | head -n 1)"

if [[ -z "${HEADER_VERSION}" ]]; then
	echo "Could not determine plugin version from ai-provider-for-codex.php" >&2
	exit 1
fi

if [[ -z "${README_STABLE}" ]]; then
	echo "Could not determine 'Stable tag' from readme.txt" >&2
	exit 1
fi

if [[ "${HEADER_VERSION}" != "${README_STABLE}" ]]; then
	echo "Version drift detected:" >&2
	echo "  ai-provider-for-codex.php Version:    ${HEADER_VERSION}" >&2
	echo "  readme.txt Stable tag: ${README_STABLE}" >&2
	echo "WordPress.org will reject the submission until these match." >&2
	exit 1
fi

VERSION="${HEADER_VERSION}"

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

if ! command -v php >/dev/null 2>&1; then
	echo "This script requires php (for syntax linting the staged tree)." >&2
	exit 1
fi

# Never ship files Git does not track (local notes, command output, scratch
# files): build an exclude list from the working tree's untracked set so a
# stray file cannot leak into the release zip. Modified tracked files still
# ship, preserving the build-from-working-tree release-prep workflow. Falls
# back to release-exclude.txt alone outside a Git work tree.
# Fail loudly if a real release-path source file is untracked, before the block
# below silently excludes the untracked set from the build.
bash "${ROOT_DIR}/scripts/check-untracked-release-paths.sh"

RSYNC_EXCLUDES=( --exclude-from="${EXCLUDE_FILE}" )
if git -C "${ROOT_DIR}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	UNTRACKED_EXCLUDE_FILE="$(mktemp)"
	git -C "${ROOT_DIR}" ls-files --others --exclude-standard \
		| sed 's,^,/,' > "${UNTRACKED_EXCLUDE_FILE}"
	RSYNC_EXCLUDES+=( --exclude-from="${UNTRACKED_EXCLUDE_FILE}" )
fi

rsync -a \
	--delete \
	"${RSYNC_EXCLUDES[@]}" \
	"${ROOT_DIR}/" \
	"${TARGET_DIR}/"

if [[ -e "${TARGET_DIR}/sidecar" ]]; then
	echo "Release package must not include sidecar/; the local runtime is distributed separately." >&2
	exit 1
fi

# Lint the exact PHP file set that will ship — catches errors that hide in dev
# because of include ordering or files that only the packaged tree exposes.
while IFS= read -r -d '' file; do
	php -l "$file" >/dev/null
done < <(find "${TARGET_DIR}" -type f -name '*.php' -print0)

(
	cd "${STAGING_DIR}"
	rm -f "${OUTPUT_ZIP}"
	zip -qr "${OUTPUT_ZIP}" "${SLUG}"
)

echo "Created ${OUTPUT_ZIP}"
echo
echo "Before submitting to WordPress.org, also run:"
echo "  - Release-style Plugin Check:  WP_PATH=/path/to/wordpress bash scripts/plugin-check-release.sh"
echo "  - Readme validator:            https://wordpress.org/plugins/developers/readme-validator/"
