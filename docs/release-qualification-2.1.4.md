# v2.1.4 Local Release Qualification

Qualified on October 12, 2026, from v2.1.3 base revision
`dd3f8e1a269626707b72525740a99597c7db5135` plus the candidate changes on
`fix/sidecar-generation-security-2.1.4`. The tested tree was exported with
`git archive`, the candidate patch and the new source files. Its 105-file source
manifest, patch, command exit codes, logs, binary checksums and package manifest
are retained under `/tmp/opencode/codex-214/`. Final qualification/task records
were added afterward; the shipping bytes remain those tested here.

## Isolation Changes

Every app-server launch uses `--disable apps --disable plugins` and a scoped
child environment. Only operating-system path variables and the three CA trust
path variables are inherited. User homes are scoped; provider keys, bearer
tokens and database credentials are excluded. (The qualified tree also excluded
proxy variables; they are forwarded since the post-qualification change below.)

Text and image threads request approval `never`, sandbox `read-only`, no
environments, disabled shell/environment/image-view/multi-agent tools and
disabled web search. Image threads alone enable native image generation.
Generation fails with HTTP 502 `runtime_policy_rejected` unless Codex confirms
the approval, sandbox and empty environment selection. PHP preserves that error
without treating it as an authentication failure.

Explicit diagnostics now report the qualified CLI version, effective generation
policy/features and unexpected per-user configuration files. The per-user check
covers `config.toml`, `hooks.json`, `.env`, `AGENTS.md` and `AGENTS.override.md`
without reading their contents. It does not audit every host configuration layer.

The turn deadline is a wall-clock budget, not an inactivity timeout. Late queued
completions cannot escape it, and timeout cancellation is best-effort with a
five-second interrupt budget. Initialization timeouts also close the real child
process. Both cleanup edge cases had failing regressions before the fixes and
passing regressions afterward.

## Official Codex Inputs And Protocol Checks

| Input | SHA-256 |
| --- | --- |
| Codex 0.162.1 Linux x86_64 musl archive | `86f268d81b898f3e144c5ecff2ad2fda2c5802e042d6195b72538fe0fa125057` |
| Matching code-mode host archive | `458239ea45c1fc508d89695f180ab352f2834709a8a46929a4466f4f04399ada` |
| Model catalog from source commit `092d3acd6bec3e3a14bdc7e7a2810ab628ab759d` | `943ca7fe1d19ed019054158303f0dbbd80b3bef43b3aa425a71aa7cb3fb52c2b` |

Archive hashes match the official GitHub release asset digests. The extracted
executables are checked by the [probe runner](../sidecar/scripts/offline-tool-surface-probe/README.md).
The catalog hash was computed from that exact source commit.

The credential-free compatibility smoke passed **36 checks** against the real
Codex binary in a network-disabled container. It covers initialization, device-code
protocol availability, managed-auth refresh fields, capabilities, text/image
thread policy echoes, disabled features including Apps/Plugins, native image
feature selection, diagnostics and process restart. No login or inference was
performed by that smoke.

## Observational Tool-Surface Probe

The final probe ran the real sidecar code and Codex against a loopback HTTPS mock
in `docker run --network none`. A runtime-generated CA was forwarded through
`SSL_CERT_FILE`. All auth was synthetic and unsigned. The mock returned normal
text completions and never returned tool calls or code-mode programs.

| Scenario | Model | Completion handling | Tools | Nested tools | Model HTTP calls | Refused model WS upgrades |
| --- | --- | --- | ---: | ---: | ---: | ---: |
| text-code-mode | gpt-6-astra | Text delivered | 5 | 7 | 1 | 1 |
| text-direct-tool | gpt-5.5 | Text delivered | 4 | 0 | 1 | 1 |
| image-code-mode | gpt-6-astra | Text-only image result rejected | 5 | 8 | 1 | 1 |
| image-direct-tool | gpt-5.5 | Text-only image result rejected | 5 | 0 | 1 | 1 |

Direct text tools were `request_user_input`, `get_goal`, `create_goal` and
`update_goal`; image threads additionally offered `image_gen.imagegen`.
Code-mode envelopes offered `functions.exec`, `functions.wait`, the two
`functions.request_user_input` variants and `clock.sleep`. Nested tools were the
goal helpers, three MCP resource helpers and the read-only `clock__curr_time`;
only image threads additionally offered `image_gen__imagegen`. Both outer specs
and nested names were checked against fixed allowlists.

