# Sidecar Generation Isolation Remediation (2.1.4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the 2.1.4 security release so that a Codex generation started on behalf of one WordPress user cannot reach another user's stored auth, the sidecar's secrets, the connected ChatGPT account's connectors, or account-synced plugin code on the shared host; make the sidecar refuse to run a turn when Codex does not confirm the requested policy; and document, test and qualify the result on the pinned Codex 0.162.1 baseline.

**Architecture:** The uncommitted 2.1.4 candidate on `fix/sidecar-generation-security-2.1.4` already (a) starts `codex app-server` with a minimal child environment and (b) sends every generation thread `approvalPolicy: never`, `sandbox: read-only`, `environments: []` and a `config` block disabling shell, environment tools, local image viewing, multi-agent backends and web search. Offline verification against the real 0.162.1 binary (mock ChatGPT backend, no network, no credentials) confirmed that this closes the 2.1.3 exposure and that text and image generation still work for both direct-tool and code-mode models. The same verification found two features the thread policy does not cover because they are process-level: **ChatGPT Apps** (`features.apps`) exposes the connected account's connectors to every turn, and **Plugins** (`features.plugins`) syncs the connected account's installed plugins onto the shared host and starts any MCP servers they declare as host processes. Both are removed by launching `codex app-server --disable apps --disable plugins`, which was verified to keep generation working. This plan adds those launch flags, a fail-closed policy check on the `thread/start` response, qualified-version and policy rows in diagnostics, a CA-bundle passthrough, a per-turn wall-clock deadline, documentation, a committed observational tool-surface probe, and the release/qualification steps.

**Tech Stack:** Python 3.11+ sidecar (`sidecar/app/main.py`, stdlib only), Codex CLI 0.162.1 app-server JSON-RPC, WordPress plugin PHP 7.4+ (diagnostics REST + admin JS), `unittest` sidecar regressions, WP-CLI `eval-file` verification, Docker for the offline probe, Bash release scripts.

---

## Findings this plan resolves

| ID | Finding | Status in candidate | Resolved by |
|----|---------|---------------------|-------------|
| F1 | 2.1.3 passed the sidecar's full environment to app-server and set no approval, sandbox or tool limits, so a generation could read other users' `auth.json` and `CODEX_WP_BEARER_TOKEN`. | Fixed (minimal env + thread policy), verified offline. | Keep; covered by Tasks 2, 7, 8. |
| F2 | `features.apps` stays on. With ChatGPT auth, every generation connects to the account's ChatGPT Apps and the model can call those connector tools. | Open. | Task 1 |
| F3 | `features.plugins` stays on. Every generation downloads the connected account's installed plugins into the per-user `CODEX_HOME` on the shared host, and plugin-declared MCP servers run as host processes outside the thread policy. | Open. | Task 1 |
| F4 | MCP servers or hooks defined in any host-side Codex config layer also start as host processes regardless of thread policy. Nothing documents or detects this. | Open. | Tasks 4, 7 |
| F5 | Nothing checks at runtime that Codex applied the requested policy; the fix silently depends on the exact Codex version. | Open. | Tasks 2, 3 |
| F6 | The minimal environment drops `SSL_CERT_FILE` / `SSL_CERT_DIR` / `CODEX_CA_CERTIFICATE`, breaking hosts that need a custom CA bundle; docs mention proxies but not CA variables. | Open. | Task 5 |
| F7 | `TURN_TIMEOUT` is applied per notification, not per turn, so a turn that keeps emitting events has no overall cap (pre-existing). | Open. | Task 6 |
| F8 | Docs claim "no host access" without qualification; `test-codex-compatibility.py` docstring still says 0.155.1; changelog does not mention Apps/Plugins; no 2.1.4 qualification record. | Open. | Task 7 |
| F9 | The offline verification lived only in a session scratchpad. | Open. | Task 8 |

Not addressed here, tracked as follow-ups in the final section: per-user OS-account isolation, Windows sandbox behavior, GitHub Actions billing lock.

---

## File Structure

