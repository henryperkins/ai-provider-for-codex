#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_PATH="${WP_PATH:-/home/hperkins-wp/htdocs/wp.hperkins.com}"

while IFS= read -r -d '' file; do
	php -l "$file" >/dev/null
done < <(find "$ROOT_DIR" -type f -name '*.php' -not -path '*/vendor/*' -print0)

node --input-type=module --check < "$ROOT_DIR/assets/connectors.js" >/dev/null
wp --path="$WP_PATH" eval-file "$ROOT_DIR/scripts/verify.php"
