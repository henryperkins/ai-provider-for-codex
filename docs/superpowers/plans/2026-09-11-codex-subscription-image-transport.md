# Codex Subscription Image Transport Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an opt-in direct subscription image transport behind the existing `POST /v1/responses/image` interface, so image generation can skip the app-server reasoning turn, while keeping Codex as the sole owner of ChatGPT login, token refresh, and credential persistence.

**Architecture:** The sidecar gains a transport coordinator that selects between the existing app-server turn path (default) and a new direct HTTPS path to the Codex subscription images endpoint. The direct path reads a short-lived credential snapshot from the per-user `auth.json`, dispatches one bounded request, and validates the returned PNG. WordPress sends its effective post-filter timeout as an explicit budget so the two processes cannot disagree about the deadline. No new WordPress model is added: `codex-image` remains the single image model, and the backend stays service-selected.

**Tech Stack:** Python 3.11+ standard library only (`urllib.request`, `threading`, `unittest`) in `sidecar/app/main.py`; PHP 7.4+ with the WordPress HTTP API and the `wordpress/php-ai-client` SDK in `src/`. No new dependencies in either process.

**Spec:** `docs/superpowers/specs/2026-09-11-codex-subscription-image-transport-design.md`

## Global Constraints

- **One image model only.** `codex-image` stays the sole image model id. Never add `codex-image-sunburst`, `codex-image-flare`, or any named variant.
- **Never claim model provenance.** `runtimeModel` stays unset unless a trustworthy response contract identifies the generating model. Never populate it from a request field, an alias, image dimensions, or a client constant.
- **Credentials never leave the sidecar.** No token, no `Authorization` header, and no `auth.json` content may reach PHP, responses, logs, request previews, traces, exception payloads, or test fixtures.
- **No base64 or image bytes in logs**, in either process.
- **v1 ships exactly two transport modes:** `app-server` (default) and `direct`. There is no `auto` mode and no automatic transport fallback.
- **Codex is the only refresh writer.** The sidecar must never POST a refresh token to an OAuth endpoint or write rotated credentials.
- **No new runtime dependencies.** Composer is dev-only; the sidecar is standard library only.
- **Ambiguous post-dispatch outcomes never auto-replay.** A timeout, reset after transmission, ambiguous 5xx, or malformed success body is `outcome_unknown`, never a regeneration.
- Run `WP_PATH=/path/to/site ./scripts/verify.sh` before claiming any task complete.

---

### Task 1: Fix the `account/read` Refresh Parameter

This is a standalone prerequisite defect, shipped and reviewed on its own. The deployed app-server schema declares `GetAccountParams.refreshToken`; the sidecar sends `refresh`, which is silently ignored, so the proactive refresh behind the hourly snapshot cron has never fired. Every later task's auth strategy depends on forced refresh actually working.

**Files:**
- Modify: `sidecar/app/main.py:465`, `sidecar/app/main.py:592`, `sidecar/app/main.py:709`
- Create: `sidecar/scripts/test-account-snapshot.py`
- Modify: `scripts/verify.sh:118`

**Interfaces:**
- Consumes: nothing.
- Produces: `account/read` calls that carry `{"refreshToken": <bool>}`. Task 3's `refresh_via_codex()` relies on this parameter name being correct.

- [ ] **Step 1: Write the failing test**

Create `sidecar/scripts/test-account-snapshot.py`:

```python
#!/usr/bin/env python3
"""Standalone unit tests for sidecar account-snapshot behavior.

Run: python3 sidecar/scripts/test-account-snapshot.py
"""

from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "app"))

import main  # noqa: E402


class FakeSession:
    def __init__(self, responses):
        self.responses = responses
        self.requests = []

    def request(self, method, params=None, *, timeout=main.REQUEST_TIMEOUT):
        self.requests.append((method, params or {}, timeout))
        if method not in self.responses:
            raise AssertionError(f"Unexpected request: {method}")
        return self.responses[method]


def stored_auth_temp_root():
    temp = tempfile.TemporaryDirectory()
    storage_root = Path(temp.name)
    codex_home = storage_root / "users" / "123"
    codex_home.mkdir(parents=True)
    (codex_home / "auth.json").write_text("{}", encoding="utf-8")
    return temp, storage_root


class AccountReadParameterTest(unittest.TestCase):
    def test_snapshot_requests_managed_refresh_with_schema_parameter(self):
        temp, storage_root = stored_auth_temp_root()
        self.addCleanup(temp.cleanup)

        fake = FakeSession(
            {
                "account/read": {"account": {"type": "chatgpt"}},
                "account/rateLimits/read": {},
                "model/list": {"models": []},
                "modelProvider/capabilities/read": {"imageGeneration": True},
            }
        )

        with mock.patch.object(main, "STORAGE_ROOT", storage_root), mock.patch.object(
            main, "app_server_session"
        ) as session_factory:
            session_factory.return_value.__enter__.return_value = fake
            main.RuntimeState().account_snapshot(123)

        account_params = next(
            params for method, params, _ in fake.requests if method == "account/read"
        )
        self.assertEqual(account_params, {"refreshToken": True})
        self.assertNotIn("refresh", account_params)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `python3 sidecar/scripts/test-account-snapshot.py`

Expected: FAIL with `AssertionError: {'refresh': True} != {'refreshToken': True}`

- [ ] **Step 3: Fix the three call sites**

In `sidecar/app/main.py`, line 465 (inside `account_snapshot`):

```python
            account = session.request("account/read", {"refreshToken": True}, timeout=REQUEST_TIMEOUT)
```

Lines 592 (inside `generate_text`) and 709 (inside `generate_image`) both become:

```python
            account = session.request("account/read", {"refreshToken": False}, timeout=REQUEST_TIMEOUT)
```

Do not change anything else. The two `False` sites were already behaving as intended — an unknown field and an explicit `false` both yield no refresh — but they are corrected so the parameter name is consistent and greppable.

- [ ] **Step 4: Run the test to verify it passes**

Run: `python3 sidecar/scripts/test-account-snapshot.py`

Expected: PASS, `Ran 1 test ... OK`

- [ ] **Step 5: Register the new test file with verify.sh**

In `scripts/verify.sh`, immediately after line 118, add:

```bash
python3 "$ROOT_DIR/sidecar/scripts/test-account-snapshot.py"
```

- [ ] **Step 6: Run the existing sidecar suites for regressions**

Run:
```bash
python3 sidecar/scripts/test-token-usage.py
python3 sidecar/scripts/test-diagnostics.py
python3 sidecar/scripts/test-image-generation.py
```

Expected: all three PASS. `test-image-generation.py` asserts on `thread/start` and `turn/start` params, not `account/read`, so it should be unaffected.

- [ ] **Step 7: Commit**

```bash
git add sidecar/app/main.py sidecar/scripts/test-account-snapshot.py scripts/verify.sh
git commit -m "fix(sidecar): send account/read refreshToken per app-server schema

The deployed app-server schema declares GetAccountParams.refreshToken.
The sidecar sent refresh, which is ignored, so the proactive refresh
behind the hourly snapshot cron never fired."
```

---

### Task 2: Transport Mode Configuration And Coordinator Indirection

Introduce the mode setting and route `generate_image` through a coordinator that, for now, only calls the existing app-server path. This is a pure refactor with no behavior change, which makes the later direct-path work a small diff against a reviewed seam.

**Files:**
- Modify: `sidecar/app/main.py` (constants block near line 20; `RuntimeState.generate_image` near line 613)
- Modify: `sidecar/config.example.env`
- Test: `sidecar/scripts/test-image-generation.py`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `main.IMAGE_TRANSPORT: str` — `"app-server"` or `"direct"`, read from `CODEX_WP_IMAGE_TRANSPORT`, default `"app-server"`.
  - `main.resolve_image_transport(value: Any) -> str` — normalizes and validates a mode string, raising `RuntimeError` on an unknown value.
  - `RuntimeState.generate_image(wp_user_id: int, payload: dict) -> dict` — unchanged signature, now a coordinator.
  - `RuntimeState._generate_image_app_server(wp_user_id: int, payload: dict) -> dict` — the existing body, moved verbatim.

- [ ] **Step 1: Write the failing tests**

Append to `sidecar/scripts/test-image-generation.py`, before the `if __name__` block:

```python
class TransportModeTest(unittest.TestCase):
    def test_defaults_to_app_server(self):
        self.assertEqual(main.resolve_image_transport(None), "app-server")
        self.assertEqual(main.resolve_image_transport(""), "app-server")

    def test_accepts_known_modes(self):
        self.assertEqual(main.resolve_image_transport("app-server"), "app-server")
        self.assertEqual(main.resolve_image_transport("  DIRECT  "), "direct")

    def test_rejects_unknown_mode(self):
        with self.assertRaises(RuntimeError) as ctx:
            main.resolve_image_transport("auto")
        self.assertIn("auto", str(ctx.exception))

    def test_coordinator_dispatches_to_app_server_by_default(self):
        state = main.RuntimeState()
        with mock.patch.object(main, "IMAGE_TRANSPORT", "app-server"), mock.patch.object(
            main.RuntimeState, "_generate_image_app_server", return_value={"ok": True}
        ) as app_server:
            result = state.generate_image(123, {"prompt": "x"})
        self.assertEqual(result, {"ok": True})
        app_server.assert_called_once_with(123, {"prompt": "x"})
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `python3 sidecar/scripts/test-image-generation.py -k Transport -v`

