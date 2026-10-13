# Codex WordPress Sidecar

Local runtime service for `scriptorium-ai-provider-for-codex`.

It wraps `codex app-server`, stores per-user ChatGPT/Codex auth state on disk, and exposes a localhost-only HTTP API for the WordPress plugin. Text-to-image generation is available through this sidecar only when the connected user's Codex runtime reports image-generation capability.

## Requirements

- Python 3.11+
- `codex` installed and available on the host (this release is qualified against **0.162.1**; another release can be refused at app-server launch or at thread start, see below)
- a host that can run a local service or daemon

## Recommended Quick Start

1. On the same host as WordPress, confirm Python 3.11+ and the `codex` CLI are installed.
2. Copy `sidecar/systemd/codex-wp-sidecar.service` to `/etc/systemd/system/codex-wp-sidecar.service` and replace `/path/to/wp-content/plugins/scriptorium-ai-provider-for-codex` with the installed plugin directory.
3. Create `/etc/codex-wp-sidecar.env` with the environment values below, then enable and start the service.
4. In WordPress, open `Settings > Codex Provider`. If the values were not auto-detected, enter them manually.
5. Run **Settings > Codex Provider > Diagnostics** and confirm all rows pass, then check the Codex card under **Settings > Connectors**.
6. Each user finishes setup from `Users > Codex Provider` by connecting their own account.

## Environment

- `CODEX_BIN` default: `/usr/local/bin/codex`
- `CODEX_WP_STORAGE_ROOT` default: `/var/lib/codex-wp`
- `CODEX_WP_HOST` default: `127.0.0.1`
- `CODEX_WP_PORT` default: `4317`
- `CODEX_WP_BEARER_TOKEN` required
- `CODEX_RUNTIME_REQUEST_TIMEOUT` default: `60`
- `CODEX_RUNTIME_TURN_TIMEOUT` default: `300` (wall-clock budget from `turn/start` through turn completion, rather than a fresh timeout between events)
- `CODEX_RUNTIME_LOGIN_TIMEOUT` default: `1800`

### Generation Isolation (Three Layers)

The sidecar protects user auth and the host through three defensive layers:

1. **Process environment:** Codex child processes receive only `PATH`, `PATHEXT`, `SYSTEMROOT`, `WINDIR`, the CA trust paths `SSL_CERT_FILE`, `SSL_CERT_DIR` and `CODEX_CA_CERTIFICATE`, and the proxy settings `HTTP_PROXY`, `HTTPS_PROXY`, `ALL_PROXY` and `NO_PROXY` (in any letter case) when present, plus `CODEX_HOME`, `HOME` and `USERPROFILE` set to the user's directory. Provider keys, sidecar tokens, database credentials and every other variable are not forwarded. If the host requires a private CA or reaches `chatgpt.com` through a proxy, set those variables in the sidecar's environment; Codex honors them by default.
2. **Process features:** The sidecar launches `codex app-server --disable apps --disable plugins`. This prevents generations from accessing account-backed ChatGPT Apps connectors or downloading and executing plugin-declared MCP servers on the host. The `--disable` flag fails closed on unrecognized feature flags.
3. **Thread policy:** Every generation thread enforces `approvalPolicy: "never"`, `sandbox: "read-only"`, and `environments: []`. It disables shell tools, environment tools (`apply_patch`), local image viewing, multi-agent execution, and web search. Native image generation is enabled only on image threads when advertised by Codex. The empty environment selection requires `experimentalApi` and is qualified against Codex 0.162.1. If Codex does not echo this exact policy on `thread/start`, the sidecar refuses to start the turn (HTTP 502 `runtime_policy_rejected`).

On timeout, the sidecar attempts `turn/interrupt` for up to five seconds, closes the process, and returns HTTP 504 `request_timeout` without partial output. The default 300-second turn budget stays below the WordPress-side 360-second generation fallback. Setup and post-turn account reads have separate RPC budgets; explicit consumer timeouts and hosting deadlines still apply.

### Host Configuration And Diagnostics

On the qualified baseline with no externally configured tools, built-in model tools cannot read host files or launch host commands under this policy. Codex itself still reads authentication and writes runtime state and generated artifacts.

The host must not define MCP servers, hooks or plugin marketplaces in any Codex configuration layer. Such MCP servers and enabled, trusted or managed hooks run as ordinary processes under the sidecar's OS account, outside the per-thread policy. This includes system configuration (`/etc/codex/` on Unix or `%ProgramData%\\OpenAI\\Codex\\` on Windows), user configuration and any applicable managed or project configuration. `--disable plugins` removes account-synced plugins; it does not suppress separately configured MCP servers or hooks.

Per-user directories should contain authentication and Codex runtime state only. Diagnostics warn when they contain `config.toml`, `hooks.json`, `.env`, `AGENTS.md` or `AGENTS.override.md`, listing up to five paths to review. Codex loads these files when present; `.env` can reintroduce variables excluded from the parent environment, and the AGENTS files add instructions. The check reports file paths without reading their contents. It does not scan every system or managed configuration layer.

If `codex app-server` exits while starting (for example because the installed release does not know the `apps` or `plugins` feature flag), the error returned to WordPress and the diagnostics `app-server handshake` row include the last lines Codex wrote to stderr, and the sidecar prints the same line to its own stderr (the service journal).

The other new diagnostics rows check the installed CLI against **0.162.1** and verify the echoed generation policy and disabled features on an unauthenticated ephemeral thread. Diagnostics are explicit, administrator-triggered probes; passive status reads remain health checks only. Restart the sidecar after updating its source or Codex version.

Per-user directories separate state under one OS account; they are not an OS isolation boundary. Separate service accounts or filesystem confinement are needed for that boundary.

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
- `GET /ping` (health alias)
- `GET /v1/diagnostics` (bearer-authenticated, explicit probe)
- `POST /v1/login/start`
- `GET /v1/login/status`
- `GET /v1/account/snapshot`
- `POST /v1/responses/text`
- `POST /v1/responses/image`
- `POST /v1/session/clear`