- Modify `sidecar/app/main.py`: launch flags, policy check, diagnostics rows, CA passthrough, turn deadline.
- Modify `sidecar/scripts/test-generation-contract.py`: argv assertion, policy-check cases, deadline cases.
- Modify `sidecar/scripts/test-process-security.py`: argv reaches the child, CA passthrough, secrets still excluded.
- Modify `sidecar/scripts/test-diagnostics.py`: version row, policy row.
- Modify `sidecar/scripts/test-codex-compatibility.py`: assert `apps`/`plugins` disabled on the real binary; fix docstring.
- Create `sidecar/scripts/offline-tool-surface-probe/` (non-shipping): mock-backend probe that records the tool surface offered to the model and asserts the allowlist.
- Modify `src/REST/DiagnosticsController.php`: remediation text for the two new check ids.
- Modify `sidecar/README.md`, `sidecar/HOW-IT-WORKS.md`, `sidecar/config.example.env`, `README.md`, `readme.txt`, `LOCAL-SIDECAR-SPEC.md`: document the launch flags, host-config rule, CA variables, deadline.
- Create `docs/release-qualification-2.1.4.md`: evidence summary for the release.
- `scripts/verify.sh` already runs every sidecar test; no change unless the probe is wired in (it is not; it needs Docker).

---

### Task 1: Disable ChatGPT Apps and Plugins at app-server launch (F2, F3)

**Files:**
- Modify: `sidecar/app/main.py`
- Modify: `sidecar/scripts/test-generation-contract.py`
- Modify: `sidecar/scripts/test-process-security.py`
- Modify: `sidecar/scripts/test-codex-compatibility.py`

- [x] **Step 1: Add the launch-command builder**

In `sidecar/app/main.py`, next to `INITIALIZE_CAPABILITY_OPTOUTS`:

```python
# Process-wide Codex features that must never exist in a sidecar-owned
# app-server. Apps and Plugins are account-backed: Apps exposes the connected
# ChatGPT account's connectors to every turn, and Plugins syncs that account's
# installed plugins onto this shared host and starts their MCP servers as host
# processes, outside the per-thread policy. Use `--disable`, not
# `-c features.<name>=false`: `--disable` refuses to start on an unknown
# feature name, while `-c` silently ignores one.
CODEX_DISABLED_FEATURES = ("apps", "plugins")


def codex_app_server_command() -> list[str]:
    command = [CODEX_BIN, "app-server"]
    for feature in CODEX_DISABLED_FEATURES:
        command.extend(["--disable", feature])
    return command
```

Replace `[CODEX_BIN, "app-server"]` in `JsonRpcSession.start()` with `codex_app_server_command()`. This also covers login sessions, account snapshots and the diagnostics handshake, which all go through `JsonRpcSession`.

- [x] **Step 2: Assert the argv in the handshake test**

In `HandshakeContractTest` (`test-generation-contract.py`), after `session.start()`, add:

```python
self.assertEqual(
    spawn.call_args.args[0],
    [main.CODEX_BIN, "app-server", "--disable", "apps", "--disable", "plugins"],
)
```

- [x] **Step 3: Assert the argv reaches a real child**

In `test-process-security.py`, the fixture already receives `*command[1:]`. Make it record `sys.argv[1:]` into the environment report (e.g., key `__ARGV__`) and assert in `test_app_server_child_receives_only_runtime_paths_and_scoped_homes` that it equals `["app-server", "--disable", "apps", "--disable", "plugins"]`. Pop the key before the environment equality check.

- [x] **Step 4: Verify on the real binary**

In `test-codex-compatibility.py`, inside the per-thread feature loop, add `"apps"` and `"plugins"` to the names asserted `False`. If `experimentalFeature/list` turns out not to list them, assert instead that the sidecar's own `codex_app_server_command()` contains both `--disable` pairs and add a comment saying why. Also update the docstring's example from `0.155.1` to `0.162.1`.

- [x] **Step 5: Run**

