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

Production deployment (October 13, 2026, partial): the rebuilt 2.1.4 ZIP
(SHA-256 `eee2485e…1c8b`) was installed over the active plugin on hperkins.blog
through the WordPress.com plugin upload; the site reports version 2.1.4, active.
The Windows sidecar behind the production gateway was not updated in this step:
the gateway's public health endpoint still reports runtime version 2.1.2. The
diagnostics run and the authenticated text/image generations remain pending
until that host runs the 2.1.4 sidecar.

Still not performed: Windows sidecar validation and authenticated text/image
generation.

## Local Review Follow-Up (October 21, 2026)

Resumed from `f1afcd2d81d9f4000bfd35bc80ed3d2e2fa81bf6` with local,
uncommitted review fixes. Evidence is retained under
`/tmp/opencode/codex-214-resume-20261021/`, including the candidate patch,
source hashes, command logs/exit codes, tool and container versions, and probe
reports. This is a revised local candidate, not a publication or production update.

- Diagnostics now uses a 30-second WordPress HTTP budget. The CLI version,
  handshake and policy checks can consume 19 seconds, and child cleanup can add
  four more. The previous 20-second budget could hide the policy failure behind
  a transport timeout. Other control requests remain at 20 seconds, generation
  remains at 360 seconds, and explicit SDK timeouts still take precedence.
- Malformed tool specs no longer drop the mock's request record or response.
  Offered specs and parsing errors are retained and explicitly fail qualification.
  A new Docker-free regression suite covers both direct and additional-tool
  specs and runs in `scripts/verify.sh`. These regressions and the diagnostic
  timeout assertion failed before their fixes and passed afterward.
- The submission checklist now names the 2.1.4 candidate. The remediation plan's
  test and documentation steps now consistently describe proxy forwarding.
  The stderr-cleanup review comments did not require a production change:
  `_stderr_loop()` already closes the stream in `finally`, and the six process
  security tests passed with `ResourceWarning` promoted to an error.

Fresh verification passed on disposable WordPress 7.1.3 / PHP 8.4.26 / Core SDK
1.3.1, both without and with AI 1.4.0 + MCP Adapter 0.7.0, using MySQL 8.4.11.
The complete `scripts/verify.sh`, strict Composer validation, locked dependency
dry-run and PHPStan passed. Persisted logging passed all 11 assertions with four
intercepted requests and zero live runtime requests. All inert Python suites also
passed on Python 3.11.2 in a network-disabled container. Core and the installed
AI/MCP/Plugin Check plugins passed official checksum verification. The older
PHP and WordPress matrix legs documented above were not rerun in this follow-up.

Official checksum-verified Codex 0.162.1 passed all 36 compatibility checks and
the four-scenario observational tool-surface probe in network-disabled containers.
The observed surfaces match the table above. Codex 0.163.0 separately passed the
36-check credential-free host smoke; it has not received full qualification or
the tool-surface probe, and the qualified pin remains 0.162.1.

A temporary combined tree of PRs #6 and #7 had one adjacent-insertion conflict
in `scripts/test-runtime-client.php`. Keeping both test blocks and including the
30-second diagnostics fix passed the complete verification script, including
165 runtime-client assertions. Neither branch nor `main` was merged or pushed.

The rebuilt local ZIP contains **48 files**, **132,861 bytes**, SHA-256
`8cb2536eb47e97c4d397dd57643026a13b3021cf40d3b3c57c14bca99952c3a4`.
Every file matches the working tree and its regular-directory installation on
the disposable site; there are no duplicate ZIP entries. The installed ZIP
passed integration, the 11 persisted logging assertions and Plugin Check 2.1.0
with no findings. The source check had only the excluded `.github` warning.
This ZIP supersedes the October 13 package **for local candidate testing only**;
it was not installed on production.

Still pending: Windows sidecar validation, current production-runtime verification,
real account login/refresh and authorized text/image generation, the remaining
release matrix, and explicit merge/release/deployment authorization. No billable
generation, production restart, public release or production plugin removal
was performed. GitHub Verify remains blocked before runner assignment; all five
jobs in run `38334462161` have `runner_id: 0` and no executable steps.

## Approved Combined Candidate (October 21, 2026)

The operator authorized committing, pushing, reconciling the PRs and completing
the release gates. Review fixes were committed and pushed as `6f66591`; merge
commit `a90fedf` brings PR #6 into the PR #7 branch while retaining both sets of
runtime-client tests. Subsequent qualification fixes are in tested revision
`e9b838231a4502485ebf161cc6a5b2db0115d184`. No PR has been merged into `main`.

