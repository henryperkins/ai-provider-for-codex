# Runtime Diagnostics And Guided Setup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an authenticated, read-only sidecar diagnostic endpoint plus an explicit "Check runtime" wp-admin action, an in-body setup guide, and generated systemd/env snippets — so an admin can see *why* the local runtime is broken without any OS writes.

**Architecture:** A new authenticated `GET /v1/diagnostics` on the Python sidecar runs four server-side checks and returns a structured list. WordPress proxies it through a new `manage_options`-gated REST route, composes PHP-derived rows (reachability, bearer-match, config source) with the sidecar rows, stores the verdict in its own transient (never the shared reachability cache), and renders it on Settings ▸ Codex Provider behind an explicit button.

**Tech Stack:** Python 3.11 stdlib (sidecar `http.server`), PHP (WordPress HTTP API, REST, hand-rolled PSR-4 autoloader), raw ESM JavaScript script modules (no build step), `node --test` for JS, WP-CLI `eval-file` (`scripts/verify.php`) for PHP.

## Global Constraints

- **Do NOT bump any version.** Everything stays `0.1.5`. The sidecar must keep **exactly two** `"version": "0.1.5"` literals so `scripts/verify.sh:84-107` passes.
- **WordPress floor:** 7.0+ (`\AIProviderForCodex\MIN_WP_VERSION`). **PHP floor:** 7.4 (use `strpos`, not `str_contains`). **Sidecar:** Python 3.11+, stdlib only.
- **No new dependencies** (runtime autoloader is hand-rolled; Composer is dev-only).
- **WordPress.org-safe:** PHP never writes to the OS, executes installers, or phones home. Snippets are display-only text.
- **Naming:** keep `codex_provider_*` / `codex_runtime_*` option prefixes; text domain `'scriptorium-ai-provider-for-codex'`.
- **Parallel work in flight:** `sidecar/app/main.py` is being edited on this branch for unrelated image-gen/token-usage work. Locate sidecar edit points by **surrounding code/strings**, not absolute line numbers.
- **Every commit message ends with the trailer** `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>` (omitted from the command examples below for brevity).
- New PHP classes load by PSR-4 convention (`src/<Sub>/<Class>.php`) — no autoloader registration needed.

---

### Task 1: Sidecar identity helper + liveness reuse + README fix

**Files:**
- Modify: `sidecar/app/main.py` (the `/ping`+`/healthz` branch in `RuntimeHandler._dispatch`, and module scope)
- Modify: `sidecar/README.md` (the truncated sentence, ~line 65)
- Create: `sidecar/scripts/test-diagnostics.py`

**Interfaces:**
- Produces: `_server_identity() -> dict[str, Any]` returning `{"service": "codex-wp-sidecar", "version": "0.1.5"}`.

- [ ] **Step 1: Write the failing test**

Create `sidecar/scripts/test-diagnostics.py`:

```python
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "app"))

import main  # noqa: E402


class ServerIdentityTest(unittest.TestCase):
    def test_returns_service_and_version_only(self):
        self.assertEqual(
            main._server_identity(),
            {"service": "codex-wp-sidecar", "version": "0.1.5"},
        )


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python3 sidecar/scripts/test-diagnostics.py`
Expected: FAIL with `AttributeError: module 'main' has no attribute '_server_identity'`

- [ ] **Step 3: Add the helper and reuse it in the liveness branch**

In `sidecar/app/main.py`, add the helper at module scope (near the other top-level `def`s, e.g. just above `def ensure_storage_root`):

```python
def _server_identity() -> dict[str, Any]:
    return {"service": "codex-wp-sidecar", "version": "0.1.5"}
```

Then find the `/ping`+`/healthz` branch in `_dispatch`:

```python
            if "GET" == self.command and parsed.path in {"/ping", "/healthz"}:
                self._json_response(
                    {
                        "ok": True,
                        "service": "codex-wp-sidecar",
                        "version": "0.1.5",
                        "codexBin": CODEX_BIN,
                    }
                )
                return
```

Replace its body with:

```python
            if "GET" == self.command and parsed.path in {"/ping", "/healthz"}:
                self._json_response({"ok": True, **_server_identity(), "codexBin": CODEX_BIN})
                return
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python3 sidecar/scripts/test-diagnostics.py`
Expected: PASS (`Ran 1 test ... OK`)

- [ ] **Step 5: Confirm the version-literal guard still holds**

Run: `grep -c '"version": "0.1.5"' sidecar/app/main.py`
Expected: `2` (one in `clientInfo`, one in `_server_identity`)

- [ ] **Step 6: Fix the truncated README sentence**

In `sidecar/README.md`, find the truncated line:

```
If PHP can read `/etc/codex-wp-sidecar.env`, the WordPress plugin can auto-detect
```

Replace it with:

```
If PHP can read `/etc/codex-wp-sidecar.env`, the WordPress plugin can auto-detect both the Runtime URL and bearer token, and the settings fields become informational.
```

- [ ] **Step 7: Commit**

```bash
git add sidecar/app/main.py sidecar/README.md sidecar/scripts/test-diagnostics.py
git commit -m "sidecar: extract server identity helper and fix README sentence"
```

---

### Task 2: Sidecar configurable initialize timeout

**Files:**
- Modify: `sidecar/app/main.py` (`JsonRpcSession.start`)
- Modify: `sidecar/scripts/test-diagnostics.py`

**Interfaces:**
- Consumes: nothing new.
- Produces: `JsonRpcSession.start(self, initialize_timeout: float | None = None) -> JsonRpcSession` — passes `initialize_timeout or REQUEST_TIMEOUT` to the `initialize` request. All existing callers (default `None`) keep using `REQUEST_TIMEOUT`.

- [ ] **Step 1: Write the failing test**

Append to `sidecar/scripts/test-diagnostics.py` (above the `if __name__` block):

```python
from unittest import mock  # noqa: E402


class StartTimeoutTest(unittest.TestCase):
    def test_start_forwards_initialize_timeout(self):
        session = main.JsonRpcSession(Path("/tmp/codex-diag-test"))
        with mock.patch.object(session, "request") as request, mock.patch.object(
            main.subprocess, "Popen"
        ), mock.patch.object(main.threading, "Thread"):
            session.start(initialize_timeout=7.5)
        self.assertEqual(request.call_args.args[0], "initialize")
        self.assertEqual(request.call_args.kwargs["timeout"], 7.5)
```

- [ ] **Step 2: Run test to verify it fails**

Run: `python3 sidecar/scripts/test-diagnostics.py`
Expected: FAIL with `TypeError: start() got an unexpected keyword argument 'initialize_timeout'`

- [ ] **Step 3: Add the parameter**

In `sidecar/app/main.py`, change the `start` signature and the `initialize` call. Find:

```python
    def start(self) -> JsonRpcSession:
        if self._proc is not None:
            return self
```

Replace the signature line with:

```python
    def start(self, initialize_timeout: float | None = None) -> JsonRpcSession:
        if self._proc is not None:
            return self
```

Then find the `initialize` request inside `start`:

```python
            timeout=REQUEST_TIMEOUT,
        )
        return self
```

Replace that `timeout=REQUEST_TIMEOUT,` line (the one inside the `self.request("initialize", ...)` call) with:

```python
            timeout=initialize_timeout or REQUEST_TIMEOUT,
        )
        return self
```

- [ ] **Step 4: Run test to verify it passes**

Run: `python3 sidecar/scripts/test-diagnostics.py`
Expected: PASS (`Ran 2 tests ... OK`)

