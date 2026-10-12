# Codex WordPress Sidecar

Local runtime service for `scriptorium-ai-provider-for-codex`.

It wraps `codex app-server`, stores per-user ChatGPT/Codex auth state on disk, and exposes a localhost-only HTTP API for the WordPress plugin. Text-to-image generation is available through this sidecar only when the connected user's Codex runtime reports image-generation capability.

## Requirements

- Python 3.11+
- `codex` installed and available on the host
- a host that can run a local service or daemon

## Recommended Quick Start

1. On the same host as WordPress, confirm Python 3.11+ and the `codex` CLI are installed.
2. Copy `sidecar/systemd/codex-wp-sidecar.service` to `/etc/systemd/system/codex-wp-sidecar.service` and replace `/path/to/wp-content/plugins/scriptorium-ai-provider-for-codex` with the installed plugin directory.
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

Codex child processes receive only `PATH`, `PATHEXT`, `SYSTEMROOT`, `WINDIR`,
`SSL_CERT_FILE`, `SSL_CERT_DIR` and `CODEX_CA_CERTIFICATE` when present, plus
`CODEX_HOME`, `HOME` and `USERPROFILE` set to the user's Codex directory.
Provider keys, proxy settings and sidecar credentials are not forwarded. If the
host requires a private CA, set `SSL_CERT_FILE` (or `CODEX_CA_CERTIFICATE`) in
the sidecar's environment; these are forwarded. Proxy variables are not
forwarded; put the sidecar host on a network path that reaches `chatgpt.com`
directly. Codex uses the connected user's stored ChatGPT authentication.

Text and image threads use `approvalPolicy: "never"`, `sandbox: "read-only"` and
disable shell execution, local image viewing, both multi-agent backends and web
search. Native image generation is enabled only for image threads when advertised by Codex.
These settings are enforced by the sidecar on every generation thread.

Generation threads also select no filesystem environments and disable stable
environment tools. This prevents native image tools from reading local reference
paths and removes `apply_patch`, while preserving new image generation and
Codex's generated-artifact storage. The empty environment selection requires
`experimentalApi` and is qualified against Codex 0.162.1; verify compatibility
before using a different Codex version. Restart the sidecar after updating its source.

Per-user directories separate Codex state within the sidecar's system account;
they do not enforce operating-system isolation between users. Separate system
accounts or filesystem confinement remain a deployment hardening step.

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

If PHP can read `/etc/codex-wp-sidecar.env`, the WordPress plugin can auto-detect both the Runtime URL and bearer token, and the settings fields become informational.

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
- `POST /v1/responses/image`
- `POST /v1/session/clear`