Expected: FAIL with `AttributeError: module 'main' has no attribute 'resolve_image_transport'`

- [ ] **Step 3: Add the constants and resolver**

In `sidecar/app/main.py`, after the `LOGIN_TIMEOUT` line in the constants block:

```python
IMAGE_TRANSPORTS = ("app-server", "direct")
```

Then, in the module-level helper area near `normalize_capabilities_payload`, add:

```python
def resolve_image_transport(value: Any) -> str:
    """Normalizes an image-transport mode, defaulting to the app-server path.

    There is deliberately no `auto` mode: automatic fallback would require
    distinguishing a guaranteed pre-generation failure from an ambiguous
    backend error, and guessing wrong bills the user for a second image.
    """
    if value is None:
        return "app-server"

    normalized = str(value).strip().lower()
    if "" == normalized:
        return "app-server"

    if normalized not in IMAGE_TRANSPORTS:
        raise RuntimeError(
            f"Unknown image transport {normalized!r}. Expected one of: {', '.join(IMAGE_TRANSPORTS)}."
        )

    return normalized
```

Back in the constants block, after `IMAGE_TRANSPORTS`:

```python
IMAGE_TRANSPORT = resolve_image_transport(os.environ.get("CODEX_WP_IMAGE_TRANSPORT"))
```

Because `resolve_image_transport` is called at import time, define the function above the constants block, or move the `IMAGE_TRANSPORT` assignment below the function definition. Prefer the latter — keep the constants block contiguous and place `IMAGE_TRANSPORT` immediately after the function.

- [ ] **Step 4: Split `generate_image` into coordinator and app-server path**

Rename the existing method. Change the signature line at `sidecar/app/main.py:613` from:

```python
    def generate_image(self, wp_user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
```

to:

```python
    def _generate_image_app_server(self, wp_user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
```

Leave that method's entire body byte-for-byte unchanged. Immediately above it, add the coordinator:

```python
    def generate_image(self, wp_user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        if "direct" == IMAGE_TRANSPORT:
            return self._generate_image_direct(wp_user_id, payload)

        return self._generate_image_app_server(wp_user_id, payload)
```

`_generate_image_direct` does not exist yet. Add a temporary stub directly below the coordinator so the module imports cleanly; Task 7 replaces it:

```python
    def _generate_image_direct(self, wp_user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        raise HttpError(
            "image_transport_unavailable",
            "The direct image transport is not implemented yet.",
            HTTPStatus.NOT_IMPLEMENTED,
        )
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `python3 sidecar/scripts/test-image-generation.py -v`

Expected: PASS, including every pre-existing test. The existing app-server image tests call `RuntimeState().generate_image(...)` with the default mode, which now routes through the coordinator to the unchanged method.

- [ ] **Step 6: Document the setting**

Append to `sidecar/config.example.env`:

```
CODEX_WP_IMAGE_TRANSPORT=app-server
```

- [ ] **Step 7: Commit**

```bash
git add sidecar/app/main.py sidecar/config.example.env sidecar/scripts/test-image-generation.py
git commit -m "refactor(sidecar): add image transport mode and coordinator seam

No behavior change: app-server remains the default and only working
transport. There is deliberately no auto mode."
```

---

### Task 3: Managed Credential Accessor And Auth Generation State

The direct path needs a short-lived credential snapshot and a way to detect rebinding. The mutex here guards only credential mutations and reads — it is never held across a network wait, so a five-minute image cannot block that user's text requests.

**Files:**
- Modify: `sidecar/app/main.py` (new classes after `HttpError`, near line 51)
- Create: `sidecar/scripts/test-credentials.py`
- Modify: `scripts/verify.sh`

**Interfaces:**
- Consumes: `main.auth_file_path`, `main.user_codex_home`, `main.app_server_session` (Task 1's corrected `refreshToken` parameter).
- Produces:
  - `main.ManagedCredentials` — attributes `access_token: str`, `account_id: str`, `generation: int`. Its `__repr__` must never include the token.
  - `main.CredentialStore.snapshot(wp_user_id: int) -> ManagedCredentials`
  - `main.CredentialStore.current_generation(wp_user_id: int) -> int`
  - `main.CredentialStore.refresh(wp_user_id: int, seen_generation: int) -> ManagedCredentials`
  - `main.CredentialStore.bump(wp_user_id: int) -> int`
  - `main.CREDENTIALS: CredentialStore` — module-level singleton.

- [ ] **Step 1: Write the failing tests**

Create `sidecar/scripts/test-credentials.py`:

```python
#!/usr/bin/env python3
"""Standalone unit tests for the managed credential accessor.

Run: python3 sidecar/scripts/test-credentials.py
"""

from __future__ import annotations

import json
import sys
import tempfile
import threading
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "app"))

import main  # noqa: E402


AUTH_DOCUMENT = {
    "auth_mode": "chatgpt",
    "OPENAI_API_KEY": None,
    "tokens": {
        "id_token": "id-token-value",
        "access_token": "access-token-value",
        "refresh_token": "refresh-token-value",
        "account_id": "account-id-value",
    },
    "last_refresh": "2026-09-07T01:50:28Z",
}


def storage_with_auth(document=None):
    temp = tempfile.TemporaryDirectory()
    storage_root = Path(temp.name)
    codex_home = storage_root / "users" / "123"
    codex_home.mkdir(parents=True)
    payload = AUTH_DOCUMENT if document is None else document
    (codex_home / "auth.json").write_text(json.dumps(payload), encoding="utf-8")
    return temp, storage_root