- [ ] **Step 5: Commit**

```bash
git add sidecar/app/main.py sidecar/scripts/test-diagnostics.py
git commit -m "sidecar: allow a custom initialize timeout on session start"
```

---

### Task 3: Sidecar diagnostics checks

**Files:**
- Modify: `sidecar/app/main.py` (imports, constant, check functions, `run_diagnostics`)
- Modify: `sidecar/scripts/test-diagnostics.py`

**Interfaces:**
- Consumes: `_server_identity()`, `JsonRpcSession.start(initialize_timeout=...)`, `CODEX_BIN`, `STORAGE_ROOT`.
- Produces:
  - `DIAGNOSTIC_HANDSHAKE_TIMEOUT: float = 10.0`
  - `run_diagnostics() -> dict[str, Any]` returning `{"ok": bool, "service": str, "version": str, "checks": list[dict]}`, where each check is `{"id": str, "label": str, "status": "pass" | "fail", "detail": str}` and `ok = all checks pass`.
  - check helpers `_check_python_version()`, `_check_codex_cli()`, `_check_storage_root()`, `_check_app_server()`, each `-> dict[str, Any]`.

- [ ] **Step 1: Write the failing tests**

Append to `sidecar/scripts/test-diagnostics.py` (above `if __name__`):

```python
import tempfile  # noqa: E402


class DiagnosticsChecksTest(unittest.TestCase):
    def test_python_version_passes_on_supported_runtime(self):
        check = main._check_python_version()
        self.assertEqual(check["id"], "python_version")
        self.assertEqual(check["status"], "pass")

    def test_codex_cli_fails_when_binary_missing(self):
        with mock.patch.object(main, "CODEX_BIN", "/nonexistent/codex-binary"):
            check = main._check_codex_cli()
        self.assertEqual(check["id"], "codex_cli")
        self.assertEqual(check["status"], "fail")

    def test_codex_cli_passes_when_version_succeeds(self):
        completed = mock.Mock(returncode=0, stdout="codex 1.2.3\n", stderr="")
        with mock.patch.object(main.subprocess, "run", return_value=completed):
            check = main._check_codex_cli()
        self.assertEqual(check["status"], "pass")
        self.assertIn("codex 1.2.3", check["detail"])

    def test_storage_root_passes_when_writable(self):
        with tempfile.TemporaryDirectory() as tmp:
            with mock.patch.object(main, "STORAGE_ROOT", Path(tmp) / "store"):
                check = main._check_storage_root()
        self.assertEqual(check["status"], "pass")

    def test_storage_root_fails_when_parent_is_a_file(self):
        with tempfile.NamedTemporaryFile() as blocker:
            with mock.patch.object(main, "STORAGE_ROOT", Path(blocker.name) / "store"):
                check = main._check_storage_root()
        self.assertEqual(check["status"], "fail")

    def test_app_server_fails_when_start_raises(self):
        fake = mock.Mock()
        fake.start.side_effect = RuntimeError("boom")
        with mock.patch.object(main, "JsonRpcSession", return_value=fake):
            check = main._check_app_server()
        self.assertEqual(check["id"], "app_server")
        self.assertEqual(check["status"], "fail")
        fake.close.assert_called_once()

    def test_run_diagnostics_aggregates_ok(self):
        passing = {"id": "x", "label": "X", "status": "pass", "detail": ""}
        failing = {"id": "y", "label": "Y", "status": "fail", "detail": "nope"}
        with mock.patch.object(main, "_check_python_version", return_value=passing), \
            mock.patch.object(main, "_check_codex_cli", return_value=passing), \
            mock.patch.object(main, "_check_storage_root", return_value=passing), \
            mock.patch.object(main, "_check_app_server", return_value=failing):
            result = main.run_diagnostics()
        self.assertFalse(result["ok"])
        self.assertEqual(result["service"], "codex-wp-sidecar")
        self.assertEqual(len(result["checks"]), 4)
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `python3 sidecar/scripts/test-diagnostics.py`
Expected: FAIL with `AttributeError: module 'main' has no attribute '_check_python_version'`

- [ ] **Step 3: Add imports and the constant**

In `sidecar/app/main.py`, the import block currently starts:

```python
import hmac
import json
import os
import subprocess
import threading
import time
import uuid
```

Add `import platform` and `import sys` so it reads:

```python
import hmac
import json
import os
import platform
import subprocess
import sys
import threading
import time
import uuid
```

Add the timeout constant next to the other timeout constants (after `LOGIN_TIMEOUT = ...`):

```python
DIAGNOSTIC_HANDSHAKE_TIMEOUT = 10.0
```

- [ ] **Step 4: Add the check functions and `run_diagnostics`**

In `sidecar/app/main.py`, add these at module scope (e.g. just above `def _server_identity`):

```python
def _check_python_version() -> dict[str, Any]:
    version = platform.python_version()
    ok = sys.version_info >= (3, 11)
    detail = f"Python {version}" if ok else f"Python {version} (3.11+ required)"
    return {"id": "python_version", "label": "Python runtime", "status": "pass" if ok else "fail", "detail": detail}


def _check_codex_cli() -> dict[str, Any]:
    label = "Codex CLI"
    try:
        result = subprocess.run(
            [CODEX_BIN, "--version"],
            capture_output=True,
            text=True,
            timeout=5,
        )
    except FileNotFoundError:
        return {"id": "codex_cli", "label": label, "status": "fail", "detail": f"codex not found at {CODEX_BIN}"}
    except subprocess.TimeoutExpired:
        return {"id": "codex_cli", "label": label, "status": "fail", "detail": f"codex --version timed out at {CODEX_BIN}"}

    if result.returncode != 0:
        return {"id": "codex_cli", "label": label, "status": "fail", "detail": f"codex --version exited with code {result.returncode}"}

    raw = (result.stdout or result.stderr).strip()
    version = raw.splitlines()[0] if raw else "unknown version"
    return {"id": "codex_cli", "label": label, "status": "pass", "detail": f"{version} at {CODEX_BIN}"}


def _check_storage_root() -> dict[str, Any]:
    label = "Storage root writable"
    try:
        STORAGE_ROOT.mkdir(parents=True, exist_ok=True)
        probe = STORAGE_ROOT / ".diagnostics-write-probe"
        probe.write_text("ok", encoding="utf-8")
        probe.unlink()
    except OSError as exc:
        return {"id": "storage_root", "label": label, "status": "fail", "detail": f"{STORAGE_ROOT}: {exc}"}
    return {"id": "storage_root", "label": label, "status": "pass", "detail": f"{STORAGE_ROOT} is writable"}


def _check_app_server() -> dict[str, Any]:
    label = "app-server handshake"
    started = time.time()
    session = JsonRpcSession(STORAGE_ROOT / "_diagnostics")
    try:
        session.start(initialize_timeout=DIAGNOSTIC_HANDSHAKE_TIMEOUT)
    except FileNotFoundError:
        return {"id": "app_server", "label": label, "status": "fail", "detail": f"codex not found at {CODEX_BIN}"}
    except Exception as exc:  # noqa: BLE001 - any startup failure is a diagnostic failure
        return {"id": "app_server", "label": label, "status": "fail", "detail": f"initialize failed: {exc}"}
    finally:
        session.close()
    elapsed = time.time() - started
    return {"id": "app_server", "label": label, "status": "pass", "detail": f"initialize completed in {elapsed:.1f}s"}


