#!/usr/bin/env bash
set -euo pipefail

usage() {
	cat <<'EOF'
Usage: install-systemd.sh [options]

Installs the Codex WordPress sidecar as a systemd service and writes a shared
env file that the WordPress plugin can auto-detect.

Options:
  --service-name NAME   systemd service name (default: codex-wp-sidecar)
  --plugin-dir PATH     absolute plugin directory path
  --env-file PATH       shared env file path (default: /etc/<service-name>.env)
  --unit-file PATH      systemd unit path (default: /etc/systemd/system/<service-name>.service)
  --python-bin PATH     python3 binary to use
  --codex-bin PATH      codex binary to use
  --storage-root PATH   sidecar storage root (default: /var/lib/codex-wp)
  --host HOST           bind host (default: 127.0.0.1)
  --port PORT           bind port (default: 4317)
  --token TOKEN         shared bearer token; generated if omitted
  --php-group GROUP     group that should be able to read the shared env file
  --no-start            install and enable the service without starting it
  --dry-run             print planned files without writing them
  --help                show this help text
EOF
}

fail() {
	printf 'Error: %s\n' "$*" >&2
	exit 1
}

detect_php_group() {
	local candidate wordpress_group

	wordpress_group="$(detect_wordpress_group)"

	for candidate in "${PHP_GROUP:-}" "${wordpress_group}" www-data apache nginx wwwrun _www; do
		if [[ -n "${candidate}" ]] && getent group "${candidate}" >/dev/null 2>&1; then
			printf '%s\n' "${candidate}"
			return
		fi
	done

	id -gn
}

detect_wordpress_root() {
	local dir

	dir="${PLUGIN_DIR}"

	while [[ -n "${dir}" ]] && [[ "${dir}" != "/" ]]; do
		if [[ -f "${dir}/wp-config.php" ]]; then
			printf '%s\n' "${dir}"
			return
		fi

		dir="$(dirname "${dir}")"
	done
}

detect_wordpress_group() {
	local wordpress_root

	wordpress_root="$(detect_wordpress_root)"

	if [[ -n "${wordpress_root}" ]] && [[ -f "${wordpress_root}/wp-config.php" ]]; then
		stat -c '%G' "${wordpress_root}/wp-config.php"
	fi
}

detect_sudo_user_home() {
	if [[ -n "${SUDO_USER:-}" ]]; then
		getent passwd "${SUDO_USER}" | cut -d: -f6
	fi
}

detect_codex_bin() {
	local sudo_home candidate

	if command -v codex >/dev/null 2>&1; then
		command -v codex
		return
	fi

	for candidate in /usr/local/bin/codex /usr/bin/codex; do
		if [[ -x "${candidate}" ]]; then
			printf '%s\n' "${candidate}"
			return
		fi
	done

	sudo_home="$(detect_sudo_user_home)"

	for candidate in \
		"${sudo_home}/.local/bin/codex" \
		"${sudo_home}/.cargo/bin/codex" \
		"${sudo_home}/bin/codex"
	do
		if [[ -n "${sudo_home}" ]] && [[ -x "${candidate}" ]]; then
			printf '%s\n' "${candidate}"
			return
		fi
	done
}

