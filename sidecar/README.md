# Codex WordPress Sidecar

Local runtime service for `ai-provider-for-codex`.

It wraps `codex app-server`, stores per-user ChatGPT/Codex auth state on disk, and exposes a localhost-only HTTP API for the WordPress plugin.

## Requirements

- Python 3.11+
- `codex` installed and available on the host
- a host that can run a local service or daemon

## Environment

- `CODEX_BIN` default: `/usr/local/bin/codex`
- `CODEX_WP_STORAGE_ROOT` default: `/var/lib/codex-wp`
- `CODEX_WP_HOST` default: `127.0.0.1`
- `CODEX_WP_PORT` default: `4317`
- `CODEX_WP_BEARER_TOKEN` required
- `CODEX_RUNTIME_REQUEST_TIMEOUT` default: `60`
- `CODEX_RUNTIME_TURN_TIMEOUT` default: `300`
- `CODEX_RUNTIME_LOGIN_TIMEOUT` default: `1800`

## Run Manually

```bash
export CODEX_WP_BEARER_TOKEN="replace-me"
python3 sidecar/app/main.py
```

## Install As A systemd Service

```bash
sudo ./sidecar/scripts/install-systemd.sh
```

That installer:

- writes `/etc/codex-wp-sidecar.env`
- writes a real systemd unit with the current plugin path
- enables and starts the service
- lets the WordPress plugin auto-detect the runtime URL and bearer token from the shared env file

## WordPress Plugin Settings

Configure the plugin with:

- Runtime URL: `http://127.0.0.1:4317`
- Runtime bearer token: the same value used for `CODEX_WP_BEARER_TOKEN`

If `/etc/codex-wp-sidecar.env` is readable by PHP, the plugin can load both values automatically and the settings fields become informational.

## API Surface

- `GET /healthz`
- `POST /v1/login/start`
- `GET /v1/login/status`
- `GET /v1/account/snapshot`
- `POST /v1/responses/text`
- `POST /v1/session/clear`
