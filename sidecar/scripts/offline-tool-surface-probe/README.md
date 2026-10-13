# Offline Tool-Surface Probe

This qualification helper runs the real sidecar text/image paths and official
Codex **0.162.1** against a loopback HTTPS mock. The Docker container has
`--network none`, receives no real credentials, and uses an unsigned synthetic
token. It observes the tool specs offered to `gpt-5.5` (direct-tool) and
`gpt-6-astra` (code-mode), and returns a fixed normal text completion on both
text and image threads. It never returns function calls, code-mode programs
or other tool calls.

Text scenarios verify delivery through the real sidecar. Image scenarios verify
the offered image-thread tools, successful Codex turn completion, and the
sidecar's rejection of text-only output. They **do not qualify successful image
delivery through real Codex**. Codex 0.162.1 parses a directly supplied hosted
`image_generation_call` but its live non-tool item path does not forward that
result as an image item; its working image path requires an image-tool call.
The observational-only scope was retained explicitly during qualification.
Image delivery remains covered separately by `test-image-generation.py` and
`test-generation-contract.py` with inert protocol fixtures. The matching
code-mode host is installed, but no model-supplied program is run.

## Inputs

Use Linux x86_64, Docker, Bash, `sha256sum`, and a locally available
`node:24-bookworm` image (Python 3.11 and OpenSSL included). The runner never
downloads assets or pulls an image. Obtain these inputs before running it:

| Official asset | SHA-256 |
| --- | --- |
| [Codex musl archive](https://github.com/openai/codex/releases/download/rust-v0.162.1/codex-x86_64-unknown-linux-musl.tar.gz) | `86f268d81b898f3e144c5ecff2ad2fda2c5802e042d6195b72538fe0fa125057` |
| [Code-mode host musl archive](https://github.com/openai/codex/releases/download/rust-v0.162.1/codex-code-mode-host-x86_64-unknown-linux-musl.tar.gz) | `458239ea45c1fc508d89695f180ab352f2834709a8a46929a4466f4f04399ada` |
| [models-manager/models.json](https://raw.githubusercontent.com/openai/codex/092d3acd6bec3e3a14bdc7e7a2810ab628ab759d/codex-rs/models-manager/models.json) | `943ca7fe1d19ed019054158303f0dbbd80b3bef43b3aa425a71aa7cb3fb52c2b` |

The archive hashes match the official GitHub release asset digests. The model
file is pinned to the commit behind `rust-v0.162.1`; its hash was calculated from
that source. `run.sh` also checks the extracted binary hashes before executing.

```bash
CODEX_BIN=/path/to/codex-x86_64-unknown-linux-musl \
CODEX_CODE_MODE_HOST_BIN=/path/to/codex-code-mode-host-x86_64-unknown-linux-musl \
MODELS_JSON=/path/to/models.json \
PROBE_OUT=/path/to/disposable-evidence-directory \
bash sidecar/scripts/offline-tool-surface-probe/run.sh
```

`PROBE_OUT` is optional; otherwise a temporary output directory is created.
`PROBE_IMAGE` may identify an already-installed compatible image. The image ID
and the sidecar, probe and model-file hashes are recorded in `probe-report.json`.
The runner creates a throwaway CA and localhost certificate inside the container
and forwards the CA through `SSL_CERT_FILE`, exercising the real child-process
passthrough. Its exit trap removes its own named container even if interrupted.

## Assertions And Evidence

Each scenario must make exactly one inspectable model HTTP request, expose a
nonempty surface in the expected direct/code-mode format, and report a completed
turn under the echoed generation policy. Text scenarios must deliver the exact
fixed text; image scenarios must reject its text-only result. The fixed
model-facing allowlist is:

`request_user_input`, `request_user_input_async`, `get_goal`, `create_goal`,
`update_goal`, `list_mcp_resources`, `list_mcp_resource_templates`,
`read_mcp_resource`, `clock.sleep`, `functions.exec`, `functions.wait`, plus
`image_gen.imagegen` only for image threads. Code-mode envelopes also use the
explicit aliases `functions.request_user_input` and
`functions.request_user_input_async`.

Nested code-mode names are checked separately: `get_goal`, `create_goal`,
`update_goal`, the three MCP resource helpers, and `clock__curr_time` (the
read-only clock helper observed on 0.162.1). Only image threads may include
`image_gen__imagegen`. Unknown nested names fail the probe even when the outer
`functions.exec` envelope is allowed.

Every backend path and response status is recorded, together with the full
offered specs from both top-level `tools` and `additional_tools` input items.
Only the enumerated model, account/settings, rate-limit and analytics paths are
accepted; account and analytics payloads are not saved. Any `/ps/`, `/plugins/`,
`/ps/mcp` or other unexpected endpoint fails. Codex
0.162.1 attempts a websocket upgrade on the **model responses endpoint**; the
mock refuses it with HTTP 426 and Codex falls back to HTTP. All upgrades must
be limited to that endpoint and refused; HTTP 101 or an upgrade elsewhere fails.
This proves absence of Apps/Plugins traffic in the mock, not absence of the
model transport's websocket attempt.

Outputs are `probe-report.json` (observations and assertions) and
`probe-summary.md` (qualification table). Auth headers are not logged. Exit 1
means an assertion failed; retain that evidence when investigating.

## Mock Setup Details

- Workspace routing requires **HTTPS**; a plain HTTP mock does not follow the
  same account-routing path.
- The synthetic user's `config.toml` redirects both base URLs to loopback and
  disables request compression so the Python 3.11 mock can inspect JSON bodies.
  Image scenarios select their underlying text model through this test-only
  config; production `codex-image` sends no runtime model override.
- Code-mode models can place tool specs inside `additional_tools` input items;
  checking only the top-level `tools` array misses those tools.

The probe's per-user configuration exists solely to direct requests to the mock.
It is not a recommended production configuration and will intentionally trigger
the sidecar's per-user configuration diagnostic. No MCP server, hook or plugin
marketplace is configured. All runtime and TLS state is disposable. This helper
and its external inputs are excluded from the plugin ZIP by `/sidecar/scripts`.