```bash
python3 sidecar/scripts/test-generation-contract.py
python3 sidecar/scripts/test-process-security.py
CODEX_BIN=/path/to/codex-0.162.1 python3 sidecar/scripts/test-codex-compatibility.py --version 0.162.1
```

Expected: all pass; the compatibility run prints `OK` lines for `apps` and `plugins` on both text and image threads and still reports a clean restart.

---

### Task 2: Refuse to run a turn when Codex does not confirm the policy (F5)

**Files:**
- Modify: `sidecar/app/main.py`
- Modify: `sidecar/scripts/test-generation-contract.py`

- [x] **Step 1: Add the check**

```python
def require_generation_policy(thread_started: Any) -> None:
    # Codex echoes the effective policy in the thread/start result. A different
    # value means an unqualified Codex build or a rejected override; starting a
    # turn in that state would silently drop the isolation guarantee.
    approval = thread_started.get("approvalPolicy") if isinstance(thread_started, dict) else None
    sandbox = thread_started.get("sandbox") if isinstance(thread_started, dict) else None
    sandbox_type = sandbox.get("type") if isinstance(sandbox, dict) else None
    thread = thread_started.get("thread") if isinstance(thread_started, dict) else None
    environments = thread.get("environments") if isinstance(thread, dict) else None
    if approval != "never" or sandbox_type != "readOnly" or environments != []:
        raise HttpError(
            "runtime_policy_rejected",
            "Codex did not apply the sidecar generation policy. Check the Codex version against the qualified release.",
            HTTPStatus.BAD_GATEWAY,
            data={"approvalPolicy": approval, "sandboxType": sandbox_type,
                  "environmentCount": len(environments) if isinstance(environments, list) else None},
        )
```

Call it immediately after both `thread_started = session.request("thread/start", ...)` lines in `generate_text` and `generate_image`, before `require_nested_string(..., "thread", "id")`.

- [x] **Step 2: Update the fixture and add cases**

In the fixture's `thread/start` reply (line ~65 of `test-generation-contract.py`) return the 0.162.1 shape: `{"thread": {"id": "thread_test", "environments": []}, "approvalPolicy": "never", "sandbox": {"type": "readOnly"}}`. Add:

- `test_text_turn_is_refused_when_thread_policy_is_missing`: fixture returns the old `{"thread": {"id": ...}}`; expect `HttpError` with code `runtime_policy_rejected`, status 502, and **no** `turn/start` in `requests`.
- `test_image_turn_is_refused_when_sandbox_is_not_read_only`: fixture returns `sandbox: {"type": "workspaceWrite"}`; same expectations.

- [x] **Step 3: PHP side**

`Runtime\Client` already surfaces unknown runtime error codes with their message; confirm with `scripts/test-runtime-client.php` that `runtime_policy_rejected` is passed through unchanged and is not treated as `auth_required`. No code change expected.

- [x] **Step 4: Run** `python3 sidecar/scripts/test-generation-contract.py` → 38 cases at the policy checkpoint; 43 in the final suite with deadline regressions.

---

### Task 3: Diagnostics rows for qualified version and generation policy (F5)

**Files:**
- Modify: `sidecar/app/main.py`
- Modify: `sidecar/scripts/test-diagnostics.py`
- Modify: `src/REST/DiagnosticsController.php`

- [x] **Step 1: Version row**

Add `QUALIFIED_CODEX_VERSION = "0.162.1"`. Add `_check_codex_version(cli_check)` that parses the `codex-cli X.Y.Z` detail and returns id `codex_version`, label `Codex version qualified`, status `pass` when equal, otherwise `warn` with detail `"<found> installed; this sidecar release is qualified against 0.162.1"`. Append it in `run_diagnostics()` only when `cli_check` passed. Bumping the constant is part of every future Codex re-qualification (add to the CLAUDE.md release checklist).

- [x] **Step 2: Policy row**