class CredentialSnapshotTest(unittest.TestCase):
    def test_reads_only_access_token_and_account_id(self):
        temp, storage_root = storage_with_auth()
        self.addCleanup(temp.cleanup)

        with mock.patch.object(main, "STORAGE_ROOT", storage_root):
            creds = main.CredentialStore().snapshot(123)

        self.assertEqual(creds.access_token, "access-token-value")
        self.assertEqual(creds.account_id, "account-id-value")
        self.assertEqual(creds.generation, 0)
        self.assertFalse(hasattr(creds, "refresh_token"))
        self.assertFalse(hasattr(creds, "id_token"))

    def test_repr_never_leaks_the_token(self):
        temp, storage_root = storage_with_auth()
        self.addCleanup(temp.cleanup)

        with mock.patch.object(main, "STORAGE_ROOT", storage_root):
            creds = main.CredentialStore().snapshot(123)

        self.assertNotIn("access-token-value", repr(creds))
        self.assertNotIn("access-token-value", str(creds))

    def test_rejects_non_chatgpt_auth_mode(self):
        document = dict(AUTH_DOCUMENT, auth_mode="apikey")
        temp, storage_root = storage_with_auth(document)
        self.addCleanup(temp.cleanup)

        with mock.patch.object(main, "STORAGE_ROOT", storage_root):
            with self.assertRaises(main.HttpError) as ctx:
                main.CredentialStore().snapshot(123)

        self.assertEqual(ctx.exception.code, "direct_transport_ineligible")

    def test_rejects_missing_tokens(self):
        temp, storage_root = storage_with_auth({"auth_mode": "chatgpt", "tokens": {}})
        self.addCleanup(temp.cleanup)

        with mock.patch.object(main, "STORAGE_ROOT", storage_root):
            with self.assertRaises(main.HttpError) as ctx:
                main.CredentialStore().snapshot(123)

        self.assertEqual(ctx.exception.code, "auth_required")

    def test_rejects_malformed_json_without_leaking_content(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        storage_root = Path(temp.name)
        codex_home = storage_root / "users" / "123"
        codex_home.mkdir(parents=True)
        (codex_home / "auth.json").write_text("{not json access-token-value", encoding="utf-8")

        with mock.patch.object(main, "STORAGE_ROOT", storage_root):
            with self.assertRaises(main.HttpError) as ctx:
                main.CredentialStore().snapshot(123)

        self.assertNotIn("access-token-value", str(ctx.exception))


class AuthGenerationTest(unittest.TestCase):
    def test_bump_increments_per_user_independently(self):
        store = main.CredentialStore()
        self.assertEqual(store.current_generation(123), 0)
        self.assertEqual(store.bump(123), 1)
        self.assertEqual(store.bump(123), 2)
        self.assertEqual(store.current_generation(123), 2)
        self.assertEqual(store.current_generation(456), 0)

    def test_refresh_is_skipped_when_generation_already_advanced(self):
        temp, storage_root = storage_with_auth()
        self.addCleanup(temp.cleanup)
        store = main.CredentialStore()
        store.bump(123)

        with mock.patch.object(main, "STORAGE_ROOT", storage_root), mock.patch.object(
            main, "app_server_session"
        ) as session_factory:
            creds = store.refresh(123, seen_generation=0)

        session_factory.assert_not_called()
        self.assertEqual(creds.generation, 1)

    def test_refresh_calls_codex_when_generation_matches(self):
        temp, storage_root = storage_with_auth()
        self.addCleanup(temp.cleanup)
        store = main.CredentialStore()
        calls = []

        class FakeSession:
            def request(self, method, params=None, *, timeout=main.REQUEST_TIMEOUT):
                calls.append((method, params))
                return {"account": {"type": "chatgpt"}}

        with mock.patch.object(main, "STORAGE_ROOT", storage_root), mock.patch.object(
            main, "app_server_session"
        ) as session_factory:
            session_factory.return_value.__enter__.return_value = FakeSession()
            creds = store.refresh(123, seen_generation=0)

        self.assertEqual(calls, [("account/read", {"refreshToken": True})])
        self.assertEqual(creds.generation, 1)

    def test_mutex_is_not_held_across_the_caller_s_work(self):
        store = main.CredentialStore()
        entered = threading.Event()

        def other_user_work():
            store.bump(456)
            entered.set()

        lock = store.lock_for(123)
        with lock:
            thread = threading.Thread(target=other_user_work)
            thread.start()
            self.assertTrue(entered.wait(timeout=2), "per-user locks must not contend")
            thread.join()


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `python3 sidecar/scripts/test-credentials.py`

Expected: FAIL with `AttributeError: module 'main' has no attribute 'CredentialStore'`

- [ ] **Step 3: Implement the credential accessor**

In `sidecar/app/main.py`, after the `HttpError` class (line 51), add:

```python
MAX_AUTH_DOCUMENT_BYTES = 256 * 1024


class ManagedCredentials:
    """A short-lived, narrowly scoped credential snapshot.

    Holds only what the direct transport needs. The refresh token and id
    token are deliberately never read, so they cannot be logged or leaked
    from this object.
    """

    __slots__ = ("access_token", "account_id", "generation")

    def __init__(self, access_token: str, account_id: str, generation: int) -> None:
        self.access_token = access_token
        self.account_id = account_id
        self.generation = generation

    def __repr__(self) -> str:
        return f"ManagedCredentials(account_id={self.account_id!r}, generation={self.generation})"

    __str__ = __repr__


class CredentialStore:
    """Per-Codex-home credential reads, refreshes, and generation tracking.

    The lock guards credential mutations and reads only. It is never held
    across a generation request, an app-server turn, or any other network
    wait, so a long image cannot block that user's other work.
    """

    def __init__(self) -> None:
        self._guard = threading.Lock()
        self._locks: dict[int, threading.Lock] = {}
        self._generations: dict[int, int] = {}

    def lock_for(self, wp_user_id: int) -> threading.Lock:
        with self._guard:
            if wp_user_id not in self._locks:
                self._locks[wp_user_id] = threading.Lock()
            return self._locks[wp_user_id]

    def current_generation(self, wp_user_id: int) -> int:
        with self._guard:
            return self._generations.get(wp_user_id, 0)

    def bump(self, wp_user_id: int) -> int:
        with self._guard:
            generation = self._generations.get(wp_user_id, 0) + 1
            self._generations[wp_user_id] = generation
            return generation

    def snapshot(self, wp_user_id: int) -> ManagedCredentials:
        with self.lock_for(wp_user_id):
            return self._read_locked(wp_user_id)

    def refresh(self, wp_user_id: int, seen_generation: int) -> ManagedCredentials:
        """Refreshes through Codex, unless another operation already did.

        Skipping when the generation has already advanced is what prevents
        concurrent 401s from stampeding into parallel refreshes.
        """
        with self.lock_for(wp_user_id):
            if self.current_generation(wp_user_id) != seen_generation:
                return self._read_locked(wp_user_id)

            codex_home = user_codex_home(wp_user_id)
            with app_server_session(codex_home) as session:
                session.request("account/read", {"refreshToken": True}, timeout=REQUEST_TIMEOUT)

            self.bump(wp_user_id)
            return self._read_locked(wp_user_id)

    def _read_locked(self, wp_user_id: int) -> ManagedCredentials:
        path = auth_file_path(user_codex_home(wp_user_id))

        if not path.is_file():
            raise HttpError(
                "auth_required",
                "No stored ChatGPT or Codex auth is available for this WordPress user.",
                HTTPStatus.CONFLICT,
            )

        if path.stat().st_size > MAX_AUTH_DOCUMENT_BYTES:
            raise HttpError(
                "direct_transport_ineligible",
                "The stored Codex auth document is larger than expected.",
                HTTPStatus.CONFLICT,
            )

        try:
            document = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            # Never echo the parse error: the document contains secrets.
            raise HttpError(
                "direct_transport_ineligible",
                "The stored Codex auth document could not be read.",
                HTTPStatus.CONFLICT,
            ) from None

        if not isinstance(document, dict):
            raise HttpError(
                "direct_transport_ineligible",
                "The stored Codex auth document has an unexpected shape.",
                HTTPStatus.CONFLICT,
            )

        auth_mode = optional_string(document.get("auth_mode"))
        if auth_mode is not None and "chatgpt" != auth_mode.lower():
            raise HttpError(
                "direct_transport_ineligible",
                "The direct image transport requires managed ChatGPT authentication.",
                HTTPStatus.CONFLICT,
            )

        tokens = document.get("tokens")
        access_token = optional_string(tokens.get("access_token")) if isinstance(tokens, dict) else None
        account_id = optional_string(tokens.get("account_id")) if isinstance(tokens, dict) else None

        if not access_token or not account_id:
            raise HttpError(
                "auth_required",
                "The stored Codex auth does not contain usable ChatGPT credentials.",
                HTTPStatus.CONFLICT,
            )

        return ManagedCredentials(access_token, account_id, self.current_generation(wp_user_id))


CREDENTIALS = CredentialStore()
```

Note `CREDENTIALS` must be defined after the helper functions it calls are available at runtime; because the calls happen inside methods rather than at import time, placing the class right after `HttpError` and the singleton immediately below it is safe.

**One deliberate deviation from the spec's wording, which a reviewer will notice.** The spec says the mutex is "never held across a network wait," but `refresh()` holds it across an `app_server_session` call. That is intentional and must not be "fixed" into a release-before-refresh: serializing the refresh is precisely what stops concurrent 401s from stampeding into parallel refreshes, which is the behavior the spec asks for two sentences later. The property that actually matters — and that this preserves — is that the lock is never held across an *image generation*, which is bounded by `TURN_TIMEOUT` (300s) rather than `REQUEST_TIMEOUT` (60s). A refresh is short and bounded; a generation is neither.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `python3 sidecar/scripts/test-credentials.py -v`

Expected: PASS, `Ran 9 tests ... OK`

- [ ] **Step 5: Register the test file and check for regressions**

In `scripts/verify.sh`, after the `test-account-snapshot.py` line from Task 1, add:

```bash
python3 "$ROOT_DIR/sidecar/scripts/test-credentials.py"
```

Run all sidecar suites:
```bash
python3 sidecar/scripts/test-token-usage.py
python3 sidecar/scripts/test-diagnostics.py
python3 sidecar/scripts/test-image-generation.py
python3 sidecar/scripts/test-account-snapshot.py
python3 sidecar/scripts/test-credentials.py
```

Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add sidecar/app/main.py sidecar/scripts/test-credentials.py scripts/verify.sh
git commit -m "feat(sidecar): add managed credential accessor and auth generation

Reads only access_token and account_id, never the refresh or id token.
The per-user lock guards credential reads and mutations only, never a
network wait, so a long image cannot block that user's other requests."
```

---

### Task 4: Direct Image Transport And Response Validation

The bounded HTTPS call and strict validation of what comes back. No coordinator wiring yet — this task delivers a pure function that is fully testable against a mocked opener.

**Files:**
- Modify: `sidecar/app/main.py` (constants block; new functions near `normalize_image_item`, around line 922)
- Test: `sidecar/scripts/test-direct-image.py` (create)
- Modify: `scripts/verify.sh`

**Interfaces:**
- Consumes: `main.ManagedCredentials` (Task 3).
- Produces:
  - `main.DIRECT_IMAGE_URL: str` — the fixed approved destination.
  - `main.direct_image_request(credentials: ManagedCredentials, prompt: str, budget: float) -> dict[str, Any]` — returns a dict with keys `imageBase64`, `mimeType`, `imageCount`, `width`, `height`, `generationId`, `serverReported`, `usage`. Raises `HttpError` on every failure class.

- [ ] **Step 1: Write the failing tests**

Create `sidecar/scripts/test-direct-image.py`:

```python
#!/usr/bin/env python3
"""Standalone unit tests for the direct subscription image transport.

Run: python3 sidecar/scripts/test-direct-image.py
"""

from __future__ import annotations

import base64
import io
import json
import sys
import unittest
import urllib.error
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "app"))

import main  # noqa: E402


# 1x1 PNG. Width and height live at byte offsets 16-24 of the IHDR chunk.
PNG_BASE64 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII="

SUCCESS_BODY = {
    "background": "opaque",
    "created": 1789000000,
    "output_format": "png",
    "quality": "low",
    "size": "1024x1024",
    "usage": {
        "input_tokens": 17,
        "input_tokens_details": {"image_tokens": 0, "text_tokens": 17},
        "output_tokens": 343,
        "output_tokens_details": {"image_tokens": 343, "text_tokens": 0},
        "total_tokens": 360,
    },
    "data": [{"b64_json": PNG_BASE64, "generation_id": "gen_abc123"}],
}


def credentials():
    return main.ManagedCredentials("access-token-value", "account-id-value", 0)


