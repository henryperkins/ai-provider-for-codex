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

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="scriptorium-ai-provider-for-codex"

if [[ -z "${WP_PATH:-}" ]]; then
	echo "Set WP_PATH=/path/to/wordpress and retry." >&2
	exit 1
fi

EXCLUDE_DIRECTORIES="docs,scripts,sidecar/scripts"
EXCLUDE_FILES=".gitignore,IDEATION-ARTIFACT.md,leadership-lesson-side-convo.md,plato-leadership-lesson.md,.distignore,CLAUDE.md,phpstan-stubs.php,LOCAL-SIDECAR-SPEC.md,PLUGIN-SUBMISSION-READINESS-CHECKLIST.md,README.md,codex-app.err,composer.json,composer.lock,package.json,package-lock.json,phpstan.neon,phpstan-baseline.neon"

if ! command -v wp >/dev/null 2>&1; then
	echo "This script requires wp-cli." >&2
	exit 1
fi

if [[ ! -d "${WP_PATH}" ]]; then
	echo "WordPress path does not exist: ${WP_PATH}" >&2
	echo "Set WP_PATH=/path/to/wordpress and retry." >&2
	exit 1
fi

# Enforce the same release-path guard the packager uses, without building a zip
# or coupling this lint check to version-drift checks.
bash "${ROOT_DIR}/scripts/check-untracked-release-paths.sh"

# Mirror package-release.sh: the release zip excludes every git-untracked
# working-tree file (local notes, command output, scratch markdown), so exclude
# those from the check too. Otherwise this wrapper flags scratch files the
# release never ships, and EXCLUDE_FILES would need hand-editing per file.
# Plugin Check's --exclude-files does literal substring matching, so an empty
# value would match every path — only append when the untracked set is non-empty.
if git -C "${ROOT_DIR}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	UNTRACKED_FILES="$(git -C "${ROOT_DIR}" ls-files --others --exclude-standard | paste -sd, -)"
	if [[ -n "${UNTRACKED_FILES}" ]]; then
		EXCLUDE_FILES="${EXCLUDE_FILES},${UNTRACKED_FILES}"
	fi
fi

wp --path="${WP_PATH}" plugin check "${SLUG}" \
	--exclude-directories="${EXCLUDE_DIRECTORIES}" \
	--exclude-files="${EXCLUDE_FILES}" \
	"$@"
