#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_PATH="${WP_PATH:-/home/hperkins-wp/htdocs/wp.hperkins.com}"

while IFS= read -r -d '' file; do
	php -l "$file" >/dev/null
done < <(find "$ROOT_DIR" -type f -name '*.php' -not -path '*/vendor/*' -print0)

for release_exclusion in "/docs" "/sidecar/scripts"; do
	if ! grep -Fxq "$release_exclusion" "$ROOT_DIR/scripts/release-exclude.txt"; then
		echo "scripts/release-exclude.txt must exclude $release_exclusion from release zips." >&2
		exit 1
	fi
done

plugin_check_excludes="$(
	sed -n 's/^EXCLUDE_DIRECTORIES="\([^"]*\)".*/\1/p' "$ROOT_DIR/scripts/plugin-check-release.sh" | head -n 1
)"

for plugin_check_exclusion in "docs" "sidecar/scripts"; do
	case ",$plugin_check_excludes," in
		*,"$plugin_check_exclusion",*) ;;
		*)
			echo "scripts/plugin-check-release.sh must exclude $plugin_check_exclusion from dev-tree Plugin Check runs." >&2
			exit 1
			;;
	esac
done

node --input-type=module --check < "$ROOT_DIR/assets/connectors.js" >/dev/null
wp --path="$WP_PATH" eval-file "$ROOT_DIR/scripts/verify.php"