No final scenario contacted `/ps/`, `/plugins/` or `/ps/mcp`. A separate negative
control, removing only the launch flags, failed on Apps/Plugins requests in all
four scenarios, including `POST /backend-api/ps/mcp` and plugin discovery paths.
Its mock returned no connector tools or plugin bundles, so this comparison
observes backend traffic without executing external connector/plugin code.

Codex attempted a websocket upgrade only on the model responses endpoint. The
mock refused each with HTTP 426 and generation used HTTP fallback. No websocket
upgrade succeeded; absence of websocket attempts is not claimed.

**Approved scope correction:** The initial hosted-image completion experiment
failed: Codex 0.162.1 parses `image_generation_call`, but its live non-tool item
path does not forward that result as an image item. Successful native image
delivery requires an image-tool invocation. The operator explicitly chose to
retain the observational-only mock. Accordingly, this probe qualifies image-thread
surfaces and rejection of text-only output, **not successful real Codex image-tool
execution**. Image-delivery behavior is separately covered by inert sidecar
protocol fixtures.

## Complete Local Matrix

| Configuration | PHP 7.4.33 | PHP 8.3.35 |
| --- | --- | --- |
| WordPress 7.0.7, Core SDK 1.3.1 | Pass | Pass |
| WordPress 7.1.3, Core SDK 1.3.1 | Pass | Pass |
| WordPress 7.0.7 + AI 1.4.0 + MCP Adapter 0.7.0 | Pass | Pass |
| WordPress 7.1.3 + AI 1.4.0 + MCP Adapter 0.7.0 | Pass | Pass |

All eight configurations ran the complete `scripts/verify.sh` with actual exit
0. Each run includes PHP lint and release consistency checks, request-log writer
modes with 57/53/53 assertions, 41 runtime-client assertions, 32 SDK contract
cases with 53 assertions, 26 JavaScript tests, 91 Python unittest cases, five
token-usage assertions and the WordPress integration script. The Python suites
also passed on the container's Python 3.11.2 minimum-version environment.

Composer strict validation, locked installs and fresh PHPStan analysis passed
on PHP 7.4 and 8.3. Standalone SDK 1.5.0 passed the same 32 cases/53 assertions
on both PHP versions in isolated processes, without replacing Core's bundled
SDK or changing the candidate dependency lock.

All four AI/MCP configurations passed 11 persisted request-log assertions each
against disposable MySQL 8.4.11, covering success/error persistence, disabled
logging, failed INSERTs and duplicate counts. Logging was enabled before those
checks; each recorded four intercepted requests and zero live runtime requests.
Every matrix site's Core files and each AI/MCP plugin pair passed official
checksum verification.

## Qualified Package

- File: `scriptorium-ai-provider-for-codex-2.1.4.zip`
- Size: **130,790 bytes**
- Files: **48**, with no duplicate ZIP entries
- SHA-256: `8f8fef0b8a6f37209387e201fe4e79d5087a022e0a5f276a546a3f599dc75dc1`

Superseded by the rebuilt package recorded under "Post-Qualification Changes".

Every file matches both the frozen source and working tree; extraction followed
by `cmp` also passed for every packaged file. Development scripts, the probe,
internal docs, dependencies and local artifacts are excluded. Version metadata
is synchronized at 2.1.4, with the qualified Codex constant and CI pin at 0.162.1.

The exact ZIP installed and activated as a regular directory on disposable
WordPress 7.1.3 / PHP 8.3 / AI 1.4 / MCP 0.7. All installed hashes match the ZIP.
The installed package passed integration and the 11 persisted logging assertions.
Plugin Check 2.1.0 reported no errors or warnings for the installed ZIP. The
source-export release-style check had zero `ERROR` rows and one
`github_directory` warning for `.github`, which is absent from the ZIP. The
checker can return exit 0 with findings; its output was inspected independently.

## Retained Failures And Configuration Findings

The first probe run retained the failed hosted-image experiments and identified
normal telemetry/account-settings endpoints missing from the mock's initial
path list. The revised fixed path list includes those exact endpoints while
continuing to reject Apps/Plugins paths and unknown endpoints. The final probe
and its negative control are retained separately.

The initial Composer command used the wrong executable path; the corrected
`/usr/local/bin/composer` commands passed. The initial package validator expected
a clean-completion string even for the source export's non-shipping `.github`
warning; final validation instead required zero errors and that exact excluded
warning, while still requiring an entirely clean installed package.