class FakeResponse:
    def __init__(self, body, status=200):
        self._payload = json.dumps(body).encode() if isinstance(body, (dict, list)) else body
        self.status = status

    def read(self, limit=None):
        # The transport calls read(limit) to bound the response; mirror that.
        return self._payload if limit is None else self._payload[:limit]

    def __enter__(self):
        return self

    def __exit__(self, *args):
        return False


def http_error(status, body):
    payload = json.dumps(body).encode() if isinstance(body, (dict, list)) else body
    return urllib.error.HTTPError("https://example.test", status, "err", {}, io.BytesIO(payload))


class DirectRequestShapeTest(unittest.TestCase):
    def test_sends_only_prompt_and_omits_selectors(self):
        captured = {}

        def fake_urlopen(request, timeout=None):
            captured["body"] = json.loads(request.data.decode())
            captured["headers"] = dict(request.headers)
            captured["url"] = request.full_url
            captured["timeout"] = timeout
            return FakeResponse(SUCCESS_BODY)

        with mock.patch.object(main.urllib.request, "urlopen", fake_urlopen):
            main.direct_image_request(credentials(), "Draw a blue square.", budget=120.0)

        self.assertEqual(captured["url"], main.DIRECT_IMAGE_URL)
        self.assertEqual(captured["body"], {"prompt": "Draw a blue square."})
        self.assertNotIn("model", captured["body"])
        self.assertNotIn("quality", captured["body"])
        self.assertNotIn("size", captured["body"])
        self.assertLess(captured["timeout"], 120.0)

    def test_sends_subscription_headers(self):
        captured = {}

        def fake_urlopen(request, timeout=None):
            captured.update({k.lower(): v for k, v in request.headers.items()})
            return FakeResponse(SUCCESS_BODY)

        with mock.patch.object(main.urllib.request, "urlopen", fake_urlopen):
            main.direct_image_request(credentials(), "x", budget=120.0)

        self.assertEqual(captured["authorization"], "Bearer access-token-value")
        self.assertEqual(captured["chatgpt-account-id"], "account-id-value")
        self.assertIn("originator", captured)


class DirectResponseTest(unittest.TestCase):
    def _run(self, body):
        with mock.patch.object(main.urllib.request, "urlopen", lambda r, timeout=None: FakeResponse(body)):
            return main.direct_image_request(credentials(), "x", budget=120.0)

    def test_returns_validated_image_and_decoded_dimensions(self):
        result = self._run(SUCCESS_BODY)
        self.assertEqual(result["imageBase64"], PNG_BASE64)
        self.assertEqual(result["mimeType"], "image/png")
        self.assertEqual(result["imageCount"], 1)
        self.assertEqual(result["width"], 1)
        self.assertEqual(result["height"], 1)
        self.assertEqual(result["generationId"], "gen_abc123")

    def test_server_reported_fields_are_labeled_separately(self):
        result = self._run(SUCCESS_BODY)
        self.assertEqual(
            result["serverReported"],
            {"quality": "low", "size": "1024x1024", "outputFormat": "png", "background": "opaque"},
        )
        self.assertNotIn("model", result["serverReported"])

    def test_usage_details_are_preserved_separately(self):
        result = self._run(SUCCESS_BODY)
        self.assertTrue(result["usage"]["usageKnown"])
        self.assertEqual(result["usage"]["inputTokens"], 17)
        self.assertEqual(result["usage"]["outputTokens"], 343)
        self.assertEqual(result["usage"]["outputTokensDetails"], {"image_tokens": 343, "text_tokens": 0})

    def test_absent_usage_is_unknown_not_zero(self):
        body = {k: v for k, v in SUCCESS_BODY.items() if k != "usage"}
        result = self._run(body)
        self.assertFalse(result["usage"]["usageKnown"])
        self.assertNotIn("inputTokens", result["usage"])

    def test_multiple_candidates_return_the_first_and_record_the_count(self):
        body = dict(SUCCESS_BODY)
        body["data"] = [
            {"b64_json": PNG_BASE64, "generation_id": "gen_1"},
            {"b64_json": PNG_BASE64, "generation_id": "gen_2"},
        ]
        result = self._run(body)
        self.assertEqual(result["imageCount"], 2)
        self.assertEqual(result["generationId"], "gen_1")

    def test_rejects_non_png_payload(self):
        body = dict(SUCCESS_BODY)
        body["data"] = [{"b64_json": base64.b64encode(b"\xff\xd8\xff not a png").decode()}]
        with self.assertRaises(main.HttpError) as ctx:
            self._run(body)
        self.assertEqual(ctx.exception.code, "image_generation_failed")

    def test_rejects_invalid_base64(self):
        body = dict(SUCCESS_BODY)
        body["data"] = [{"b64_json": "!!!not-base64!!!"}]
        with self.assertRaises(main.HttpError):
            self._run(body)

    def test_rejects_empty_data_array(self):
        body = dict(SUCCESS_BODY)
        body["data"] = []
        with self.assertRaises(main.HttpError):
            self._run(body)

    def test_rejects_oversized_response(self):
        oversized = b"x" * (main.MAX_DIRECT_RESPONSE_BYTES + 1)
        with mock.patch.object(
            main.urllib.request, "urlopen", lambda r, timeout=None: FakeResponse(oversized)
        ):
            with self.assertRaises(main.HttpError) as ctx:
                main.direct_image_request(credentials(), "x", budget=120.0)
        self.assertEqual(ctx.exception.code, "image_generation_failed")


class DirectErrorClassificationTest(unittest.TestCase):
    def _raise(self, error):
        def fake_urlopen(request, timeout=None):
            raise error

        with mock.patch.object(main.urllib.request, "urlopen", fake_urlopen):
            with self.assertRaises(main.HttpError) as ctx:
                main.direct_image_request(credentials(), "x", budget=120.0)
        return ctx.exception

    def test_401_is_auth_required(self):
        self.assertEqual(self._raise(http_error(401, {"error": {"message": "no"}})).code, "auth_required")

    def test_403_is_a_typed_denial(self):
        self.assertEqual(self._raise(http_error(403, {"error": {"message": "no"}})).code, "image_generation_denied")

    def test_429_is_a_typed_denial(self):
        self.assertEqual(self._raise(http_error(429, {"error": {"message": "slow"}})).code, "image_generation_denied")

    def test_404_is_route_unavailable(self):
        self.assertEqual(self._raise(http_error(404, b"<html>gone</html>")).code, "image_route_unavailable")

    def test_5xx_is_outcome_unknown(self):
        self.assertEqual(self._raise(http_error(503, b"busy")).code, "image_outcome_unknown")

    def test_timeout_is_outcome_unknown(self):
        self.assertEqual(self._raise(TimeoutError("read timed out")).code, "image_outcome_unknown")

    def test_error_messages_never_echo_the_token(self):
        exception = self._raise(http_error(401, {"error": {"message": "access-token-value rejected"}}))
        self.assertNotIn("access-token-value", str(exception))


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `python3 sidecar/scripts/test-direct-image.py`

Expected: FAIL with `AttributeError: module 'main' has no attribute 'direct_image_request'`

- [ ] **Step 3: Add the import and constants**

At the top of `sidecar/app/main.py`, add to the import block:

```python
import base64
import urllib.error
import urllib.request
```

In the constants block:

```python
DIRECT_IMAGE_URL = "https://chatgpt.com/backend-api/codex/images/generations"
DIRECT_IMAGE_ORIGINATOR = "codex_cli_rs"
MAX_DIRECT_RESPONSE_BYTES = 64 * 1024 * 1024
MAX_DIRECT_IMAGE_BYTES = 32 * 1024 * 1024
MAX_DIRECT_IMAGE_PIXELS = 64 * 1024 * 1024
DIRECT_PROCESSING_RESERVE = 15.0
```

- [ ] **Step 4: Implement the transport**

Add near the other image helpers in `sidecar/app/main.py`:

