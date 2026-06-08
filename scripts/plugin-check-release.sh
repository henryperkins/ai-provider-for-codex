#!/usr/bin/env bash
set -euo pipefail

# Runs Plugin Check against the symlinked dev checkout while skipping the
# dev-only files that scripts/release-exclude.txt strips from the release zip.
#
# Plugin Check's --exclude-* flags do literal substring matching (see
# Plugin_Check/.../Abstract_File_Check.php), so glob entries in
# release-exclude.txt (e.g. *.pyc, *.swp) cannot be auto-derived; the lists
# below are the dev-checkout subset of release-exclude.txt that survives that
# limitation. Plugin Check already excludes .git, vendor, vendor_prefixed,
# vendor-prefixed, and node_modules by default — do not re-add those here.
# When you add a dev-only file to release-exclude.txt, also add it here if it
# is a literal name that lands in a developer's working tree.

SLUG="ai-provider-for-codex"

if [[ -z "${WP_PATH:-}" ]]; then
	echo "Set WP_PATH=/path/to/wordpress and retry." >&2
	exit 1
fi

EXCLUDE_DIRECTORIES="docs,scripts,sidecar/scripts"
EXCLUDE_FILES=".gitignore,.distignore,CLAUDE.md,phpstan-stubs.php,LOCAL-SIDECAR-SPEC.md,PLUGIN-SUBMISSION-READINESS-CHECKLIST.md,README.md,codex-app.err,composer.json,composer.lock,package.json,package-lock.json,phpstan.neon,phpstan-baseline.neon"

if ! command -v wp >/dev/null 2>&1; then
	echo "This script requires wp-cli." >&2
	exit 1
fi

if [[ ! -d "${WP_PATH}" ]]; then
	echo "WordPress path does not exist: ${WP_PATH}" >&2
	echo "Set WP_PATH=/path/to/wordpress and retry." >&2
	exit 1
fi

wp --path="${WP_PATH}" plugin check "${SLUG}" \
	--exclude-directories="${EXCLUDE_DIRECTORIES}" \
	--exclude-files="${EXCLUDE_FILES}" \
	"$@"