The real-binary `-c mcp_servers={}` experiment did **not** suppress a server
defined in a throwaway `config.toml`: the benign placeholder ran with and without
the override. It was not adopted. Hosts must keep MCP servers, hooks and plugin
marketplaces out of applicable Codex configuration layers. Per-user directories
under one OS account remain a logical state boundary, not OS confinement.

## Tools And Production Boundary

Tools were WP-CLI 2.12.0, Composer 2.10.3, Node 24.21.0, Python 3.14.4 on the host
and Python 3.11.2 in the offline container. PHP versions and the Node/MySQL
container digests are saved with the command evidence.

Not performed for this qualification: real account login or authenticated/billable
generation, successful real Codex image-tool execution, Windows sidecar validation,
production deployment, per-user OS-account confinement, or GitHub Actions runs.
Historical authenticated/adversarial session results are not substituted for this
observational probe. Intended-host checks in [the local release gate](local-release-gate.md)
remain separate from this local qualification. No push, PR or publication is
part of these local checks.

## Post-Qualification Changes (October 12, 2026)

An independent review of the branch was triaged after the qualification above.
Three findings held: proxy variables were withheld from Codex, a failed login
start leaked a Codex process, and Codex launch errors were discarded. The source
changes below were made on the branch after the qualified bytes, so the package
recorded above was superseded; the rebuilt package and re-run matrix are
recorded at the end of this section.

- `codex_process_environment()` also forwards `HTTP_PROXY`, `HTTPS_PROXY`,
  `ALL_PROXY` and `NO_PROXY` in any letter case. Codex 0.162.1 honors them by
  default (`respect_system_proxy` is off, so the transport-default route reads
  them) and 2.1.3 forwarded the whole environment, so withholding them would have
  broken proxy-only hosts silently; no diagnostics row can detect that failure.
- `RuntimeState.start_login` closes the app-server session when initialization
  or the device-code request fails. Previously the child stayed alive, holding
  the user's `CODEX_HOME`, until the sidecar restarted.
- `JsonRpcSession` drains the child's stderr into a five-line tail (240
  characters per line) and appends it to unexpected-exit errors, which are also
  written once to the sidecar's own stderr. A refused launch now reads
  `Unknown feature flag: …` instead of only an exit code.
- `readme.txt` records the proxy forwarding, the qualified-release requirement
  and the login fix; the sidecar documentation, spec and plan were aligned.

Re-run on the changed tree (host Python 3.14.4, PHP 8.4.26, Node 24, local Codex
CLI 0.162.1, no login or generation): every step of `scripts/verify.sh` except
the WP-CLI integration script passed, including `test-diagnostics.py` 32,
`test-generation-contract.py` 43, `test-process-security.py` 6 (two new
regressions: login-start cleanup against a real child, stderr tail reporting),
`test-image-generation.py` 9, `test-account-snapshot.py` 3, `test-token-usage.py`,
the standalone PHP request-log, runtime-client and SDK contract tests and the
JavaScript tests. `test-codex-compatibility.py --version 0.162.1` passed its 36
checks on the host (not in a network-disabled container). Against the real
binary, a deliberately unknown `--disable` name failed `initialize` in 0.01 s
with `Codex reported: … Error: Unknown feature flag: …` in both the generation
error and the diagnostics `app_server` row.

Rebuilt package and matrix (October 13, 2026, commits `7c85cdb` and `aa5b97c`):
the eight-configuration matrix was rebuilt on disposable sites (WordPress 7.0.7
and 7.1.3, PHP 7.4.33 and 8.3.35, with and without AI 1.4.0 + MCP Adapter 0.7.0,
MySQL 8.4 at the CI digest). Every configuration passed the complete
`scripts/verify.sh` with exit 0, and the four AI/MCP configurations also passed
the 11 persisted request-log assertions with zero live runtime requests.
`scripts/package-release.sh` produced `scriptorium-ai-provider-for-codex-2.1.4.zip`:
**132,638 bytes**, **48 files**, no duplicate entries, SHA-256
`eee2485e24a5cf79bccb970840486f986d48a22bf7dd185b030322abc9ee1c8b`; every
extracted file matches the committed tree. Plugin Check 2.1.0 reported zero
`ERROR` rows and only the excluded `.github` `github_directory` warning on the
source export, and no errors or warnings on the installed ZIP, which also passed
the integration script and the 11 persisted logging assertions on WordPress
7.1.3 / PHP 8.3 / AI 1.4 / MCP 0.7.

Still not performed: Windows sidecar validation and authenticated text/image
generation.
