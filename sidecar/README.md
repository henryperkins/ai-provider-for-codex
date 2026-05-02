# Codex WordPress Sidecar

Local runtime service for `ai-provider-for-codex`.

It wraps `codex app-server`, stores per-user ChatGPT/Codex auth state on disk, and exposes a localhost-only HTTP API for the WordPress plugin.

## Requirements

- Python 3.11+
- `codex` installed and available on the host
- a host that can run a local service or daemon

## Recommended Quick Start

1. On the same host as WordPress, confirm Python 3.11+ and the `codex` CLI are installed.
2. Copy `sidecar/systemd/codex-wp-sidecar.service` to `/etc/systemd/system/codex-wp-sidecar.service` and replace `/path/to/wp-content/plugins/ai-provider-for-codex` with the installed plugin directory.
3. Create `/etc/codex-wp-sidecar.env` with the environment values below, then enable and start the service.
4. In WordPress, open `Settings > Codex Provider`. If the values were not auto-detected, enter them manually.
5. Open `Settings > Connectors` and confirm Codex reports a healthy local runtime.
6. Each user finishes setup from `Users > Codex Provider` by connecting their own account.

## Environment

- `CODEX_BIN` default: `/usr/local/bin/codex`
- `CODEX_WP_STORAGE_ROOT` default: `/var/lib/codex-wp`
- `CODEX_WP_HOST` default: `127.0.0.1`
- `CODEX_WP_PORT` default: `4317`
- `CODEX_WP_BEARER_TOKEN` required
- `CODEX_RUNTIME_REQUEST_TIMEOUT` default: `60`
- `CODEX_RUNTIME_TURN_TIMEOUT` default: `300`
- `CODEX_RUNTIME_LOGIN_TIMEOUT` default: `1800`

## Manual Run For Testing

```bash
export CODEX_WP_BEARER_TOKEN="replace-me"
python3 sidecar/app/main.py
```

## Install As A systemd Service

This is the recommended path for most installs. The included
`sidecar/systemd/codex-wp-sidecar.service` file is a template; replace the
placeholder plugin path with the real installed plugin directory before copying
it into `/etc/systemd/system/`.

Create `/etc/codex-wp-sidecar.env` with values similar to:

```text
CODEX_BIN=/usr/local/bin/codex
CODEX_WP_STORAGE_ROOT=/var/lib/codex-wp
CODEX_WP_HOST=127.0.0.1
CODEX_WP_PORT=4317
CODEX_WP_RUNTIME_BASE_URL=http://127.0.0.1:4317
CODEX_WP_BEARER_TOKEN=replace-me-with-a-long-random-token
```

Then run:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now codex-wp-sidecar
```

If PHP can read `/etc/codex-wp-sidecar.env`, the WordPress plugin can auto-detect

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