Extend `_check_app_server()`: after a successful `initialize`, call `thread/start` with `generation_thread_params()` (no auth needed; the compatibility smoke already does this), then run the same `experimentalFeature/list` pagination as the smoke test. Return an extra check id `generation_policy`, label `Generation policy enforced`: `pass` when `approvalPolicy == "never"`, `sandbox.type == "readOnly"` and every name in `("shell_tool", "stable_environment_tools", "view_image", "multi_agent", "multi_agent_v2", "apps", "plugins")` is `False`; otherwise `fail` with the first mismatching name in the detail. Keep the existing `rmtree` of the `_diagnostics` home. Factor the feature-list pagination into a small helper so the smoke test and diagnostics share it (the smoke test imports `main`).

- [x] **Step 3: Tests**

In `test-diagnostics.py`: version row pass/warn cases; `generation_policy` pass case and a failure case using a mocked session whose feature list reports `apps: True`; `run_diagnostics` still returns `ok: False` when any row is not `pass` (existing behavior counts `warn`).

- [x] **Step 4: PHP remediation text**

In `DiagnosticsController::remediation_for_check()` add:

- `codex_version`: "Install the Codex CLI release this plugin version was qualified against, or re-run the compatibility smoke test for the installed version before relying on it."
- `generation_policy`: "The installed Codex CLI did not apply the sidecar's generation restrictions. Stop using the provider until the Codex version matches the qualified release, then restart the sidecar."

The admin UI already renders `warn` as a warning badge (`assets/diagnostics.js`). Run `node --test assets/*.test.mjs` and `scripts/verify.php` (the diagnostics route test should accept the two new ids; extend the mocked sidecar payload in `scripts/verify.php` if it asserts an exact row list).

---

### Task 4: Host-side Codex configuration must not add servers or hooks (F4)

**Files:**
- Modify: `sidecar/app/main.py`
- Modify: `sidecar/scripts/test-diagnostics.py`
- Modify: `sidecar/README.md`, `sidecar/HOW-IT-WORKS.md`

- [x] **Step 1: Detect stray per-user configuration**

The sidecar owns `${CODEX_WP_STORAGE_ROOT}/users/<id>/` and never writes `config.toml` there, so one appearing means something else (or a previous unrestricted Codex) created it. Add diagnostics check id `user_config_overrides`: `pass` when no `users/*/config.toml` exists, otherwise `warn` listing up to five paths. Add remediation text in PHP: "Per-user Codex directories should contain only auth and runtime state. Review and remove the listed config files; MCP servers or hooks defined there run as host processes."

- [x] **Step 2: Document the rule** (wording lands in Task 7): the sidecar host must not define `mcp_servers`, `hooks` or plugin marketplaces in any Codex configuration layer, because those start as ordinary processes under the sidecar's OS account and are outside the per-thread policy. Mention that `--disable plugins` removes account-synced plugins only.

- [x] **Step 3 (evaluate, do not assume):** in the compatibility smoke, test whether `-c mcp_servers={}` passed on the app-server command line suppresses a server defined in a throwaway `config.toml` (observe `mcpServer/startupStatus/updated` notifications for a benign placeholder command). Adopt it in `codex_app_server_command()` only if the smoke proves it and startup stays clean; otherwise record the result in the qualification doc and rely on Steps 1–2. The override did not suppress the server and was not adopted.

---

### Task 5: Pass CA bundle variables through; keep everything else out (F6)

**Files:**
- Modify: `sidecar/app/main.py`
- Modify: `sidecar/scripts/test-process-security.py`
- Modify: `sidecar/config.example.env`, `sidecar/README.md`