The final source was frozen with `git archive`. The source archive and hashes,
qualification harnesses, command exit codes, logs, dependency locks and package
manifests are retained under `/tmp/opencode/codex-214-final-20261021/`.
Documentation added after the tested revision does not change shipping files.

### Findings Resolved During Qualification

- The first combined ZIP exposed 13 Plugin Check findings on arguments to the
  proxy-exception helper. That helper already escapes the displayed message.
  Three narrowly scoped, explained PHPCS annotations address the helper-call
  findings without changing status codes, payloads or response behavior.
- The previous nested-tool regex could miss a second member of a code-mode
  declaration. The probe now accepts only the audited one-method declaration
  format and fails closed on extra members, uninspected declarations or unknown
  shapes. Regressions reproduce the missed-member case and preserve the rejected
  specs as evidence. The real four-scenario probe still passes.
- The plan no longer claims disabling the shell tool establishes Windows
  confinement. Windows behavior remains an unresolved security qualification;
  the observational probe executes no model-supplied programs.
- The first focused SDK command selected the locked 1.3.1 SDK because the
  harness used the wrong override variable. The corrected harness explicitly
  loads isolated SDK 1.5.0 and checks its reported version. Initial logs and the
  failed installed-ZIP findings are retained separately under
  `/tmp/opencode/codex-214-approved-20261021/` rather than counted as final passes.

### Final Local Results

| Configuration | PHP 7.4.33 | PHP 8.3.35 |
| --- | --- | --- |
| WordPress 7.0.7, Core SDK 1.3.1 | Pass | Pass |
| WordPress 7.1.3, Core SDK 1.3.1 | Pass | Pass |
| WordPress 7.0.7 + AI 1.4.0 + MCP Adapter 0.7.0 | Pass | Pass |
| WordPress 7.1.3 + AI 1.4.0 + MCP Adapter 0.7.0 | Pass | Pass |

Every configuration passed the complete `scripts/verify.sh`, including 165
runtime-client assertions, 26 JavaScript tests and 100 Python unittest cases,
plus the five token-usage assertions. Strict Composer validation, locked installs
and fresh PHPStan analysis passed on both PHP versions. Isolated SDK 1.5.0 passed
all 32 contract cases/53 assertions and the 165 runtime-client assertions on both
versions without replacing Core's SDK.

The four AI/MCP cases each passed 11 persisted logging assertions, with four
intercepted requests and zero live runtime requests. Disposable databases used
MySQL 8.4.11 at the CI-pinned image digest. Core and the installed AI/MCP plugins
passed official checksum verification. All inert Python suites also passed on
Python 3.11.2 in a network-disabled container.

Codex 0.162.1 passed all 36 compatibility checks and the strict four-scenario
observational probe in network-disabled containers. The tool counts and approved
image-delivery limitation remain as recorded above. The qualified pin is unchanged.

### Frozen Package And Remaining Gate

- File: `scriptorium-ai-provider-for-codex-2.1.4.zip`
- Size: **134,346 bytes**, **48 files**, no duplicate ZIP entries
- SHA-256: `08fe6a560b131643ecfd053ebdce7f3dba4a348eba95406dc0236917a84e7727`

Every packaged file matches source and a regular-directory installation on
disposable WordPress 7.1.3 / PHP 8.3.35 / AI 1.4.0 / MCP Adapter 0.7.0. That exact
ZIP passed complete verification, the 11 persisted logging assertions and Plugin
Check 2.1.0 with **zero findings**. This supersedes the earlier local candidates,
not the published v2.1.3 release or the historical production installation.

Windows-host access and the service/install path are not configured in this
environment. The intended-host security checks, actual production source/version
verification, managed-auth refresh and bounded authenticated text/image delivery
remain pending. No production service, plugin installation or account was changed,
and no billable generation was performed. Merge to `main`, publication and
deployment remain held at that gate; authorization is recorded, not qualification.

Hosted Verify run `39531463727` on the tested revision again failed all five jobs
with `runner_id: 0` and zero steps. Local evidence does not turn that run green,
and no required-check bypass or billing change was attempted.

## Windows Host Qualified And Candidate Rebuilt (October 21, 2026)

The operator identified this Windows PC as the intended host and completed a
fresh device-code sign-in in separate staged storage. The Windows gate passed;
merge, release and deployment authorization remains in effect. Production
promotion and its final checks are recorded separately below when completed.

### Source And Requalification

Tested revision: `e80426cdf273fee726df5f53e0886f8edb03e631`. Windows exposed two
test portability errors: a diagnostic expectation used POSIX path separators,
and an environment expectation ignored Windows uppercasing `NO_PROXY`. The fixes
change only those assertions. The first Windows failures are retained; the 38
affected tests passed after correction, alongside 62 unchanged passing cases
and five token-usage assertions.