def run_diagnostics() -> dict[str, Any]:
    checks = [
        _check_python_version(),
        _check_codex_cli(),
        _check_storage_root(),
        _check_app_server(),
    ]
    ok = all(check["status"] == "pass" for check in checks)
    return {"ok": ok, **_server_identity(), "checks": checks}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `python3 sidecar/scripts/test-diagnostics.py`
Expected: PASS (`Ran 9 tests ... OK`)

- [ ] **Step 6: Commit**

```bash
git add sidecar/app/main.py sidecar/scripts/test-diagnostics.py
git commit -m "sidecar: add read-only runtime diagnostics checks"
```

---

### Task 4: Sidecar `/v1/diagnostics` route

**Files:**
- Modify: `sidecar/app/main.py` (`RuntimeHandler._dispatch`)

**Interfaces:**
- Consumes: `run_diagnostics()`. Route is registered **after** `_ensure_local_client()` and `_ensure_authorized()`.
- Produces: `GET /v1/diagnostics` → `200` with the `run_diagnostics()` payload; `401` (code `unauthorized`) when the bearer is missing/invalid.

- [ ] **Step 1: Add the route**

In `sidecar/app/main.py`, find the authenticated routes in `_dispatch` (after `self._ensure_local_client()` and `self._ensure_authorized()`), just before the `/v1/login/start` branch:

```python
            if "POST" == self.command and "/v1/login/start" == parsed.path:
```

Insert this branch immediately above it:

```python
            if "GET" == self.command and "/v1/diagnostics" == parsed.path:
                self._json_response(run_diagnostics())
                return

```

- [ ] **Step 2: Lint the module**

Run: `python3 -c "import ast; ast.parse(open('sidecar/app/main.py').read())"`
Expected: no output (parses cleanly)

- [ ] **Step 3: Manually verify the live route (integration)**

Run (in one shell):

```bash
CODEX_WP_BEARER_TOKEN=test-token CODEX_WP_STORAGE_ROOT=/tmp/codex-diag python3 sidecar/app/main.py &
SIDECAR_PID=$!
sleep 1
echo "--- no auth (expect 401) ---"
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:4317/v1/diagnostics
echo "--- with auth (expect 200 + JSON checks) ---"
curl -s -H "Authorization: Bearer test-token" http://127.0.0.1:4317/v1/diagnostics
kill $SIDECAR_PID
```

Expected: first call prints `401`; second prints a JSON object with `"checks"` containing `python_version`, `codex_cli`, `storage_root`, `app_server` (individual statuses may be `fail` if `codex` is not installed on this host — that is correct).

- [ ] **Step 4: Commit**

```bash
git add sidecar/app/main.py
git commit -m "sidecar: expose authenticated GET /v1/diagnostics route"
```

---

### Task 5: WordPress Client diagnostics call (no health-cache writes)

**Files:**
- Modify: `src/Runtime/Client.php` (`request`, `process_response`, new `diagnostics`)
- Modify: `scripts/verify.php` (new assertions inside the existing IIFE)

**Interfaces:**
- Consumes: existing `Settings`, `RuntimeRequestException`, `HealthMonitor`.
- Produces:
  - `Client::request(string $method, string $path, array $body = [], array $query = [], bool $record_health = true): array`
  - `Client::diagnostics(): array` → returns the decoded sidecar payload (`['ok'=>bool,'service'=>string,'version'=>string,'checks'=>array]`); throws `RuntimeRequestException` on HTTP ≥400 (carrying `get_status_code()` / `get_runtime_error_code()`) and `RuntimeException` on transport failure — **without** touching `HealthMonitor`.

- [ ] **Step 1: Write the failing test**

In `scripts/verify.php`, inside the IIFE (after the helper closures are defined, e.g. just before the closing `} )();`), add:

```php
		// --- Runtime diagnostics: Client::diagnostics() does not touch HealthMonitor. ---
		delete_transient( 'codex_provider_runtime_health' );
		HealthMonitor::record_failure( 'sentinel-before-diagnostics' );

		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_http_json_response ) {
				if ( false !== strpos( $url, '/v1/diagnostics' ) ) {
					return $codex_provider_http_json_response(
						200,
						[
							'ok'      => true,
							'service' => 'codex-wp-sidecar',
							'version' => '0.1.5',
							'checks'  => [
								[ 'id' => 'python_version', 'label' => 'Python runtime', 'status' => 'pass', 'detail' => 'Python 3.11.6' ],
							],
						]
					);
				}
				return $preempt;
			},
			static function () use ( $codex_provider_assert ) {
				$client = new \AIProviderForCodex\Runtime\Client();
				$result = $client->diagnostics();
				$codex_provider_assert( true === ( $result['ok'] ?? null ), 'Client::diagnostics() should return the sidecar ok flag.' );
				$codex_provider_assert( 'pass' === $result['checks'][0]['status'], 'Client::diagnostics() should return sidecar checks.' );
				$codex_provider_assert(
					'sentinel-before-diagnostics' === HealthMonitor::get_status()['error'],
					'Client::diagnostics() must not overwrite the HealthMonitor reachability cache.'
				);
			}
		);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wp --path=$WP_PATH eval-file wp-content/plugins/scriptorium-ai-provider-for-codex/scripts/verify.php`
Expected: FAIL with an error like `Call to undefined method ...Client::diagnostics()`

- [ ] **Step 3: Thread `$record_health` through the client and add `diagnostics()`**

In `src/Runtime/Client.php`, change `request()`'s signature. Find:

```php
	public function request( string $method, string $path, array $body = [], array $query = [] ): array {
```

Replace with:

```php
	public function request( string $method, string $path, array $body = [], array $query = [], bool $record_health = true ): array {
```

In the transport-error branch inside `request()`, find:

```php
			if ( self::is_connector_approval_error( $response ) ) {
				HealthMonitor::record_connector_unapproved( $message );
			} else {
				HealthMonitor::record_failure( $message );
			}
```

Replace with:

```php
			if ( $record_health ) {
				if ( self::is_connector_approval_error( $response ) ) {
					HealthMonitor::record_connector_unapproved( $message );
				} else {
					HealthMonitor::record_failure( $message );
				}
			}
```

In `request()`, find the call to `process_response`:

```php
		return $this->process_response(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			(string) wp_remote_retrieve_response_message( $response )
		);
```

Replace with:

```php
		return $this->process_response(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			(string) wp_remote_retrieve_response_message( $response ),
			$record_health
		);
```

Change `process_response()`'s signature. Find:

```php
	private function process_response( int $status_code, string $raw_body, string $fallback_status_message ): array {
```

Replace with:

```php
	private function process_response( int $status_code, string $raw_body, string $fallback_status_message, bool $record_health = true ): array {
```

Inside `process_response()`, guard the invalid-JSON failure record. Find:

```php
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				HealthMonitor::record_failure( __( 'The local Codex runtime returned invalid JSON.', 'scriptorium-ai-provider-for-codex' ) );
				throw self::runtime_exception( esc_html__( 'The local Codex runtime returned invalid JSON.', 'scriptorium-ai-provider-for-codex' ) );
			}
```

Replace with:

```php
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				if ( $record_health ) {
					HealthMonitor::record_failure( __( 'The local Codex runtime returned invalid JSON.', 'scriptorium-ai-provider-for-codex' ) );
				}
				throw self::runtime_exception( esc_html__( 'The local Codex runtime returned invalid JSON.', 'scriptorium-ai-provider-for-codex' ) );
			}
```

Inside `process_response()`, guard the ≥400 failure record. Find:

```php
			if ( $status_code >= 500 || in_array( $status_code, [ 401, 403 ], true ) ) {
				HealthMonitor::record_failure( (string) $message );
			}
```

Replace with:

```php
			if ( $record_health && ( $status_code >= 500 || in_array( $status_code, [ 401, 403 ], true ) ) ) {
				HealthMonitor::record_failure( (string) $message );
			}
```

Inside `process_response()`, guard the success record. Find:

```php
		HealthMonitor::record_success();

		return is_array( $payload ) ? $payload : [];
```

Replace with:

```php
		if ( $record_health ) {
			HealthMonitor::record_success();
		}

		return is_array( $payload ) ? $payload : [];
```

Finally, add the `diagnostics()` method (e.g. right after `post()`):

```php
	/**
	 * Runs the sidecar's read-only diagnostics without touching the health cache.
	 *
	 * @return array<string,mixed>
	 */
	public function diagnostics(): array {
		return $this->request( 'GET', '/v1/diagnostics', [], [], false );
	}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `wp --path=$WP_PATH eval-file wp-content/plugins/scriptorium-ai-provider-for-codex/scripts/verify.php`
Expected: the script runs to completion with its existing success output (no exception).

- [ ] **Step 5: Lint**

Run: `php -l src/Runtime/Client.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Commit**

```bash
git add src/Runtime/Client.php scripts/verify.php
git commit -m "runtime: add Client::diagnostics() that skips health-cache writes"
```

---

### Task 6: Diagnostics REST controller

**Files:**
- Create: `src/REST/DiagnosticsController.php`
- Modify: `src/Plugin.php` (register the route)
- Modify: `uninstall.php` (clean up the verdict transient)
- Modify: `scripts/verify.php` (assertions)

**Interfaces:**
- Consumes: `Client::diagnostics()`, `RuntimeRequestException::get_status_code()`, `Settings::configuration_metadata()`.
- Produces:
  - `DiagnosticsController::register_routes(): void` → `POST codex-provider/v1/diagnostics`, `permission_callback` = `manage_options`.
  - `DiagnosticsController::run(): WP_REST_Response` → body `['ok'=>bool,'checkedAt'=>string,'rows'=>array<int,array{id:string,label:string,status:string,detail:string}>,'config'=>array<int,array{label:string,value:string}>]`.
  - Writes transient `codex_provider_last_diagnostics` = `['checked_at'=>string,'ok'=>bool,'failed'=>string[]]` (TTL `HOUR_IN_SECONDS`). Never writes `HealthMonitor`.

- [ ] **Step 1: Write the failing test**

In `scripts/verify.php`, inside the IIFE (after the Task 5 block), add:

```php
		// --- Runtime diagnostics: controller row mapping + verdict storage. ---
		$codex_provider_diagnostics_rows = static function ( array $body ): array {
			$by_id = [];
			foreach ( $body['rows'] as $row ) {
				$by_id[ $row['id'] ] = $row;
			}
			return $by_id;
		};

		// 200 with a failing sidecar check => overall ok false, verdict recorded.
		delete_transient( 'codex_provider_last_diagnostics' );
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_http_json_response ) {
				if ( false !== strpos( $url, '/v1/diagnostics' ) ) {
					return $codex_provider_http_json_response(
						200,
						[
							'ok'     => false,
							'checks' => [
								[ 'id' => 'codex_cli', 'label' => 'Codex CLI', 'status' => 'fail', 'detail' => 'codex not found' ],
							],
						]
					);
				}
				return $preempt;
			},
			static function () use ( $codex_provider_assert, $codex_provider_diagnostics_rows ) {
				$response = \AIProviderForCodex\REST\DiagnosticsController::run();
				$body     = $response->get_data();
				$rows     = $codex_provider_diagnostics_rows( $body );
				$codex_provider_assert( false === $body['ok'], 'A 200 with a failing check must yield overall ok=false.' );
				$codex_provider_assert( 'pass' === $rows['reachable']['status'], 'Reachable row should pass on HTTP 200.' );
				$codex_provider_assert( 'pass' === $rows['bearer']['status'], 'Bearer row should pass on HTTP 200.' );
				$codex_provider_assert( 'fail' === $rows['codex_cli']['status'], 'Failing sidecar check should surface as a fail row.' );
				$verdict = get_transient( 'codex_provider_last_diagnostics' );
				$codex_provider_assert( is_array( $verdict ) && false === $verdict['ok'], 'Verdict transient must record ok=false.' );
			}
		);

		// 401 => bearer fail, reachable pass.
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( $codex_provider_http_json_response ) {
				if ( false !== strpos( $url, '/v1/diagnostics' ) ) {
					return $codex_provider_http_json_response( 401, [ 'error' => [ 'code' => 'unauthorized', 'message' => 'Invalid bearer token.' ] ] );
				}
				return $preempt;
			},
			static function () use ( $codex_provider_assert, $codex_provider_diagnostics_rows ) {
				$rows = $codex_provider_diagnostics_rows( \AIProviderForCodex\REST\DiagnosticsController::run()->get_data() );
				$codex_provider_assert( 'pass' === $rows['reachable']['status'], '401 still proves reachability.' );
				$codex_provider_assert( 'fail' === $rows['bearer']['status'], '401 must mark the bearer row as failed.' );
			}
		);

		// Transport failure => reachable fail.
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) {
				if ( false !== strpos( $url, '/v1/diagnostics' ) ) {
					return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to 127.0.0.1 port 4317: Connection refused' );
				}
				return $preempt;
			},
			static function () use ( $codex_provider_assert, $codex_provider_diagnostics_rows ) {
				$rows = $codex_provider_diagnostics_rows( \AIProviderForCodex\REST\DiagnosticsController::run()->get_data() );
				$codex_provider_assert( 'fail' === $rows['reachable']['status'], 'A transport failure must mark reachability as failed.' );
			}
		);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wp --path=$WP_PATH eval-file wp-content/plugins/scriptorium-ai-provider-for-codex/scripts/verify.php`
Expected: FAIL with `Class "AIProviderForCodex\REST\DiagnosticsController" not found`

- [ ] **Step 3: Create the controller**

Create `src/REST/DiagnosticsController.php`:

```php
<?php
/**
 * Read-only runtime diagnostics REST endpoint.
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\REST;

use AIProviderForCodex\Runtime\Client;
use AIProviderForCodex\Runtime\RuntimeRequestException;
use AIProviderForCodex\Runtime\Settings;
use RuntimeException;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs an explicit, admin-triggered diagnostic against the local runtime.
 */
final class DiagnosticsController {

	private const VERDICT_TRANSIENT = 'codex_provider_last_diagnostics';

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'codex-provider/v1',
			'/diagnostics',
			[
				'methods'             => 'POST',
				'permission_callback' => [ self::class, 'can_run' ],
				'callback'            => [ self::class, 'run' ],
			]
		);
	}

	/**
	 * Only administrators may run diagnostics (it exposes host paths and spawns a process).
	 *
	 * @return bool
	 */
	public static function can_run(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Runs the diagnostic and returns composed rows.
	 *
	 * @return WP_REST_Response
	 */
	public static function run(): WP_REST_Response {
		$rows = [];

		try {
			$result = ( new Client() )->diagnostics();
			$rows[] = self::row( 'reachable', __( 'Sidecar reachable', 'scriptorium-ai-provider-for-codex' ), 'pass', '' );
			$rows[] = self::row( 'bearer', __( 'Bearer token matches', 'scriptorium-ai-provider-for-codex' ), 'pass', '' );

			foreach ( (array) ( $result['checks'] ?? [] ) as $check ) {
				if ( ! is_array( $check ) ) {
					continue;
				}
				$rows[] = self::row(
					sanitize_key( (string) ( $check['id'] ?? '' ) ),
					(string) ( $check['label'] ?? '' ),
					self::normalize_status( (string) ( $check['status'] ?? 'fail' ) ),
					(string) ( $check['detail'] ?? '' )
				);
			}

			$ok = (bool) ( $result['ok'] ?? false );
		} catch ( RuntimeRequestException $exception ) {
			$rows[] = self::row( 'reachable', __( 'Sidecar reachable', 'scriptorium-ai-provider-for-codex' ), 'pass', '' );

			if ( 401 === $exception->get_status_code() ) {
				$rows[] = self::row( 'bearer', __( 'Bearer token matches', 'scriptorium-ai-provider-for-codex' ), 'fail', $exception->getMessage() );
			} else {
				$rows[] = self::row( 'bearer', __( 'Bearer token matches', 'scriptorium-ai-provider-for-codex' ), 'warn', $exception->getMessage() );
			}

			$ok = false;
		} catch ( RuntimeException $exception ) {
			$rows[] = self::row( 'reachable', __( 'Sidecar reachable', 'scriptorium-ai-provider-for-codex' ), 'fail', $exception->getMessage() );
			$ok     = false;
		}

		$checked_at = gmdate( 'Y-m-d H:i:s' );
		self::store_verdict( $ok, $checked_at, $rows );

		return new WP_REST_Response(
			[
				'ok'        => $ok,
				'checkedAt' => $checked_at,
				'rows'      => $rows,
				'config'    => self::config_rows(),
			]
		);
	}

	/**
	 * Builds a single result row.
	 *
	 * @param string $id Row ID.
	 * @param string $label Row label.
	 * @param string $status pass|warn|fail.
	 * @param string $detail Detail text.
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private static function row( string $id, string $label, string $status, string $detail ): array {
		return [
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
		];
	}

	/**
	 * Clamps a sidecar status to the known vocabulary.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private static function normalize_status( string $status ): string {
		return in_array( $status, [ 'pass', 'warn', 'fail' ], true ) ? $status : 'fail';
	}

	/**
	 * Returns the resolved-configuration info rows.
	 *
	 * @return array<int,array{label:string,value:string}>
	 */
	private static function config_rows(): array {
		$meta = Settings::configuration_metadata();

		return [
			[
				'label' => __( 'Runtime URL source', 'scriptorium-ai-provider-for-codex' ),
				'value' => (string) ( $meta['base_url_source'] ?? '' ),
			],
			[
				'label' => __( 'Bearer token source', 'scriptorium-ai-provider-for-codex' ),
				'value' => (string) ( $meta['bearer_token_source'] ?? '' ),
			],
		];
	}

	/**
	 * Stores a compact verdict for the passive settings card. Never touches HealthMonitor.
	 *
	 * @param bool                                                          $ok Overall result.
	 * @param string                                                        $checked_at GMT timestamp.
	 * @param array<int,array{id:string,label:string,status:string,detail:string}> $rows Result rows.
	 * @return void
	 */
	private static function store_verdict( bool $ok, string $checked_at, array $rows ): void {
		$failed = [];
		foreach ( $rows as $row ) {
			if ( 'fail' === $row['status'] ) {
				$failed[] = $row['label'];
			}
		}

		set_transient(
			self::VERDICT_TRANSIENT,
			[
				'checked_at' => $checked_at,
				'ok'         => $ok,
				'failed'     => $failed,
			],
			HOUR_IN_SECONDS
		);
	}
}
```

- [ ] **Step 4: Register the route**

In `src/Plugin.php`, add the import near the other REST imports:

```php
use AIProviderForCodex\REST\DiagnosticsController;
```

And register it next to the existing `rest_api_init` hooks. Find:

```php
		add_action( 'rest_api_init', [ ConnectController::class, 'register_routes' ] );
		add_action( 'rest_api_init', [ StatusController::class, 'register_routes' ] );
```

Replace with:

```php
		add_action( 'rest_api_init', [ ConnectController::class, 'register_routes' ] );
		add_action( 'rest_api_init', [ StatusController::class, 'register_routes' ] );
		add_action( 'rest_api_init', [ DiagnosticsController::class, 'register_routes' ] );
```

- [ ] **Step 5: Clean up the transient on uninstall**

In `uninstall.php`, find:

```php
delete_transient( 'codex_provider_runtime_health' );
delete_transient( 'codex_provider_site_catalog_refresh_attempt' );
```

Replace with:

```php
delete_transient( 'codex_provider_runtime_health' );
delete_transient( 'codex_provider_site_catalog_refresh_attempt' );
delete_transient( 'codex_provider_last_diagnostics' );
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `wp --path=$WP_PATH eval-file wp-content/plugins/scriptorium-ai-provider-for-codex/scripts/verify.php`
Expected: runs to completion with no exception.

- [ ] **Step 7: Lint**

Run: `php -l src/REST/DiagnosticsController.php && php -l src/Plugin.php && php -l uninstall.php`
Expected: `No syntax errors detected` for each.

- [ ] **Step 8: Commit**

```bash
git add src/REST/DiagnosticsController.php src/Plugin.php uninstall.php scripts/verify.php
git commit -m "rest: add manage_options diagnostics route with decoupled verdict store"
```

---

### Task 7: Setup snippet generator + suggested bearer token

**Files:**
- Create: `src/Admin/SetupSnippets.php`
- Modify: `uninstall.php` (clean up the suggested-token option)
- Modify: `readme.txt` (Privacy section)
- Modify: `scripts/verify.php` (assertions)

**Interfaces:**
- Consumes: `Settings::get_base_url()`, `Settings::get_bearer_token()`, `\AIProviderForCodex\PLUGIN_DIR`.
- Produces:
  - `SetupSnippets::systemd_unit(): string` — the bundled unit template with the placeholder path replaced by the real plugin dir.
  - `SetupSnippets::env_file(): string` — env content with documented defaults, the resolved base URL, and a stable suggested token.
  - `SetupSnippets::suggested_bearer_token(): string` — the configured token if set, else a cached generated one (option `codex_runtime_suggested_bearer_token`, `autoload=false`).

- [ ] **Step 1: Write the failing test**

In `scripts/verify.php`, inside the IIFE (after the Task 6 block), add:

```php
		// --- Setup snippets + stable suggested token. ---
		delete_option( 'codex_runtime_suggested_bearer_token' );
		$codex_provider_unit = \AIProviderForCodex\Admin\SetupSnippets::systemd_unit();
		$codex_provider_assert(
			false === strpos( $codex_provider_unit, '/path/to/wp-content/plugins/scriptorium-ai-provider-for-codex' ),
			'The systemd snippet must replace the placeholder plugin path.'
		);
		$codex_provider_assert(
			false !== strpos( $codex_provider_unit, untrailingslashit( \AIProviderForCodex\PLUGIN_DIR ) ),
			'The systemd snippet must contain the real plugin directory.'
		);

		$codex_provider_env = \AIProviderForCodex\Admin\SetupSnippets::env_file();
		$codex_provider_assert(
			false !== strpos( $codex_provider_env, 'CODEX_WP_BEARER_TOKEN=' ),
			'The env snippet must include the bearer token line.'
		);

		$codex_provider_token_a = \AIProviderForCodex\Admin\SetupSnippets::suggested_bearer_token();
		$codex_provider_token_b = \AIProviderForCodex\Admin\SetupSnippets::suggested_bearer_token();
		$codex_provider_assert( '' !== $codex_provider_token_a, 'A suggested token must be generated.' );
		$codex_provider_assert( $codex_provider_token_a === $codex_provider_token_b, 'The suggested token must be stable across calls.' );
		// A non-autoloaded option is absent from wp_load_alloptions() (which holds autoloaded options only).
		$codex_provider_assert(
			! array_key_exists( 'codex_runtime_suggested_bearer_token', wp_load_alloptions() ),
			'The suggested-token option must not autoload.'
		);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wp --path=$WP_PATH eval-file wp-content/plugins/scriptorium-ai-provider-for-codex/scripts/verify.php`
Expected: FAIL with `Class "AIProviderForCodex\Admin\SetupSnippets" not found`

- [ ] **Step 3: Create the generator**

Create `src/Admin/SetupSnippets.php`:

```php
<?php
/**
 * Read-only setup snippet generator (systemd unit + env file).
 *
 * @package AIProviderForCodex
 */