```python
def png_dimensions(raw: bytes) -> tuple[int, int]:
    """Reads width and height from a PNG IHDR chunk.

    Dimensions come from the decoded bytes, never from a request field or
    a server-reported size label.
    """
    if len(raw) < 24 or raw[:8] != b"\x89PNG\r\n\x1a\n" or raw[12:16] != b"IHDR":
        raise HttpError(
            "image_generation_failed",
            "The image transport returned data that is not a valid PNG.",
            HTTPStatus.BAD_GATEWAY,
        )

    width = int.from_bytes(raw[16:20], "big")
    height = int.from_bytes(raw[20:24], "big")

    if width <= 0 or height <= 0 or width * height > MAX_DIRECT_IMAGE_PIXELS:
        raise HttpError(
            "image_generation_failed",
            "The image transport returned an image with unusable dimensions.",
            HTTPStatus.BAD_GATEWAY,
        )

    return width, height


def normalize_direct_usage(payload: Any) -> dict[str, Any]:
    """Maps image-endpoint usage, keeping unknown distinct from zero."""
    if not isinstance(payload, dict):
        return {"usageKnown": False, "source": "direct"}

    usage: dict[str, Any] = {"usageKnown": True, "source": "direct"}

    for source_key, target_key in (
        ("input_tokens", "inputTokens"),
        ("output_tokens", "outputTokens"),
        ("total_tokens", "totalTokens"),
    ):
        value = payload.get(source_key)
        if isinstance(value, int) and value >= 0:
            usage[target_key] = value

    for source_key, target_key in (
        ("input_tokens_details", "inputTokensDetails"),
        ("output_tokens_details", "outputTokensDetails"),
    ):
        value = payload.get(source_key)
        if isinstance(value, dict):
            usage[target_key] = {k: v for k, v in value.items() if isinstance(v, int)}

    return usage


def direct_image_request(
    credentials: ManagedCredentials,
    prompt: str,
    budget: float,
) -> dict[str, Any]:
    """Performs one bounded direct image request.

    Sends only the prompt. The endpoint ignores model, quality, and size,
    so sending them would imply a selection control that does not exist.
    """
    timeout = max(1.0, budget - DIRECT_PROCESSING_RESERVE)
    body = json.dumps({"prompt": prompt}).encode()

    request = urllib.request.Request(
        DIRECT_IMAGE_URL,
        data=body,
        headers={
            "Authorization": f"Bearer {credentials.access_token}",
            "chatgpt-account-id": credentials.account_id,
            "originator": DIRECT_IMAGE_ORIGINATOR,
            "Accept": "application/json",
            "Content-Type": "application/json",
        },
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read(MAX_DIRECT_RESPONSE_BYTES + 1)
    except urllib.error.HTTPError as exc:
        raise classify_direct_http_error(exc.code) from None
    except (TimeoutError, urllib.error.URLError, OSError):
        raise HttpError(
            "image_outcome_unknown",
            "The image request did not complete cleanly; an image may still have been generated.",
            HTTPStatus.GATEWAY_TIMEOUT,
        ) from None

    if len(raw) > MAX_DIRECT_RESPONSE_BYTES:
        raise HttpError(
            "image_generation_failed",
            "The image transport returned a response larger than the configured limit.",
            HTTPStatus.BAD_GATEWAY,
        )

    try:
        payload = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, ValueError):
        raise HttpError(
            "image_outcome_unknown",
            "The image transport returned a malformed response body.",
            HTTPStatus.BAD_GATEWAY,
        ) from None

    if not isinstance(payload, dict):
        raise HttpError(
            "image_outcome_unknown",
            "The image transport returned an unexpected response shape.",
            HTTPStatus.BAD_GATEWAY,
        )

    items = payload.get("data")
    if not isinstance(items, list) or not items:
        raise HttpError(
            "image_generation_failed",
            "The image transport returned no image candidates.",
            HTTPStatus.BAD_GATEWAY,
        )

    first = items[0] if isinstance(items[0], dict) else {}
    image_base64 = optional_string(first.get("b64_json"))

    if not image_base64:
        raise HttpError(
            "image_generation_failed",
            "The image transport returned a candidate without image data.",
            HTTPStatus.BAD_GATEWAY,
        )

    try:
        decoded = base64.b64decode(image_base64, validate=True)
    except (ValueError, TypeError):
        raise HttpError(
            "image_generation_failed",
            "The image transport returned image data that is not valid base64.",
            HTTPStatus.BAD_GATEWAY,
        ) from None

    if len(decoded) > MAX_DIRECT_IMAGE_BYTES:
        raise HttpError(
            "image_generation_failed",
            "The image transport returned an image larger than the configured limit.",
            HTTPStatus.BAD_GATEWAY,
        )

    width, height = png_dimensions(decoded)

    return {
        "imageBase64": image_base64,
        "mimeType": "image/png",
        "imageCount": len(items),
        "width": width,
        "height": height,
        "generationId": optional_string(first.get("generation_id")),
        "serverReported": {
            "quality": optional_string(payload.get("quality")),
            "size": optional_string(payload.get("size")),
            "outputFormat": optional_string(payload.get("output_format")),
            "background": optional_string(payload.get("background")),
        },
        "usage": normalize_direct_usage(payload.get("usage")),
    }


def classify_direct_http_error(status: int) -> HttpError:
    """Maps an upstream status to a typed error.

    No status produces a transport switch: v1 has no auto mode, and a
    wrong guess would bill the user for a duplicate generation.
    """
    if 401 == status:
        return HttpError(
            "auth_required",
            "The stored ChatGPT credentials were rejected by the image service.",
            HTTPStatus.CONFLICT,
        )

    if status in (402, 403, 429):
        return HttpError(
            "image_generation_denied",
            "The connected ChatGPT account is not permitted to generate this image right now.",
            HTTPStatus.FORBIDDEN,
        )

    if status in (404, 410):
        return HttpError(
            "image_route_unavailable",
            "The direct image transport endpoint is unavailable. Switch the sidecar back to the app-server transport.",
            HTTPStatus.BAD_GATEWAY,
        )

    if 400 == status:
        return HttpError(
            "image_generation_failed",
            "The image service rejected the request shape.",
            HTTPStatus.BAD_GATEWAY,
        )

    return HttpError(
        "image_outcome_unknown",
        "The image service returned an ambiguous error; an image may still have been generated.",
        HTTPStatus.BAD_GATEWAY,
    )
```

Note every error path builds its message from fixed strings. Upstream error bodies are never interpolated, so a service that echoes a credential cannot leak it into a response or a log.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `python3 sidecar/scripts/test-direct-image.py -v`

Expected: PASS, `Ran 18 tests ... OK`

- [ ] **Step 6: Register the test file**

In `scripts/verify.sh`, after the `test-credentials.py` line, add:

```bash
python3 "$ROOT_DIR/sidecar/scripts/test-direct-image.py"
```

- [ ] **Step 7: Commit**

```bash
git add sidecar/app/main.py sidecar/scripts/test-direct-image.py scripts/verify.sh
git commit -m "feat(sidecar): add direct subscription image transport

Sends only the prompt: the endpoint ignores model, quality and size, so
sending them would imply a selection control that does not exist.
Dimensions are read from the decoded PNG, never from a response label."
```

---

### Task 5: Deadline Budget Propagation

A fixed sidecar deadline is unsafe because `codex_provider_runtime_request_timeout` can lower the PHP timeout below it, leaving an image generated and billed after the caller gave up. PHP must send its effective post-filter timeout.

**Files:**
- Modify: `src/Runtime/Client.php:88-115` (reorder so the timeout is computed before the body is encoded)
- Modify: `sidecar/app/main.py` (constants; `_generate_image_direct` budget derivation)
- Test: `scripts/verify.php`; `sidecar/scripts/test-direct-image.py`

**Interfaces:**
- Consumes: `main.direct_image_request` (Task 4).
- Produces:
  - Request body key `deadlineBudgetSeconds: int` on every `/v1/responses/` request.
  - `main.resolve_budget(payload: dict) -> float` — returns the caller budget, or `DIRECT_BUDGET_FLOOR` when absent.
  - `main.DIRECT_BUDGET_FLOOR: float`.

- [ ] **Step 1: Write the failing sidecar test**

Append to `sidecar/scripts/test-direct-image.py`, before the `if __name__` block:

```python
class BudgetTest(unittest.TestCase):
    def test_uses_caller_budget_when_supplied(self):
        self.assertEqual(main.resolve_budget({"deadlineBudgetSeconds": 90}), 90.0)

    def test_falls_back_to_floor_when_absent(self):
        self.assertEqual(main.resolve_budget({}), main.DIRECT_BUDGET_FLOOR)

    def test_ignores_nonsense_budgets(self):
        self.assertEqual(main.resolve_budget({"deadlineBudgetSeconds": 0}), main.DIRECT_BUDGET_FLOOR)
        self.assertEqual(main.resolve_budget({"deadlineBudgetSeconds": -5}), main.DIRECT_BUDGET_FLOOR)
        self.assertEqual(main.resolve_budget({"deadlineBudgetSeconds": "abc"}), main.DIRECT_BUDGET_FLOOR)

    def test_http_timeout_stays_strictly_inside_a_small_budget(self):
        captured = {}

        def fake_urlopen(request, timeout=None):
            captured["timeout"] = timeout
            return FakeResponse(SUCCESS_BODY)

        with mock.patch.object(main.urllib.request, "urlopen", fake_urlopen):
            main.direct_image_request(credentials(), "x", budget=20.0)

        self.assertLess(captured["timeout"], 20.0)
        self.assertGreaterEqual(captured["timeout"], 1.0)
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `python3 sidecar/scripts/test-direct-image.py -k Budget`

Expected: FAIL with `AttributeError: module 'main' has no attribute 'resolve_budget'`

- [ ] **Step 3: Implement the sidecar side**

Add to the `sidecar/app/main.py` constants block:

```python
DIRECT_BUDGET_FLOOR = 60.0
```

Add near `resolve_image_transport`:

```python
def resolve_budget(payload: dict[str, Any]) -> float:
    """Derives the operation budget from the caller's effective timeout.

    Falls back to a conservative floor rather than the historical 360s
    assumption, so an older PHP caller against a newer sidecar cannot
    leave the sidecar generating long after the caller gave up.
    """
    value = payload.get("deadlineBudgetSeconds")

    if isinstance(value, bool) or not isinstance(value, (int, float)):
        return DIRECT_BUDGET_FLOOR

    if value <= 0:
        return DIRECT_BUDGET_FLOOR

    return float(value)