detect_node_bin() {
	local candidate resolved_codex_dir linked_codex_bin sudo_home

	if command -v node >/dev/null 2>&1; then
		command -v node
		return
	fi

	sudo_home="$(detect_sudo_user_home)"

	if [[ -n "${CODEX_BIN:-}" ]]; then
		linked_codex_bin="$(readlink "${CODEX_BIN}" 2>/dev/null || true)"
		resolved_codex_dir="$(dirname "$(readlink -f "${CODEX_BIN}")")"

		for candidate in \
			"$(dirname "${linked_codex_bin}")/node" \
			"${resolved_codex_dir}/node" \
			"$(dirname "${CODEX_BIN}")/node"
		do
			if [[ -x "${candidate}" ]]; then
				printf '%s\n' "${candidate}"
				return
			fi
		done
	fi

	for candidate in \
		"${sudo_home}/.nvm/versions/node/current/bin/node" \
		"${sudo_home}/.nvm/versions/node"/*/bin/node \
		"${sudo_home}/.local/bin/node" \
		"${sudo_home}/bin/node"
	do
		if [[ -n "${sudo_home}" ]] && [[ -x "${candidate}" ]]; then
			printf '%s\n' "${candidate}"
			return
		fi
	done
}

build_runtime_path() {
	local default_path resolved_codex linked_codex node_bin dir
	local -a dirs ordered_dirs
	declare -A seen

	default_path="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
	dirs=()

	if [[ -n "${CODEX_BIN:-}" ]]; then
		dirs+=("$(dirname "${CODEX_BIN}")")
		linked_codex="$(readlink "${CODEX_BIN}" 2>/dev/null || true)"

		if [[ -n "${linked_codex}" ]]; then
			dirs+=("$(dirname "${linked_codex}")")
		fi

		resolved_codex="$(readlink -f "${CODEX_BIN}")"
		dirs+=("$(dirname "${resolved_codex}")")
	fi

	node_bin="$(detect_node_bin || true)"

	if [[ -n "${node_bin}" ]]; then
		dirs+=("$(dirname "${node_bin}")")
	fi

	IFS=: read -r -a ordered_dirs <<< "${default_path}"
	dirs+=("${ordered_dirs[@]}")

	ordered_dirs=()

	for dir in "${dirs[@]}"; do
		if [[ -z "${dir}" ]] || [[ ! -d "${dir}" ]]; then
			continue
		fi

		if [[ -n "${seen[${dir}]:-}" ]]; then
			continue
		fi

		seen["${dir}"]=1
		ordered_dirs+=("${dir}")
	done

	IFS=:
	printf '%s\n' "${ordered_dirs[*]}"
	unset IFS
}

render_env_file() {
	cat <<EOF
CODEX_BIN=${CODEX_BIN}
PATH=${RUNTIME_PATH}
CODEX_WP_STORAGE_ROOT=${STORAGE_ROOT}
CODEX_WP_HOST=${HOST}
CODEX_WP_PORT=${PORT}
CODEX_WP_RUNTIME_BASE_URL=${RUNTIME_BASE_URL}
CODEX_WP_BEARER_TOKEN=${TOKEN}
EOF
}

render_unit_file() {
	cat <<EOF
[Unit]
Description=Codex WordPress Sidecar
After=network.target

[Service]
Type=simple
WorkingDirectory=${PLUGIN_DIR}
EnvironmentFile=${ENV_FILE}
ExecStart=${PYTHON_BIN} ${PLUGIN_DIR}/sidecar/app/main.py
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
}

sync_wordpress_runtime_settings() {
	local wordpress_root
	local -a wp_cmd

	wordpress_root="$(detect_wordpress_root)"

	if [[ -z "${wordpress_root}" ]]; then
		printf 'Warning: could not find a WordPress root above %s; skipped syncing WordPress options.\n' "${PLUGIN_DIR}" >&2
		return
	fi

	if ! command -v wp >/dev/null 2>&1; then
		printf 'Warning: wp-cli is not installed; skipped syncing WordPress runtime options.\n' >&2
		return
	fi

	wp_cmd=(wp --path="${wordpress_root}")

	if [[ "${EUID}" -eq 0 ]]; then
		wp_cmd+=(--allow-root)
	fi

	if ! "${wp_cmd[@]}" option update codex_runtime_base_url "${RUNTIME_BASE_URL}" >/dev/null; then
		printf 'Warning: failed to sync codex_runtime_base_url in %s.\n' "${wordpress_root}" >&2
	fi

	if ! "${wp_cmd[@]}" option update codex_runtime_bearer_token "${TOKEN}" >/dev/null; then
		printf 'Warning: failed to sync codex_runtime_bearer_token in %s.\n' "${wordpress_root}" >&2
	fi
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SERVICE_NAME="codex-wp-sidecar"
ENV_FILE=""
UNIT_FILE=""
PYTHON_BIN="${PYTHON_BIN:-}"
CODEX_BIN="${CODEX_BIN:-}"
STORAGE_ROOT="/var/lib/codex-wp"
HOST="127.0.0.1"
PORT="4317"
TOKEN=""
PHP_GROUP="${PHP_GROUP:-}"
RUNTIME_PATH=""
NO_START=0
DRY_RUN=0

while (($# > 0)); do
	case "$1" in
		--service-name)
			SERVICE_NAME="$2"
			shift 2
			;;
		--plugin-dir)
			PLUGIN_DIR="$2"
			shift 2
			;;
		--env-file)
			ENV_FILE="$2"
			shift 2
			;;
		--unit-file)
			UNIT_FILE="$2"
			shift 2
			;;
		--python-bin)
			PYTHON_BIN="$2"
			shift 2
			;;
		--codex-bin)
			CODEX_BIN="$2"
			shift 2
			;;
		--storage-root)
			STORAGE_ROOT="$2"
			shift 2
			;;
		--host)
			HOST="$2"
			shift 2
			;;
		--port)
			PORT="$2"
			shift 2
			;;
		--token)
			TOKEN="$2"
			shift 2
			;;
		--php-group)
			PHP_GROUP="$2"
			shift 2
			;;
		--no-start)
			NO_START=1
			shift
			;;
		--dry-run)
			DRY_RUN=1
			shift
			;;
		--help|-h)
			usage
			exit 0
			;;
		*)
			fail "unknown argument: $1"
			;;
	esac
done

if [[ -z "${ENV_FILE}" ]]; then
	ENV_FILE="/etc/${SERVICE_NAME}.env"
fi

if [[ -z "${UNIT_FILE}" ]]; then
	UNIT_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
fi

if [[ -z "${PYTHON_BIN}" ]]; then
	PYTHON_BIN="$(command -v python3 || true)"
fi

if [[ -z "${PYTHON_BIN}" ]]; then
	fail "python3 was not found"
fi

if [[ -z "${CODEX_BIN}" ]]; then
	CODEX_BIN="$(detect_codex_bin || true)"
fi

if [[ -z "${CODEX_BIN}" ]]; then
	fail "codex was not found; re-run with --codex-bin /full/path/to/codex"
fi

RUNTIME_PATH="$(build_runtime_path)"

if [[ ! -d "${PLUGIN_DIR}" ]]; then
	fail "plugin directory does not exist: ${PLUGIN_DIR}"
fi

if [[ ! -f "${PLUGIN_DIR}/sidecar/app/main.py" ]]; then
	fail "sidecar app not found under: ${PLUGIN_DIR}/sidecar/app/main.py"
fi

if [[ -z "${TOKEN}" ]]; then
	TOKEN="$("${PYTHON_BIN}" - <<'PY'
import secrets
print(secrets.token_hex(32))
PY
)"
fi

PHP_GROUP="${PHP_GROUP:-$(detect_php_group)}"
RUNTIME_BASE_URL="http://${HOST}:${PORT}"

if [[ "${DRY_RUN}" -eq 1 ]]; then
	printf 'Env file: %s\n' "${ENV_FILE}"
	render_env_file
	printf '\nUnit file: %s\n' "${UNIT_FILE}"
	render_unit_file
	exit 0
fi

if [[ "${EUID}" -ne 0 ]]; then
	fail "run this script as root to write ${ENV_FILE} and ${UNIT_FILE}"
fi

mkdir -p "$(dirname "${ENV_FILE}")" "$(dirname "${UNIT_FILE}")" "${STORAGE_ROOT}"

render_env_file >"${ENV_FILE}"
chgrp "${PHP_GROUP}" "${ENV_FILE}" 2>/dev/null || true
chmod 640 "${ENV_FILE}"

render_unit_file >"${UNIT_FILE}"
chmod 644 "${UNIT_FILE}"

sync_wordpress_runtime_settings

systemctl daemon-reload

if [[ "${NO_START}" -eq 1 ]]; then
	systemctl enable "${SERVICE_NAME}"
else
	systemctl enable "${SERVICE_NAME}"

	if systemctl is-active --quiet "${SERVICE_NAME}"; then
		systemctl restart "${SERVICE_NAME}"
	else
		systemctl start "${SERVICE_NAME}"
	fi
fi

if command -v curl >/dev/null 2>&1 && [[ "${NO_START}" -eq 0 ]]; then
	health_ok=0

	for _ in 1 2 3 4 5; do
		if curl -fsS "${RUNTIME_BASE_URL}/healthz" >/dev/null 2>&1; then
			health_ok=1
			break
		fi

		sleep 1
	done

	if [[ "${health_ok}" -ne 1 ]]; then
		printf 'Warning: %s did not answer %s/healthz yet.\n' "${SERVICE_NAME}" "${RUNTIME_BASE_URL}" >&2
	fi
fi

printf 'Installed %s\n' "${SERVICE_NAME}"
printf 'Shared runtime config: %s\n' "${ENV_FILE}"
printf 'WordPress will auto-detect the runtime URL and bearer token from that file.\n'
printf 'Runtime URL: %s\n' "${RUNTIME_BASE_URL}"
