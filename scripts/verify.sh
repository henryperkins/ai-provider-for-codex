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

locked_ai_client_version="$(
	php -r '
		$lock = json_decode(file_get_contents($argv[1]), true);
		foreach (($lock["packages-dev"] ?? []) as $package) {
			if (($package["name"] ?? "") === "wordpress/php-ai-client") {
				echo (string) ($package["version"] ?? "");
				exit;
			}
		}
	' "$ROOT_DIR/composer.lock"
)"

if [[ -z "$locked_ai_client_version" ]]; then
	echo "composer.lock must include wordpress/php-ai-client for local verification." >&2
	exit 1
fi

if [[ "$locked_ai_client_version" != dev-* ]] && ! php -r 'exit(version_compare($argv[1], "1.0", ">=") ? 0 : 1);' "$locked_ai_client_version"; then
	echo "composer.lock must use wordpress/php-ai-client 1.0 or newer for local verification. Current locked version: $locked_ai_client_version" >&2
	exit 1
fi

plugin_requires_wp="$(
	sed -n 's/^ \* Requires at least:[[:space:]]*//p' "$ROOT_DIR/scriptorium-ai-provider-for-codex.php" | head -n 1
)"
readme_requires_wp="$(
	sed -n 's/^Requires at least:[[:space:]]*//p' "$ROOT_DIR/readme.txt" | head -n 1
)"
readiness_checklist="$ROOT_DIR/PLUGIN-SUBMISSION-READINESS-CHECKLIST.md"

if [[ -z "$plugin_requires_wp" ]]; then
	echo "scriptorium-ai-provider-for-codex.php must declare a Requires at least header." >&2
	exit 1
fi

if [[ "$plugin_requires_wp" != "$readme_requires_wp" ]]; then
	echo "scriptorium-ai-provider-for-codex.php and readme.txt must declare the same Requires at least value." >&2
	echo "  scriptorium-ai-provider-for-codex.php: $plugin_requires_wp" >&2
	echo "  readme.txt: $readme_requires_wp" >&2
	exit 1
fi

if ! grep -Fq "Requires at least: $plugin_requires_wp" "$readiness_checklist"; then
	echo "PLUGIN-SUBMISSION-READINESS-CHECKLIST.md must reference the current Requires at least value: $plugin_requires_wp." >&2
	exit 1
fi

if grep -Fq "WordPress 6.9" "$readiness_checklist"; then
	echo "PLUGIN-SUBMISSION-READINESS-CHECKLIST.md still refers to unsupported WordPress 6.9 runtime support." >&2
	exit 1
fi

# The sidecar reports its version in three hand-maintained spots (clientInfo,
# the /healthz payload, and server_version). These are not derived from the
# plugin header, so guard against the drift that shipped at 0.1.4.
plugin_version="$(
	sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT_DIR/scriptorium-ai-provider-for-codex.php" | head -n 1
)"
sidecar_main="$ROOT_DIR/sidecar/app/main.py"

if [[ -z "$plugin_version" ]]; then
	echo "scriptorium-ai-provider-for-codex.php must declare a Version header." >&2
	exit 1
fi

if [[ -f "$sidecar_main" ]]; then
	sidecar_version_hits="$(grep -c "\"version\": \"$plugin_version\"" "$sidecar_main" || true)"
	if [[ "$sidecar_version_hits" -lt 2 ]]; then
		echo "sidecar/app/main.py must report version $plugin_version in both clientInfo and the /healthz payload (found $sidecar_version_hits of 2)." >&2
		exit 1
	fi
	if ! grep -Fq "server_version = \"CodexWPSidecar/$plugin_version\"" "$sidecar_main"; then
		echo "sidecar/app/main.py server_version must be \"CodexWPSidecar/$plugin_version\"." >&2
		exit 1
	fi
fi

node --input-type=module --check < "$ROOT_DIR/assets/connection-flow.js" >/dev/null
node --input-type=module --check < "$ROOT_DIR/assets/connectors.js" >/dev/null
node --input-type=module --check < "$ROOT_DIR/assets/user-connection.js" >/dev/null
node --input-type=module --check < "$ROOT_DIR/assets/diagnostics.js" >/dev/null
node --test "$ROOT_DIR/assets/connection-flow.test.mjs"
node --test "$ROOT_DIR/assets/user-connection.test.mjs"
node --test "$ROOT_DIR/assets/diagnostics.test.mjs"
python3 "$ROOT_DIR/sidecar/scripts/test-token-usage.py"
python3 "$ROOT_DIR/sidecar/scripts/test-diagnostics.py"
python3 "$ROOT_DIR/sidecar/scripts/test-image-generation.py"
wp --path="$WP_PATH" eval-file "$ROOT_DIR/scripts/verify.php"