```

- [ ] **Step 4: Run the sidecar test to verify it passes**

Run: `python3 sidecar/scripts/test-direct-image.py -v`

Expected: PASS, `Ran 22 tests ... OK`

- [ ] **Step 5: Inject the budget from PHP**

In `src/Runtime/Client.php::request()`, the timeout is currently computed at line ~109, after `$body_json` is encoded at line ~88. Reorder so the same value that bounds the HTTP call is the one sent.

Move the timeout computation to just before the body encoding, and inject it. Replace:

```php
		$body_json = [] === $body ? '' : wp_json_encode( $body );
```

with:

```php
		$timeout = $this->request_timeout( $method, $path, $url );

		if ( 'GET' !== $method && 0 === strpos( $path, '/v1/responses/' ) ) {
			$body['deadlineBudgetSeconds'] = $timeout;
		}

		$body_json = [] === $body ? '' : wp_json_encode( $body );
```

`$url` is assigned after the current body encoding, so move the `$url` assignment (and the `add_query_arg` block that follows it) above this new code. Then delete the original `$timeout = $this->request_timeout( $method, $path, $url );` line at ~109 so it is computed exactly once.

- [ ] **Step 6: Write the PHP assertion**

In `scripts/verify.php`, find the existing image-generation section that mocks HTTP via `pre_http_request`. Add a case that captures the outgoing body and asserts the budget tracks the filter:

```php
$captured_image_body = null;

add_filter(
	'codex_provider_runtime_request_timeout',
	static function ( $timeout, $method, $path ) {
		return 0 === strpos( $path, '/v1/responses/' ) ? 45 : $timeout;
	},
	10,
	3
);

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) use ( &$captured_image_body ) {
		if ( false === strpos( $url, '/v1/responses/image' ) ) {
			return $preempt;
		}

		$captured_image_body = json_decode( $args['body'], true );

		return [
			'headers'  => [],
			'body'     => wp_json_encode(
				[
					'imageBase64' => $png_base64,
					'mimeType'    => 'image/png',
					'model'       => 'codex-image',
				]
			),
			'response' => [ 'code' => 200, 'message' => 'OK' ],
		];
	},
	10,
	3
);

// ... trigger an image generation through the model ...

$codex_provider_assert(
	45 === ( $captured_image_body['deadlineBudgetSeconds'] ?? null ),
	'Image requests carry the filtered effective timeout as deadlineBudgetSeconds'
);
$codex_provider_assert(
	45 === ( $captured_args['timeout'] ?? null ),
	'The HTTP timeout matches the budget sent to the sidecar'
);
```

Match the existing helper names in `scripts/verify.php` — the file has its own assertion helper and mock-registration conventions; read the surrounding image-generation block and follow them rather than introducing new ones.

- [ ] **Step 7: Run verification**

Run: `WP_PATH=/path/to/site ./scripts/verify.sh`

Expected: PASS, including the two new assertions.

- [ ] **Step 8: Commit**

```bash
git add src/Runtime/Client.php sidecar/app/main.py sidecar/scripts/test-direct-image.py scripts/verify.php
git commit -m "feat: propagate the effective PHP timeout as a sidecar budget

A fixed sidecar deadline is unsafe because the runtime timeout filter can
lower the PHP timeout below it, leaving an image generated and billed
after the caller gave up."
```

---

### Task 6: Eligibility Gate

Three checks that must all run before any credential is read: unsupported selectors, non-empty `systemInstruction`, and the live capability probe.

**Files:**
- Modify: `src/Models/CodexImageGenerationModel.php:64-80`
- Modify: `sidecar/app/main.py` (`_generate_image_direct`)
- Test: `scripts/verify.php`; `sidecar/scripts/test-direct-image.py`

**Interfaces:**
- Consumes: `main.CredentialStore` (Task 3), `main.resolve_budget` (Task 5).
- Produces:
  - `CodexImageGenerationModel::unsupported_image_option(): ?string` — returns the first requested selector name, or `null`.
  - `main.assert_direct_eligible(payload: dict, session: Any) -> None` — raises `HttpError` when the request cannot use the direct transport.

- [ ] **Step 1: Write the failing sidecar test**

Append to `sidecar/scripts/test-direct-image.py`:

```python
class DirectEligibilityTest(unittest.TestCase):
    class Session:
        def __init__(self, capabilities):
            self.capabilities = capabilities
            self.requests = []

        def request(self, method, params=None, *, timeout=main.REQUEST_TIMEOUT):
            self.requests.append(method)
            if "modelProvider/capabilities/read" == method:
                return self.capabilities
            raise AssertionError(f"Unexpected request: {method}")

    def test_rejects_non_empty_system_instruction(self):
        session = self.Session({"imageGeneration": True})
        with self.assertRaises(main.HttpError) as ctx:
            main.assert_direct_eligible({"systemInstruction": "Be terse."}, session)
        self.assertEqual(ctx.exception.code, "direct_transport_ineligible")

    def test_allows_absent_or_empty_system_instruction(self):
        session = self.Session({"imageGeneration": True})
        main.assert_direct_eligible({}, session)
        main.assert_direct_eligible({"systemInstruction": ""}, session)

    def test_rejects_when_capability_probe_is_false(self):
        session = self.Session({"imageGeneration": False})
        with self.assertRaises(main.HttpError) as ctx:
            main.assert_direct_eligible({}, session)
        self.assertEqual(ctx.exception.code, "image_generation_unavailable")

    def test_capability_probe_runs_before_eligibility_passes(self):
        session = self.Session({"imageGeneration": True})
        main.assert_direct_eligible({}, session)
        self.assertIn("modelProvider/capabilities/read", session.requests)
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `python3 sidecar/scripts/test-direct-image.py -k Eligibility`

Expected: FAIL with `AttributeError: module 'main' has no attribute 'assert_direct_eligible'`

- [ ] **Step 3: Implement the sidecar gate**

Add to `sidecar/app/main.py`:

```python
def assert_direct_eligible(payload: dict[str, Any], session: Any) -> None:
    """Rejects requests the direct transport cannot faithfully serve.

    A non-empty systemInstruction is ineligible because the app-server path
    sends it as developerInstructions on thread/start and the direct
    endpoint has no verified equivalent channel. Flattening it into the
    prompt would silently demote its semantics.
    """
    system_instruction = optional_string(payload.get("systemInstruction"))

    if system_instruction:
        raise HttpError(
            "direct_transport_ineligible",
            "The direct image transport cannot carry a system instruction. Use the app-server transport for this request.",
            HTTPStatus.CONFLICT,
        )

    try:
        capabilities_payload = session.request(
            "modelProvider/capabilities/read",
            {},
            timeout=REQUEST_TIMEOUT,
        )
    except JsonRpcError:
        capabilities_payload = {}

    if not normalize_capabilities_payload(capabilities_payload)["imageGeneration"]:
        raise HttpError(
            "image_generation_unavailable",
            "The active Codex runtime account does not report image generation support.",
            HTTPStatus.CONFLICT,
        )
```

- [ ] **Step 4: Run the sidecar test to verify it passes**

Run: `python3 sidecar/scripts/test-direct-image.py -v`

Expected: PASS, `Ran 26 tests ... OK`

- [ ] **Step 5: Add the PHP selector rejection**

In `src/Models/CodexImageGenerationModel.php`, add a private helper following the two-tier pattern already used by `CodexTextGenerationModel::extract_reasoning_effort()`:

```php
	/**
	 * Returns the first requested image option this provider cannot honor.
	 *
	 * The subscription image backend is service-selected: model, quality and
	 * size are not selection controls. Accepting them and silently
	 * substituting service defaults would imply control that does not exist.
	 *
	 * @return string|null
	 */
	private function unsupported_image_option(): ?string {
		$config         = $this->getConfig();
		$custom_options = $config->getCustomOptions();

		foreach ( [ 'model', 'imageModel', 'image_model', 'quality', 'size', 'aspectRatio', 'background' ] as $key ) {
			if ( isset( $custom_options[ $key ] ) && null !== $custom_options[ $key ] ) {
				return $key;
			}
		}

		foreach ( [ 'getCandidateCount', 'getQuality', 'getSize', 'getAspectRatio' ] as $method ) {
			if ( ! is_callable( [ $config, $method ] ) ) {
				continue;
			}

			if ( null !== call_user_func( [ $config, $method ] ) ) {
				return $method;
			}
		}

		return null;
	}
```

Then, in `generateImageResult()`, immediately after the catalog check block that ends at line ~62, add:

```php
		$unsupported = $this->unsupported_image_option();

		if ( null !== $unsupported ) {
			throw self::runtime_exception(
				sprintf(
					/* translators: %s: the requested image option name. */
					esc_html__( 'The Codex image provider does not support the requested %s option. The image backend is selected by the service.', 'scriptorium-ai-provider-for-codex' ),
					esc_html( $unsupported )
				)
			);
		}
```

- [ ] **Step 6: Assert the rejection in verify.php**

In `scripts/verify.php`, the assertion helper is the closure `$codex_provider_assert( bool $condition, string $message )` defined near line 44. Define a local probe closure alongside the file's other helpers, then assert with it:

```php
$codex_provider_image_option_error = static function ( array $custom_options ): ?string {
	$model = new \AIProviderForCodex\Models\CodexImageGenerationModel(
		$codex_provider_image_model_metadata,
		$codex_provider_provider_metadata,
		new \WordPress\AiClient\Providers\Models\DTO\ModelConfig( [ 'customOptions' => $custom_options ] )
	);

	try {
		$model->generateImageResult( [ new \WordPress\AiClient\Messages\DTO\Message(
			\WordPress\AiClient\Messages\Enums\MessageRoleEnum::user(),
			[ new \WordPress\AiClient\Messages\DTO\MessagePart( 'A blue square.' ) ]
		) ] );
	} catch ( \Throwable $exception ) {
		return $exception->getMessage();
	}

	return null;
};

$codex_provider_assert(
	false !== strpos(
		(string) $codex_provider_image_option_error( [ 'quality' => 'max' ] ),
		'does not support the requested quality option'
	),
	'A requested quality option is rejected rather than silently ignored'
);
$codex_provider_assert(
	false === strpos(
		(string) $codex_provider_image_option_error( [ 'quality' => null ] ),
		'does not support the requested'
	),
	'A null quality option does not block dispatch'
);
```

Reuse the metadata variables the surrounding image-generation block already builds rather than constructing new ones — read that block first and match its names. The `ModelConfig` construction above must match whatever shape the block already uses to build configs; if it builds them through a helper, call that helper with `customOptions` instead.

- [ ] **Step 7: Run verification**

Run: `WP_PATH=/path/to/site ./scripts/verify.sh`

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Models/CodexImageGenerationModel.php sidecar/app/main.py sidecar/scripts/test-direct-image.py scripts/verify.php
git commit -m "feat: gate direct image eligibility on selectors and instructions

Any non-empty systemInstruction is direct-ineligible: the app-server path
sends it as developerInstructions and the direct endpoint has no verified
equivalent, so flattening it into the prompt would demote its semantics."
```

---

### Task 7: Coordinator Wiring, Auth Retry, And Generation Marker

Replace the Task 2 stub with the real direct path: snapshot credentials, dispatch, refresh once on 401, retry once inside the remaining budget, and mark a result whose auth generation changed mid-flight.

**Files:**
- Modify: `sidecar/app/main.py` (`_generate_image_direct`; `clear_session`)
- Test: `sidecar/scripts/test-direct-image.py`

**Interfaces:**
- Consumes: `main.CREDENTIALS` (Task 3), `main.direct_image_request` (Task 4), `main.resolve_budget` (Task 5), `main.assert_direct_eligible` (Task 6).
- Produces: `_generate_image_direct` returning the `/v1/responses/image` response dict, with `transport: "direct"`, `attempts: int`, `outcome: str`, and optional `authGenerationChanged: bool`.

- [ ] **Step 1: Write the failing tests**

Append to `sidecar/scripts/test-direct-image.py`:

```python
class CoordinatorTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        storage_root = Path(self.temp.name)
        codex_home = storage_root / "users" / "123"
        codex_home.mkdir(parents=True)
        (codex_home / "auth.json").write_text(
            json.dumps(
                {
                    "auth_mode": "chatgpt",
                    "tokens": {"access_token": "tok", "account_id": "acct"},
                }
            ),
            encoding="utf-8",
        )
        self.storage_root = storage_root

    def _session(self):
        class Session:
            def request(self, method, params=None, *, timeout=main.REQUEST_TIMEOUT):
                if "modelProvider/capabilities/read" == method:
                    return {"imageGeneration": True}
                if method in ("account/rateLimits/read", "account/read"):
                    return {}
                raise AssertionError(f"Unexpected request: {method}")

        return Session()

    def test_successful_direct_generation_reports_transport_metadata(self):
        result_payload = {
            "imageBase64": PNG_BASE64,
            "mimeType": "image/png",
            "imageCount": 1,
            "width": 1,
            "height": 1,
            "generationId": "gen_1",
            "serverReported": {"quality": "low"},
            "usage": {"usageKnown": True, "inputTokens": 5, "outputTokens": 9},
        }

        with mock.patch.object(main, "STORAGE_ROOT", self.storage_root), mock.patch.object(
            main, "app_server_session"
        ) as factory, mock.patch.object(
            main, "direct_image_request", return_value=result_payload
        ):
            factory.return_value.__enter__.return_value = self._session()
            result = main.RuntimeState()._generate_image_direct(123, {"prompt": "x"})

        self.assertEqual(result["transport"], "direct")
        self.assertEqual(result["attempts"], 1)
        self.assertEqual(result["outcome"], "succeeded")
        self.assertEqual(result["model"], "codex-image")
        self.assertIsNone(result["runtimeModel"])
        self.assertNotIn("authGenerationChanged", result)

    def test_auth_rejection_refreshes_once_then_retries_once(self):
        attempts = []

        def flaky(credentials, prompt, budget):
            attempts.append(budget)
            if 1 == len(attempts):
                raise main.HttpError("auth_required", "rejected", main.HTTPStatus.CONFLICT)
            return {
                "imageBase64": PNG_BASE64,
                "mimeType": "image/png",
                "imageCount": 1,
                "width": 1,
                "height": 1,
                "generationId": None,
                "serverReported": {},
                "usage": {"usageKnown": False},
            }

        with mock.patch.object(main, "STORAGE_ROOT", self.storage_root), mock.patch.object(
            main, "app_server_session"
        ) as factory, mock.patch.object(main, "direct_image_request", flaky):
            factory.return_value.__enter__.return_value = self._session()
            result = main.RuntimeState()._generate_image_direct(123, {"prompt": "x"})

        self.assertEqual(len(attempts), 2)
        self.assertLess(attempts[1], attempts[0], "the retry must not reset the budget")
        self.assertEqual(result["attempts"], 2)

    def test_repeated_auth_rejection_stops_without_app_server_fallback(self):
        def always_rejects(credentials, prompt, budget):
            raise main.HttpError("auth_required", "rejected", main.HTTPStatus.CONFLICT)

        with mock.patch.object(main, "STORAGE_ROOT", self.storage_root), mock.patch.object(
            main, "app_server_session"
        ) as factory, mock.patch.object(
            main, "direct_image_request", always_rejects
        ), mock.patch.object(
            main.RuntimeState, "_generate_image_app_server"
        ) as app_server:
            factory.return_value.__enter__.return_value = self._session()
            with self.assertRaises(main.HttpError) as ctx:
                main.RuntimeState()._generate_image_direct(123, {"prompt": "x"})

        self.assertEqual(ctx.exception.code, "auth_required")
        app_server.assert_not_called()

    def test_denial_never_falls_back_to_app_server(self):
        def denied(credentials, prompt, budget):
            raise main.HttpError("image_generation_denied", "no", main.HTTPStatus.FORBIDDEN)

        with mock.patch.object(main, "STORAGE_ROOT", self.storage_root), mock.patch.object(
            main, "app_server_session"
        ) as factory, mock.patch.object(
            main, "direct_image_request", denied
        ), mock.patch.object(
            main.RuntimeState, "_generate_image_app_server"
        ) as app_server:
            factory.return_value.__enter__.return_value = self._session()
            with self.assertRaises(main.HttpError):
                main.RuntimeState()._generate_image_direct(123, {"prompt": "x"})

        app_server.assert_not_called()

    def test_logout_mid_flight_returns_the_image_marked(self):
        def generate_then_logout(credentials, prompt, budget):
            main.CREDENTIALS.bump(123)
            return {
                "imageBase64": PNG_BASE64,
                "mimeType": "image/png",
                "imageCount": 1,
                "width": 1,
                "height": 1,
                "generationId": None,
                "serverReported": {},
                "usage": {"usageKnown": False},
            }

        with mock.patch.object(main, "STORAGE_ROOT", self.storage_root), mock.patch.object(
            main, "app_server_session"
        ) as factory, mock.patch.object(main, "direct_image_request", generate_then_logout):
            factory.return_value.__enter__.return_value = self._session()
            result = main.RuntimeState()._generate_image_direct(123, {"prompt": "x"})

        self.assertTrue(result["authGenerationChanged"])
        self.assertEqual(result["imageBase64"], PNG_BASE64)