The previous frozen ZIP was invalidated after the test-source edits. A fresh
eight-configuration matrix passed on WordPress 7.0.7/7.1.3, PHP 7.4.33/8.3.35,
both with and without AI 1.4.0 plus MCP Adapter 0.7.0. Complete verification,
PHPStan, locked installs, isolated SDK 1.5.0 contracts, persisted logging,
Python 3.11.2, Codex compatibility and the offline observational probe passed.
The first SSH invocation omitted Node from PATH; its exit-127 logs are retained
outside the corrected run. No candidate defect was inferred from that failure.

Fresh evidence: `/tmp/opencode/codex-214-windows-final-20261021/qualified/`.
This includes source and package manifests, actual command exit codes, environment
versions, the matrix, installed-package report and `windows-evidence.zip`.

The new frozen `scriptorium-ai-provider-for-codex-2.1.4.zip` has **48 files**,
**134,346 bytes**, SHA-256
`d669531b7fabed583ed7f792f77177dad8362515e4e70fc4b493b30845ac8311`.
All 48 extracted files are byte-for-byte identical to the Windows-tested ZIP and
to committed source. A fresh regular-directory installation passed complete
verification, 11 persisted logging assertions and Plugin Check 2.1.0 with zero
findings. This new archive supersedes the prior archive for publication.

### Windows Runtime And Security Evidence

The installation is `C:\Users\htper\CodexWpRuntime`. Three limited, interactive
user-logon scheduled tasks manage the runtime, separate gateway and Cloudflare
tunnel. Their listeners bind to 127.0.0.1 on ports 4317, 4318 and 4319. Runtime
configuration and per-user storage remain in this private installation.

Python 3.14.6 and official Codex **0.162.1** were used. The official Windows
archive digest is `4b17206ed3045de64064b2016627d51fba3bde386d03838312054882a64d781d`.
All 47 extracted binary/resource files match the installed runtime distribution.
The installed gateway passed all 14 fixture tests. The exact staged sidecar
passed 36 compatibility checks and 18 host checks covering startup, bearer and
loopback rejection, invalid user IDs, cross-user state, configuration overrides,
effective policy and listener cleanup. The reported system config path is absent.

The four-scenario Windows observational probe passed with synthetic auth and a
loopback mock. As before, it sends no tool calls. A separate execution probe
actually invoked code-mode `functions.exec` in text and image threads: the clock
control succeeded; process/require/fetch/Deno/Bun/XMLHttpRequest/WebSocket were
unavailable; shell, file, patch and web tools were unavailable; node:fs import
was rejected. A nonterminating canary hit its two-second deadline in 2.33 seconds,
with no staged Codex processes remaining. These are concrete restrictions of the
qualified binary/configuration, not a claim of a separate Windows account or
general-purpose OS sandbox. The Windows mock was not OS-network-isolated.

### Authenticated Staged Delivery

Fresh staged device login completed without using production auth. The account
snapshot requested `refreshToken: true`, returned managed ChatGPT authentication,
eight models and image capability. Exactly one text attempt and one image attempt
were made. Text returned the expected marker in 4.10 seconds. Real native image
generation completed in 17.43 seconds and returned a visually checked, valid
877,642-byte PNG, SHA-256
`f6fb0ec39d96c93c4703073114b4c99661f1c9fcef76f81511668e5bca9f8cb2`.
The sanitized report and image are retained; credentials are outside evidence.
No staged child processes remained afterward.

### Production Baseline And Rollback

Read-only verification found sidecar 2.1.2, active WordPress plugin 2.1.4 and an
inactive `ai-provider-for-codex` 2.1.3 duplicate. The installed active plugin has
48 files; five differ from the qualified package. The visible version alone
therefore did not establish deployed-source identity. WordPress is 7.1.3 and PHP
is 8.4. The existing SSH key connection was verified without changing credentials.

Before promotion, both plugin directories were archived under the hosting user's
private `codex-214-release-20261021` directory; the archive SHA-256 is
`d58ffe0e438e7a12ad0d8549e6fba140e2ab49005793dd15c15c387398d600c2`.
Original Windows launch/control files and the 2.1.2 package were copied to a
separate rollback directory. Promotion uses a versioned 2.1.4 directory; rollback
restores the original launch/control files and restarts the existing runtime
task. Gateway, tunnel, auth.json, runtime configuration and user storage are
preserved. The inactive duplicate is retained.

Hosted Verify remains blocked before runner assignment. The observed five-job
run `39531848815` has runner ID zero and no steps. GitHub reported no required
checks, no main-branch protection and no applicable branch rules. A normal merge
can proceed after local and intended-host qualification; no admin merge,
required-check override, Actions rerun or billing change is authorized or needed.