- [x] **Step 1:** In `codex_process_environment()`, extend the allowlist to `{"PATH", "PATHEXT", "SYSTEMROOT", "WINDIR", "SSL_CERT_FILE", "SSL_CERT_DIR", "CODEX_CA_CERTIFICATE"}`. These are file paths to public trust anchors, not credentials; proxies stay excluded (proxy URLs can embed credentials and would redirect the user's traffic).
  - **Superseded 2026-10-12 after review:** `HTTP_PROXY`, `HTTPS_PROXY`, `ALL_PROXY` and `NO_PROXY` are forwarded after all. Codex 0.162.1 honors them by default and 2.1.3 forwarded the whole environment, so withholding them silently broke proxy-only hosts. See `docs/release-qualification-2.1.4.md`, "Post-Qualification Changes".

- [x] **Step 2:** Tests: add the three CA variables and `HTTP_PROXY`, `HTTPS_PROXY`, `ALL_PROXY` and `NO_PROXY` (case-insensitive) to the parent environment in `test_app_server_child_receives_only_runtime_paths_and_scoped_homes` and expect them in the child; keep `OPENAI_API_KEY`, `CODEX_WP_BEARER_TOKEN` and the unclassified credential excluded.

- [x] **Step 3:** Document in `config.example.env` (commented lines) and `sidecar/README.md`: "If the host requires a private CA, set `SSL_CERT_FILE` (or `CODEX_CA_CERTIFICATE`) in the sidecar's environment; these are forwarded. `HTTP_PROXY`, `HTTPS_PROXY`, `ALL_PROXY` and `NO_PROXY` are also forwarded in any letter case so proxy-only hosts can reach `chatgpt.com`. Treat proxy configuration, including any embedded credentials, as operator-owned."

---

### Task 6: Per-turn wall-clock deadline (F7)

**Files:**
- Modify: `sidecar/app/main.py`
- Modify: `sidecar/scripts/test-generation-contract.py`
- Modify: `sidecar/README.md`

- [x] **Step 1:** In both generation loops compute `deadline = time.monotonic() + TURN_TIMEOUT` before `turn/start` and pass the remaining budget to each `wait_for_notification`. Recheck the deadline after queued notifications, bound `turn/start` by the turn budget, and best-effort interrupt on timeout. The HTTP handler returns `request_timeout` (504); session cleanup now also handles initialization failures.

- [x] **Step 2:** Test with the fixture: a scripted turn that emits deltas spaced so the total exceeds a small patched `TURN_TIMEOUT` (e.g., 0.3 s) must raise `TimeoutError`, send `turn/interrupt`, and never return partial text. A turn that completes within the deadline is unaffected (existing cases). Additional regressions cover late queued completions and cleanup of an unresponsive real child.

- [x] **Step 3:** Document: `CODEX_RUNTIME_TURN_TIMEOUT` is now the wall-clock budget from `turn/start` through completion, not the gap between events; setup, post-turn reads and cancellation grace are separate. The sidecar default 300 s stays below the WordPress-side 360 s generation fallback.

If this task grows beyond a focused change, ship 2.1.4 without it and move it to 2.1.5; it is not part of the isolation fix.

---

### Task 7: Documentation and release metadata (F8)

**Files:**
- Modify: `sidecar/README.md`, `sidecar/HOW-IT-WORKS.md`, `README.md`, `readme.txt`, `LOCAL-SIDECAR-SPEC.md`, `CLAUDE.md`
- Create: `docs/release-qualification-2.1.4.md`

- [x] **Step 1: `sidecar/HOW-IT-WORKS.md` and `sidecar/README.md`** — replace the candidate's paragraphs with an accurate statement of the three layers:
   1. Process environment: the exact passthrough set (Task 5), including operator-owned proxy settings and CA trust paths; provider keys, sidecar bearer tokens and database credentials stay excluded.
  2. Process features: `codex app-server --disable apps --disable plugins`; why each matters; `--disable` fails closed.
  3. Thread policy: approval `never`, sandbox `read-only`, `environments: []`, disabled tool features, `web_search` disabled, native image generation only on image threads; the sidecar refuses the turn if `thread/start` does not echo the policy.
  Then the boundary statement: the qualified tool surface disables shell/file tools; any claim about code-mode host access requires execution evidence from the actual platform and configuration. Keep the Windows qualification caveat until those checks pass. Codex configuration on the host (MCP servers, hooks) can launch host processes, so keep the sidecar host free of such configuration. Per-user directories under one OS account are not an OS boundary. See the October 21 Windows execution evidence in the qualification record for the observed restrictions and their limits.

- [x] **Step 2: `readme.txt` changelog** for 2.1.4:
  - Harden sidecar generation sessions: minimal child-process environment, explicit approval/sandbox/tool policies, ChatGPT Apps and account-synced Plugins disabled for sidecar-owned Codex processes.
  - Refuse generation when Codex does not confirm the requested policy; new diagnostics rows for Codex version qualification and policy enforcement.
  - Forward CA bundle variables to Codex; apply the turn timeout as a wall-clock limit.

- [x] **Step 3: `README.md`** — in the Codex compatibility section, list what the smoke test now verifies (add Apps/Plugins and the policy echo). `LOCAL-SIDECAR-SPEC.md` — add a short "Generation isolation contract" section listing the launch flags and thread policy so the spec matches the implementation. `CLAUDE.md` — add `QUALIFIED_CODEX_VERSION` and `CODEX_DISABLED_FEATURES` to the "Invariants worth preserving" list and to the release version-sync note.

- [x] **Step 4: `docs/release-qualification-2.1.4.md`** — record: base revision, Codex 0.162.1 archive SHA-256 (`86f268d8…5057`), the final observational probe and negative control, the approved image-scope limitation, matrix results, PHPStan, Plugin Check and package hash. Keep it factual; no reproduction of adversarial inputs.

---

### Task 8: Commit the observational tool-surface probe (F9)

**Approved scope correction (2026-10-12):** The operator chose to keep the mock strictly observational after the real Codex 0.162.1 probe showed that a directly returned hosted `image_generation_call` is parsed but not forwarded by the live non-tool item path. Successful native image delivery requires an image-tool invocation. The four scenarios now validate text delivery plus text/image thread tool surfaces and policy echoes with normal text completions; image threads must reject text-only output. Image delivery is covered separately by inert sidecar protocol fixtures. The probe does not qualify real Codex image-tool execution. Model-endpoint websocket upgrades are recorded and refused with HTTP 426; any successful upgrade or attempt on another endpoint fails. The 0.162.1 code-mode surface also includes the read-only `clock__curr_time` helper, which is explicitly allowlisted.

**Files:**
- Create: `sidecar/scripts/offline-tool-surface-probe/README.md`, `probe.py`, `run.sh`

`sidecar/scripts/` is already excluded from the release zip, so this ships nowhere.

- [x] **Step 1: Scope** — the probe is observational and fixed-allowlist. It runs the real sidecar generation code against the real `codex` binary inside `docker run --network none`, with a loopback HTTPS mock standing in for the ChatGPT backend. It uses a synthetic, unsigned token file and never contacts real endpoints. For each scenario it records: tool specs offered to the model (top-level `tools` and `additional_tools` items), nested code-mode tool names, every backend path requested, and the generation result. It asserts:
  - offered tools ⊆ allowlist `{request_user_input, request_user_input_async, get_goal, create_goal, update_goal, list_mcp_resources, list_mcp_resource_templates, read_mcp_resource, clock.sleep, clock__curr_time, functions.exec, functions.wait}` plus `image_gen.imagegen` on image threads only;
  - no request path under `/ps/` or `/plugins/`;
   - no successful websocket or upgrade outside the model endpoint; no `/ps/mcp` traffic;
   - text generation returns successfully for one direct-tool model and one code-mode model; image threads expose the expected restricted tools and reject the mock's text-only completion (see approved scope correction above).
  The probe does **not** send tool calls back from the mock; it only reports what would be offered. (The allowlist is the one observed on 0.162.1 with the launch flags.)

- [x] **Step 2: Inputs** — document the required local assets and their official checksums: `codex` 0.162.1 and `codex-code-mode-host` 0.162.1 release archives, a throwaway CA + localhost certificate generated at run time, and `models.json` from the matching Codex source tag. Record the three gotchas: workspace routing requires an HTTPS backend; request compression must be disabled in the per-user `config.toml` for the mock to read bodies; code-mode models receive tools inside `additional_tools`.

- [x] **Step 3: Container hygiene** — `run.sh` must kill its own container on exit (`trap`) because `docker run --rm` outlives a killed CLI.

- [x] **Step 4:** Run it once against the finished branch and paste the summary table into `docs/release-qualification-2.1.4.md`.

---

### Task 9: Release gate

- [x] `composer install && composer phpstan` on PHP 7.4 and 8.3 → no errors.
- [x] `node --test assets/connection-flow.test.mjs assets/user-connection.test.mjs`.
- [x] All seven `sidecar/scripts/test-*.py` suites, plus the compatibility smoke on the real 0.162.1 binary.
- [x] Eight-configuration matrix via `WP_PATH=… ./scripts/verify.sh` (WP 7.0.7/7.1.3 × PHP 7.4/8.3 × with/without AI 1.4.0 + MCP Adapter 0.7.0), per the local qualification harness notes. Enable AI Request Logging before `scripts/test-persisted-request-logs.php`.
- [x] `WP_PATH=… bash scripts/plugin-check-release.sh` on a `git archive` export; grep for `\tERROR\t` (the script exits 0 on errors). No ERROR findings; one excluded `.github` warning on the source export, clean installed ZIP.
- [x] `bash scripts/package-release.sh`; extract and `cmp` every file against the working tree; record the ZIP SHA-256.
- [x] Tool-surface probe (Task 8) passes under the approved observational-only scope.
- [x] Version lockstep: `Version:` header, `const VERSION`, `Stable tag:` and the three sidecar version strings all read `2.1.4` (already true in the candidate; re-check after edits).

---

### Task 10: Commit, review, deploy

- [x] Commit on `fix/sidecar-generation-security-2.1.4` in logical units (launch flags + tests; policy check; diagnostics; CA passthrough; deadline; docs; probe). The operator authorized command-scoped author/committer identity; Git configuration was not changed. No push or PR was performed.
- [x] PR description: findings table, verification summary and explicit non-performed items prepared in `/tmp/opencode/codex-214/review-draft.md`. No PR was created.
- [ ] Deployment (Windows sidecar host): confirm `codex --version` prints `codex-cli 0.162.1`; install the packaged sidecar files; restart the service; open **Settings → Codex Provider → Diagnostics** and confirm `Codex version qualified`, `Generation policy enforced` and `user_config_overrides` all pass; run one text and one image generation as the operator (billable, acknowledged) and confirm both appear in the AI Request Log.
- [x] The candidate's GitHub Actions job name and checksum are synchronized with the qualified 0.162.1 pin; CI remains billing-locked and is not evidence.

Implementation, local qualification and commits are complete. The Windows deployment step above remains an operator handoff; no production service restart or authenticated/billable generation was performed.

**Approved October 21 follow-up:** review fixes and PR #6 are now committed and
pushed on the PR #7 branch. The combined candidate passed the full local matrix
again; current source and package hashes are in
[`docs/release-qualification-2.1.4.md`](../../release-qualification-2.1.4.md).
The earlier no-push/no-PR notes describe the original qualification only.
Windows access and intended-host validation subsequently passed on October 21, including actual code-mode canaries and managed-auth text/image requests. Production deployment and its checks are recorded separately in the qualification record.

---

## Follow-ups (not in 2.1.4)

- **Per-user OS isolation.** Separate directories under one account remain a logical boundary. Design options: one service account per WordPress user via `systemd-run --uid`, or a filesystem-confined launcher (bubblewrap / Windows restricted token). Needs its own spec.
- **Windows qualification is specific to the tested binary and configuration.** Disabling the shell tool alone does not establish confinement of `functions.exec`. The October 21 intended-host gate added actual execution canaries, deadline/process cleanup checks and authenticated delivery; see the qualification record. The observational probe still executes no model-supplied code. Requalify platform or configuration changes rather than extending these observations into an unconditional OS-isolation guarantee.
- **Automated re-qualification.** When Codex is bumped, the compatibility smoke plus the probe are the gate; the diagnostics `codex_version` row warns operators in the meantime.
- **CI.** Resolve the Actions billing lock so the compatibility job runs on every push.