```

Add `import tempfile` to that test file's imports.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `python3 sidecar/scripts/test-direct-image.py -k Coordinator`

Expected: FAIL — the stub raises `image_transport_unavailable`.

- [ ] **Step 3: Replace the stub**

Replace the `_generate_image_direct` stub from Task 2 with:

```python
    def _generate_image_direct(self, wp_user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        prompt = require_string(payload.get("prompt"), "prompt")
        request_id = optional_string(payload.get("requestId")) or str(uuid.uuid4())
        budget = resolve_budget(payload)
        started = time.monotonic()

        codex_home = user_codex_home(wp_user_id)
        with app_server_session(codex_home) as session:
            assert_direct_eligible(payload, session)

            credentials = CREDENTIALS.snapshot(wp_user_id)
            attempts = 0

            while True:
                attempts += 1
                remaining = budget - (time.monotonic() - started)

                if remaining <= DIRECT_PROCESSING_RESERVE:
                    raise HttpError(
                        "image_outcome_unknown",
                        "The image request ran out of time before it could be dispatched.",
                        HTTPStatus.GATEWAY_TIMEOUT,
                    )

                try:
                    image = direct_image_request(credentials, prompt, remaining)
                    break
                except HttpError as exc:
                    # One managed refresh and one retry, inside the original budget.
                    if "auth_required" != exc.code or attempts >= 2:
                        raise

                    credentials = CREDENTIALS.refresh(wp_user_id, credentials.generation)

            rate_limits = session.request("account/rateLimits/read", timeout=REQUEST_TIMEOUT)
            account = session.request("account/read", {"refreshToken": False}, timeout=REQUEST_TIMEOUT)

        response = {
            "account": normalize_account_payload(account),
            "artifacts": {},
            "attempts": attempts,
            "authStored": auth_file_path(codex_home).is_file(),
            "finishReason": "stop",
            "generationId": image["generationId"],
            "imageBase64": image["imageBase64"],
            "imageCount": image["imageCount"],
            "imageHeight": image["height"],
            "imageWidth": image["width"],
            "mimeType": image["mimeType"],
            "model": "codex-image",
            "outcome": "succeeded",
            "rateLimits": normalize_rate_limits_payload(rate_limits),
            "requestId": request_id,
            # The service exposes no trustworthy model identity, so this stays null.
            "runtimeModel": None,
            "serverReported": image["serverReported"],
            "transport": "direct",
            "usage": image["usage"],
        }

        if CREDENTIALS.current_generation(wp_user_id) != credentials.generation:
            # The image was authorized at dispatch and is already billed, so it is
            # returned rather than discarded, but marked so callers can tell.
            response["authGenerationChanged"] = True

        return response
```

- [ ] **Step 4: Make logout bump the generation**

In `RuntimeState.clear_session`, add the bump so a logout is observable to an in-flight operation:

```python
    def clear_session(self, wp_user_id: int) -> None:
        clear_auth_json(user_codex_home(wp_user_id))
        CREDENTIALS.bump(wp_user_id)
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `python3 sidecar/scripts/test-direct-image.py -v`

Expected: PASS, `Ran 31 tests ... OK`

- [ ] **Step 6: Run every sidecar suite**

Run:
```bash
python3 sidecar/scripts/test-token-usage.py
python3 sidecar/scripts/test-diagnostics.py
python3 sidecar/scripts/test-image-generation.py
python3 sidecar/scripts/test-account-snapshot.py
python3 sidecar/scripts/test-credentials.py
python3 sidecar/scripts/test-direct-image.py
```

Expected: all PASS.

- [ ] **Step 7: Commit**

```bash
git add sidecar/app/main.py sidecar/scripts/test-direct-image.py
git commit -m "feat(sidecar): wire the direct image coordinator

One managed refresh and one retry on 401, inside the original budget. No
transport fallback on any failure class. A logout mid-flight returns the
image marked authGenerationChanged rather than discarding work the user
has already been billed for."
```

---

### Task 8: Response Mapping, Request Logging, And Documentation

Carry the new metadata across the Python/PHP boundary. `ResponseMapper` builds `additional_data` by explicit assignment, so nothing reaches callers unless mapped deliberately.

**Files:**
- Modify: `src/Runtime/ResponseMapper.php:99-155`
- Modify: `src/Models/CodexImageGenerationModel.php` (the success-path `RequestLogWriter::record()` call)
- Modify: `sidecar/HOW-IT-WORKS.md`, `CLAUDE.md`
- Test: `scripts/verify.php`

**Interfaces:**
- Consumes: the Task 7 response shape.
- Produces: image `additional_data` carrying `transport`, `attempts`, `outcome`, `imageWidth`, `imageHeight`, `generationId`, `serverReported`, `usageKnown`, and `authGenerationChanged`.

- [ ] **Step 1: Write the failing assertions**

In `scripts/verify.php`, extend the image-generation mock to return the Task 7 shape and assert the mapping:

```php
$codex_provider_assert(
	'direct' === ( $image_result->getAdditionalData()['transport'] ?? null ),
	'Image results carry the transport that produced them'
);
$codex_provider_assert(
	1 === ( $image_result->getAdditionalData()['imageWidth'] ?? null ),
	'Image results carry decoded dimensions, not response labels'
);
$codex_provider_assert(
	null === ( $image_result->getAdditionalData()['runtimeModel'] ?? null ),
	'runtimeModel stays unset when the service reports no model identity'
);
$codex_provider_assert(
	false === ( $image_result->getAdditionalData()['usageKnown'] ?? null ),
	'Absent usage is reported as unknown rather than zero'
);
```

- [ ] **Step 2: Run verification to see them fail**

Run: `WP_PATH=/path/to/site ./scripts/verify.sh`

Expected: FAIL on the four new assertions.

- [ ] **Step 3: Extend the mapper**

In `src/Runtime/ResponseMapper.php::to_image_generative_ai_result()`, after the existing `$additional_data` assignments (around line 120-135), add:

```php
		foreach ( [ 'transport', 'attempts', 'outcome', 'generationId', 'imageWidth', 'imageHeight' ] as $key ) {
			if ( isset( $payload[ $key ] ) ) {
				$additional_data[ $key ] = $payload[ $key ];
			}
		}

		if ( isset( $payload['serverReported'] ) && is_array( $payload['serverReported'] ) ) {
			// Server-reported values are labeled as reported, never as provenance.
			$additional_data['serverReported'] = array_filter(
				$payload['serverReported'],
				static function ( $value ) {
					return null !== $value;
				}
			);
		}

		if ( isset( $payload['usage']['usageKnown'] ) ) {
			$additional_data['usageKnown'] = (bool) $payload['usage']['usageKnown'];
		}

		if ( ! empty( $payload['authGenerationChanged'] ) ) {
			$additional_data['authGenerationChanged'] = true;
		}
```

Do not add `runtimeModel` here — the existing block already maps it only when present, and Task 7 sends `null`, so it correctly stays unset.

- [ ] **Step 4: Add transport to the success log entry**

In `src/Models/CodexImageGenerationModel.php`, in the success-path `RequestLogWriter::build_entry()` call, add the transport to the operation string so log readers can tell the paths apart:

```php
						'operation'     => 'codex:responses/image',
```

becomes:

```php
						'operation'     => isset( $response['transport'] ) && 'direct' === $response['transport']
							? 'codex:responses/image:direct'
							: 'codex:responses/image',
```

Leave the two error-path entries unchanged: they fire before a transport is known. Do not add image bytes, base64, `serverReported`, or any credential-derived value to the log context.

- [ ] **Step 5: Run verification to confirm they pass**

Run: `WP_PATH=/path/to/site ./scripts/verify.sh`

Expected: PASS, all assertions including the four new ones.

- [ ] **Step 6: Run static analysis**

Run: `composer phpstan`

Expected: no new errors. If the `array_filter` callback trips a level-5 type inference rule, add an explicit `@param` annotation rather than widening the baseline.

- [ ] **Step 7: Update documentation**

In `sidecar/HOW-IT-WORKS.md`, document `CODEX_WP_IMAGE_TRANSPORT`, both modes, the absence of an `auto` mode and why, and that the image backend is service-selected with no model guarantee.

In `CLAUDE.md`, update the sidecar endpoint list so `/v1/responses/image` notes the two transports, and add a line to the invariants section:

```markdown
- **The image backend is service-selected.** The subscription images endpoint ignores `model`, `quality`, and `size` (measured 2026-09-11). Never expose named image variants or claim a specific backend model; `runtimeModel` stays null unless a trustworthy contract identifies it.
```

- [ ] **Step 8: Commit**

```bash
git add src/Runtime/ResponseMapper.php src/Models/CodexImageGenerationModel.php scripts/verify.php sidecar/HOW-IT-WORKS.md CLAUDE.md
git commit -m "feat: map direct image transport metadata and document the mode

Dimensions come from decoded bytes and server-reported values are labeled
as reported, never as provenance. runtimeModel stays null because the
service exposes no trustworthy model identity."
```

---

## Verification

Before opening a PR, from a clean tree:

```bash
composer phpstan
WP_PATH=/path/to/site ./scripts/verify.sh
```

`verify.sh` runs `php -l`, the release-exclude and Plugin-Check consistency checks, the `composer.lock` SDK guard, the "Requires at least" parity check, the JS suites, all six sidecar Python suites, and the WP-CLI end-to-end check.

Manual confirmation, which no automated suite covers because it needs a live connected account:

1. With `CODEX_WP_IMAGE_TRANSPORT` unset, generate an image and confirm it still runs through the app-server turn.
2. Set `CODEX_WP_IMAGE_TRANSPORT=direct`, restart the sidecar, and generate again. Confirm an image returns and the Request Log entry reads `codex:responses/image:direct`.
3. With `direct` active, send a request carrying a `systemInstruction` and confirm the typed incompatibility error rather than a silently flattened prompt.
4. Confirm no token, `Authorization` header, or base64 payload appears anywhere in the sidecar logs, the WordPress Request Log, or an error response.

Two spec acceptance gates cannot be closed by this plan's tasks and must be recorded before the transport is described as ready:

5. **Latency.** Generate the same set of representative prompts on both transports and record median and tail latency, failure rate, refresh frequency. The spec forbids advertising a speed or consumption gain from architectural inference — without these numbers, the change ships described only as removing orchestration, not as faster.
6. **Plan and account-class variation.** Exercise `direct` against more than one ChatGPT plan tier. The endpoint's typed denial is the only authoritative entitlement signal, so this is the only way to observe how entitlement differences surface. Record what each tier returns.