declare( strict_types=1 );

namespace AIProviderForCodex\Admin;

use AIProviderForCodex\Runtime\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds copy-paste setup snippets tailored to this install. Display only.
 */
final class SetupSnippets {

	private const TOKEN_OPTION       = 'codex_runtime_suggested_bearer_token';
	private const PLACEHOLDER_PATH   = '/path/to/wp-content/plugins/scriptorium-ai-provider-for-codex';

	/**
	 * Returns the bundled systemd unit with the real plugin path substituted.
	 *
	 * @return string
	 */
	public static function systemd_unit(): string {
		$template_path = untrailingslashit( \AIProviderForCodex\PLUGIN_DIR ) . '/sidecar/systemd/codex-wp-sidecar.service';
		$template      = is_readable( $template_path ) ? (string) file_get_contents( $template_path ) : '';

		if ( '' === $template ) {
			return '';
		}

		return str_replace( self::PLACEHOLDER_PATH, untrailingslashit( \AIProviderForCodex\PLUGIN_DIR ), $template );
	}

	/**
	 * Returns an env-file snippet with detected values and a stable suggested token.
	 *
	 * @return string
	 */
	public static function env_file(): string {
		$base_url = Settings::get_base_url();

		if ( '' === $base_url ) {
			$base_url = Settings::DEFAULT_RUNTIME_BASE_URL;
		}

		$lines = [
			'CODEX_BIN=/usr/local/bin/codex',
			'CODEX_WP_STORAGE_ROOT=/var/lib/codex-wp',
			'CODEX_WP_HOST=127.0.0.1',
			'CODEX_WP_PORT=4317',
			'CODEX_WP_RUNTIME_BASE_URL=' . $base_url,
			'CODEX_WP_BEARER_TOKEN=' . self::suggested_bearer_token(),
			'CODEX_RUNTIME_REQUEST_TIMEOUT=60',
			'CODEX_RUNTIME_TURN_TIMEOUT=300',
			'CODEX_RUNTIME_LOGIN_TIMEOUT=1800',
		];

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Returns a stable bearer token to show in the snippet.
	 *
	 * Uses the configured token when present; otherwise caches a generated one.
	 *
	 * @return string
	 */
	public static function suggested_bearer_token(): string {
		$configured = Settings::get_bearer_token();

		if ( '' !== $configured ) {
			return $configured;
		}

		$cached = (string) get_option( self::TOKEN_OPTION, '' );

		if ( '' !== $cached ) {
			return $cached;
		}

		$token = wp_generate_password( 64, false );
		add_option( self::TOKEN_OPTION, $token, '', false );

		return $token;
	}
}
```

- [ ] **Step 4: Clean up the option on uninstall**

In `uninstall.php`, find the option-name array:

```php
foreach ( [
	'codex_runtime_base_url',
	'codex_runtime_bearer_token',
	'codex_runtime_allowed_models',
	'codex_provider_schema_version',
	'codex_provider_connector_self_approval_seeded',
] as $codex_provider_option_name ) {
```

Replace with:

```php
foreach ( [
	'codex_runtime_base_url',
	'codex_runtime_bearer_token',
	'codex_runtime_allowed_models',
	'codex_runtime_suggested_bearer_token',
	'codex_provider_schema_version',
	'codex_provider_connector_self_approval_seeded',
] as $codex_provider_option_name ) {
```

- [ ] **Step 5: Document the option in the Privacy section**

In `readme.txt`, find the Privacy line listing the shared bearer token:

```
* the shared bearer token, unless it is managed externally
```

Add a line immediately after it:

```
* a locally generated suggested bearer token, shown only as setup guidance and never transmitted; removed on uninstall
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `wp --path=$WP_PATH eval-file wp-content/plugins/scriptorium-ai-provider-for-codex/scripts/verify.php`
Expected: runs to completion with no exception.

- [ ] **Step 7: Lint**

Run: `php -l src/Admin/SetupSnippets.php && php -l uninstall.php`
Expected: `No syntax errors detected` for each.

- [ ] **Step 8: Commit**

```bash
git add src/Admin/SetupSnippets.php uninstall.php readme.txt scripts/verify.php
git commit -m "admin: add read-only setup snippet generator and suggested token"
```

---

### Task 8: Settings page UX (passive load, two-signal card, guide, snippets, button)

**Files:**
- Modify: `src/Admin/SiteSettings.php`
- Modify: `src/Plugin.php` (script-module-data filter)
- Modify: `scripts/verify.php` (assertions)

**Interfaces:**
- Consumes: `HealthMonitor::get_status()`, `SetupSnippets::systemd_unit()/env_file()`, `Settings::configuration_metadata()`, the `codex_provider_last_diagnostics` transient.
- Produces:
  - `SiteSettings::SCRIPT_MODULE_ID = 'scriptorium-ai-provider-for-codex/diagnostics'`.
  - `SiteSettings::render_setup_guide(): void` (shared by the Help tab and the page body).
  - `SiteSettings::script_module_data( array $data ): array` (supplies `diagnosticsUrl`, `restNonce`, `labels`).
  - The settings page body contains: a `[data-codex-diagnostics-run]` button, a `[data-codex-diagnostics-results]` container, and the two snippet `<textarea>` blocks. `render_page()` makes **no** HTTP request on load.

- [ ] **Step 1: Write the failing test**

In `scripts/verify.php`, inside the IIFE (after the Task 7 block), add:

```php
		// --- Settings page is passive on load (no runtime HTTP during render). ---
		wp_set_current_user( 1 );
		$codex_provider_assert(
			current_user_can( 'manage_options' ),
			'verify.php expects user 1 to be an administrator for the settings-render check.'
		);

		$codex_provider_http_calls = 0;
		$codex_provider_with_mock_runtime(
			static function ( $preempt, array $args, string $url ) use ( &$codex_provider_http_calls ) {
				$codex_provider_http_calls++;
				return new WP_Error( 'blocked', 'No runtime HTTP is allowed during settings render.' );
			},
			static function () use ( &$codex_provider_http_calls, $codex_provider_assert ) {
				ob_start();
				SiteSettings::render_page();
				$html = (string) ob_get_clean();
				$codex_provider_assert( 0 === $codex_provider_http_calls, 'render_page() must not make runtime HTTP calls on load.' );
				$codex_provider_assert( false !== strpos( $html, 'data-codex-diagnostics-run' ), 'The settings page must render the Check runtime button.' );
				$codex_provider_assert( false !== strpos( $html, 'EnvironmentFile=/etc/codex-wp-sidecar.env' ), 'The settings page must render the systemd snippet.' );
			}
		);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `wp --path=$WP_PATH eval-file wp-content/plugins/scriptorium-ai-provider-for-codex/scripts/verify.php`
Expected: FAIL — either the page-load probe makes an HTTP call (`$codex_provider_http_calls` > 0) or the button marker is absent.

- [ ] **Step 3: Make page load passive**

In `src/Admin/SiteSettings.php`, find in `render_page()`:

```php
		$runtime_status  = $is_configured ? HealthMonitor::probe() : HealthMonitor::get_status();
```

Replace with:

```php
		$runtime_status  = HealthMonitor::get_status();
		$last_diagnostic = get_transient( 'codex_provider_last_diagnostics' );
```

- [ ] **Step 4: Add the module ID constant and enqueue the diagnostics module**

In `src/Admin/SiteSettings.php`, add the constant near `STYLE_HANDLE`:

```php
	private const SCRIPT_MODULE_ID = 'scriptorium-ai-provider-for-codex/diagnostics';
```

In `enqueue_assets()`, before the `wp_register_style(...)` call, add:

```php
		wp_register_script_module(
			self::SCRIPT_MODULE_ID,
			plugins_url( 'assets/diagnostics.js', \AIProviderForCodex\PLUGIN_FILE ),
			[],
			\AIProviderForCodex\VERSION
		);
		wp_enqueue_script_module( self::SCRIPT_MODULE_ID );
```

- [ ] **Step 5: Add the script-module-data provider**

In `src/Admin/SiteSettings.php`, add this method (e.g. after `enqueue_assets`):

```php
	/**
	 * Supplies config + labels to the diagnostics script module.
	 *
	 * @param array<string,mixed> $data Existing module data.
	 * @return array<string,mixed>
	 */
	public static function script_module_data( array $data ): array {
		return array_merge(
			$data,
			[
				'diagnosticsUrl' => rest_url( 'codex-provider/v1/diagnostics' ),
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				'labels'         => [
					'run'     => __( 'Check runtime', 'scriptorium-ai-provider-for-codex' ),
					'running' => __( 'Checking…', 'scriptorium-ai-provider-for-codex' ),
					'healthy' => __( 'All checks passed.', 'scriptorium-ai-provider-for-codex' ),
					'issues'  => __( 'Some checks failed.', 'scriptorium-ai-provider-for-codex' ),
					'failed'  => __( 'The diagnostic request failed.', 'scriptorium-ai-provider-for-codex' ),
				],
			]
		);
	}
```

- [ ] **Step 6: Extract the setup guide into a shared renderer (rename only — no markup changes)**

In `src/Admin/SiteSettings.php`, rename the existing `render_help_tab()` method to `render_setup_guide()`: change only its name in the signature and doc comment, keeping its entire body — the local variables and the `?> … <?php` Quick-setup markup — exactly as it is today. Then add a new thin `render_help_tab()` just above it that delegates:

```php
	/**
	 * Renders the contextual help tab content.
	 *
	 * @return void
	 */
	public static function render_help_tab(): void {
		self::render_setup_guide();
	}
```

After this step, `render_setup_guide()` holds the original Quick-setup markup verbatim, and both the Help tab and (next step) the page body call it.

- [ ] **Step 7: Render the diagnostics panel, guide, and snippets in the page body**

In `src/Admin/SiteSettings.php` `render_page()`, find the closing of the form and the wrap:

```php
				<?php submit_button( __( 'Save settings', 'scriptorium-ai-provider-for-codex' ) ); ?>
				</form>
			</div>
			<?php
```

Replace with:

```php
				<?php submit_button( __( 'Save settings', 'scriptorium-ai-provider-for-codex' ) ); ?>
				</form>

				<h2><?php esc_html_e( 'Runtime diagnostics', 'scriptorium-ai-provider-for-codex' ); ?></h2>
				<?php if ( is_array( $last_diagnostic ) ) : ?>
					<p class="description">
						<?php
						echo esc_html(
							SafeFormat::sprintf(
								/* translators: 1: pass/fail summary, 2: relative time. */
								__( 'Last check: %1$s (%2$s).', 'scriptorium-ai-provider-for-codex' ),
								empty( $last_diagnostic['ok'] )
									? sprintf(
										/* translators: %d: number of failed checks. */
										_n( '%d issue', '%d issues', count( (array) ( $last_diagnostic['failed'] ?? [] ) ), 'scriptorium-ai-provider-for-codex' ),
										count( (array) ( $last_diagnostic['failed'] ?? [] ) )
									)
									: __( 'healthy', 'scriptorium-ai-provider-for-codex' ),
								StatusLabels::relative_time( (string) ( $last_diagnostic['checked_at'] ?? '' ) )
							)
						);
						?>
					</p>
				<?php endif; ?>
				<p>
					<button type="button" class="button button-secondary" data-codex-diagnostics-run>
						<?php esc_html_e( 'Check runtime', 'scriptorium-ai-provider-for-codex' ); ?>
					</button>
				</p>
				<div data-codex-diagnostics-results aria-live="polite"></div>

				<h2><?php esc_html_e( 'Setup', 'scriptorium-ai-provider-for-codex' ); ?></h2>
				<?php self::render_setup_guide(); ?>

				<h3><?php esc_html_e( 'systemd unit (/etc/systemd/system/codex-wp-sidecar.service)', 'scriptorium-ai-provider-for-codex' ); ?></h3>
				<textarea class="large-text code" rows="12" readonly><?php echo esc_textarea( SetupSnippets::systemd_unit() ); ?></textarea>

				<h3><?php esc_html_e( 'Environment file (/etc/codex-wp-sidecar.env)', 'scriptorium-ai-provider-for-codex' ); ?></h3>
				<textarea class="large-text code" rows="10" readonly><?php echo esc_textarea( SetupSnippets::env_file() ); ?></textarea>
			</div>
			<?php
```

- [ ] **Step 8: Register the script-module-data filter**

In `src/Plugin.php`, find:

```php
		add_filter( 'script_module_data_scriptorium-ai-provider-for-codex/user-connection', [ UserConnectionPage::class, 'script_module_data' ] );
```

Add immediately after it:

```php
		add_filter( 'script_module_data_scriptorium-ai-provider-for-codex/diagnostics', [ SiteSettings::class, 'script_module_data' ] );
```

- [ ] **Step 9: Run the test to verify it passes**

Run: `wp --path=$WP_PATH eval-file wp-content/plugins/scriptorium-ai-provider-for-codex/scripts/verify.php`
Expected: runs to completion with no exception.

- [ ] **Step 10: Lint**

Run: `php -l src/Admin/SiteSettings.php && php -l src/Plugin.php`
Expected: `No syntax errors detected` for each.

- [ ] **Step 11: Commit**

```bash
git add src/Admin/SiteSettings.php src/Plugin.php scripts/verify.php
git commit -m "admin: passive settings load with in-body diagnostics, guide, and snippets"
```

---

### Task 9: Diagnostics browser module

**Files:**
- Create: `assets/diagnostics.js`
- Create: `assets/diagnostics.test.mjs`
- Modify: `scripts/verify.sh` (add the JS check/test lines)

**Interfaces:**
- Consumes: the REST body from Task 6 (`{ ok, checkedAt, rows:[{id,label,status,detail}], config:[{label,value}] }`) and the module data from Task 8 (`diagnosticsUrl`, `restNonce`, `labels`).
- Produces: `assets/diagnostics.js` exporting `mapDiagnosticsView(result, labels = {})` → `{ overall:{status,label}, rows:[{label,status,detail,indicatorClass}], config:[{label,value}] }`. Status→class map: `pass→good`, `warn→warning`, `fail→error`.

- [ ] **Step 1: Write the failing test**

Create `assets/diagnostics.test.mjs`:

```javascript
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { mapDiagnosticsView } from './diagnostics.js';

const LABELS = { healthy: 'All checks passed.', issues: 'Some checks failed.' };

test( 'maps statuses to indicator classes', () => {
	const view = mapDiagnosticsView(
		{
			ok: false,
			rows: [
				{ id: 'reachable', label: 'Sidecar reachable', status: 'pass', detail: '' },
				{ id: 'bearer', label: 'Bearer token matches', status: 'warn', detail: 'odd' },
				{ id: 'codex_cli', label: 'Codex CLI', status: 'fail', detail: 'missing' },
			],
			config: [ { label: 'Runtime URL source', value: 'env file' } ],
		},
		LABELS
	);

	assert.equal( view.rows[ 0 ].indicatorClass, 'good' );
	assert.equal( view.rows[ 1 ].indicatorClass, 'warning' );
	assert.equal( view.rows[ 2 ].indicatorClass, 'error' );
	assert.equal( view.config[ 0 ].value, 'env file' );
	assert.equal( view.overall.status, 'fail' );
	assert.equal( view.overall.label, 'Some checks failed.' );
} );

test( 'reports healthy overall when ok is true', () => {
	const view = mapDiagnosticsView( { ok: true, rows: [], config: [] }, LABELS );
	assert.equal( view.overall.status, 'pass' );
	assert.equal( view.overall.label, 'All checks passed.' );
} );
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `node --test assets/diagnostics.test.mjs`
Expected: FAIL — cannot find module `./diagnostics.js`.

- [ ] **Step 3: Create the module**

Create `assets/diagnostics.js`:

```javascript
const INDICATOR_CLASS = Object.freeze( {
	pass: 'good',
	warn: 'warning',
	fail: 'error',
} );

export function mapDiagnosticsView( result, labels = {} ) {
	const rows = ( result?.rows || [] ).map( ( row ) => ( {
		label: row.label || '',
		status: row.status || 'fail',
		detail: row.detail || '',
		indicatorClass: INDICATOR_CLASS[ row.status ] || 'error',
	} ) );

	const ok = Boolean( result?.ok );

	return {
		overall: {
			status: ok ? 'pass' : 'fail',
			label: ok ? labels.healthy || '' : labels.issues || '',
		},
		rows,
		config: result?.config || [],
	};
}

const MODULE_ID = 'scriptorium-ai-provider-for-codex/diagnostics';
const configElement = document.getElementById( `wp-script-module-data-${ MODULE_ID }` );
const runButton = document.querySelector( '[data-codex-diagnostics-run]' );
const resultsRoot = document.querySelector( '[data-codex-diagnostics-results]' );

if ( configElement && runButton && resultsRoot ) {
	const config = JSON.parse( configElement.textContent || '{}' );
	const labels = config.labels || {};

	const renderView = ( view ) => {
		resultsRoot.textContent = '';

		const overall = document.createElement( 'p' );
		const dot = document.createElement( 'span' );
		dot.className = `codex-indicator ${ INDICATOR_CLASS[ view.overall.status ] || 'error' }`;
		overall.appendChild( dot );
		overall.appendChild( document.createTextNode( view.overall.label ) );
		resultsRoot.appendChild( overall );

		const list = document.createElement( 'ul' );
		view.rows.forEach( ( row ) => {
			const item = document.createElement( 'li' );
			const indicator = document.createElement( 'span' );
			indicator.className = `codex-indicator ${ row.indicatorClass }`;
			item.appendChild( indicator );
			const text = row.detail ? `${ row.label }: ${ row.detail }` : row.label;
			item.appendChild( document.createTextNode( text ) );
			list.appendChild( item );
		} );
		resultsRoot.appendChild( list );

		view.config.forEach( ( entry ) => {
			const line = document.createElement( 'p' );
			line.className = 'description';
			line.textContent = `${ entry.label }: ${ entry.value }`;
			resultsRoot.appendChild( line );
		} );
	};

	runButton.addEventListener( 'click', async () => {
		runButton.disabled = true;
		const previous = runButton.textContent;
		runButton.textContent = labels.running || previous;

		try {
			const response = await window.fetch( config.diagnosticsUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'X-WP-Nonce': config.restNonce || '',
				},
			} );
			const body = await response.json();
			renderView( mapDiagnosticsView( body, labels ) );
		} catch ( error ) {
			resultsRoot.textContent = labels.failed || 'The diagnostic request failed.';
			window.console?.warn?.( 'Codex diagnostics request failed.', error );
		} finally {
			runButton.disabled = false;
			runButton.textContent = previous;
		}
	} );
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `node --test assets/diagnostics.test.mjs`
Expected: PASS (2 tests).

- [ ] **Step 5: Wire the asset into verify.sh**

In `scripts/verify.sh`, the asset checks are one line per file. Find:

```bash
node --input-type=module --check < "$ROOT_DIR/assets/user-connection.js" >/dev/null
node --test "$ROOT_DIR/assets/connection-flow.test.mjs"
node --test "$ROOT_DIR/assets/user-connection.test.mjs"
```

Replace that block with (adds a `--check` line and a `--test` line for diagnostics):

```bash
node --input-type=module --check < "$ROOT_DIR/assets/user-connection.js" >/dev/null
node --input-type=module --check < "$ROOT_DIR/assets/diagnostics.js" >/dev/null
node --test "$ROOT_DIR/assets/connection-flow.test.mjs"
node --test "$ROOT_DIR/assets/user-connection.test.mjs"
node --test "$ROOT_DIR/assets/diagnostics.test.mjs"
```

- [ ] **Step 6: Verify the syntax check passes**

Run: `node --input-type=module --check < assets/diagnostics.js && echo OK`
Expected: `OK`

- [ ] **Step 7: Commit**

```bash
git add assets/diagnostics.js assets/diagnostics.test.mjs scripts/verify.sh
git commit -m "assets: add diagnostics browser module and tests"
```

---

### Task 10: Full verification

**Files:** none (verification only)

- [ ] **Step 1: Run the JS tests**

Run: `node --test assets/diagnostics.test.mjs`
Expected: PASS.

- [ ] **Step 2: Run the sidecar tests**

Run: `python3 sidecar/scripts/test-diagnostics.py`
Expected: `OK` (all tests pass).

- [ ] **Step 3: Run the version-literal guard**

Run: `grep -c '"version": "0.1.5"' sidecar/app/main.py`
Expected: `2`.

- [ ] **Step 4: Run the full verification suite**

Run: `WP_PATH=$WP_PATH ./scripts/verify.sh`
Expected: completes with no failures (php -l, release/Plugin-Check consistency, version parity, `node --check` + `node --test`, and the WP-CLI `verify.php` end-to-end check all pass).

- [ ] **Step 5: Static analysis**

Run: `composer phpstan`
Expected: no new errors above the committed baseline.

- [ ] **Step 6: Final commit (if any baseline or lint fixups were needed)**

```bash
git add -A
git commit -m "chore: finalize runtime diagnostics verification"
```
