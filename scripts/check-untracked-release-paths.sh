#!/usr/bin/env bash
#
# Fails if any release-path file is untracked by Git.
#
# The release zip excludes every git-untracked working-tree file so local
# scratch artifacts never ship. That same exclusion would silently drop a real
# source file someone forgot to `git add`, so the packager (package-release.sh)
# and the release-style Plugin Check (plugin-check-release.sh) both call this
# guard first to fail loudly instead of shipping — or linting around — a gap.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Outside a Git work tree there is nothing to check (e.g. an extracted tarball).
if ! git -C "${ROOT_DIR}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	exit 0
fi

mapfile -t UNTRACKED_RELEASE_FILES < <(
	git -C "${ROOT_DIR}" ls-files --others --exclude-standard -- \
		assets \
		languages \
		src \
		sidecar/app \
		sidecar/config.example.env \
		sidecar/systemd \
		scriptorium-ai-provider-for-codex.php \
		readme.txt \
		uninstall.php
)

if [[ "${#UNTRACKED_RELEASE_FILES[@]}" -gt 0 ]]; then
	echo "Release-path files must be tracked before packaging:" >&2
	printf '  %s\n' "${UNTRACKED_RELEASE_FILES[@]}" >&2
	echo "The release build excludes untracked files to prevent local scratch artifacts from shipping." >&2
	exit 1
fi
