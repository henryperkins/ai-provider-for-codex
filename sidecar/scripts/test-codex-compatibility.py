#!/usr/bin/env python3
"""Exercise the real sidecar transport against a named, unauthenticated Codex CLI.

Run: CODEX_BIN=/path/to/codex python3 sidecar/scripts/test-codex-compatibility.py --version 0.162.1
No login or generation is performed. Existing user credentials are never loaded.
"""

from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "app"))

import main  # noqa: E402


def check(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)
    print(f"OK: {message}")


def run(expected_version: str) -> None:
    binary = os.environ.get("CODEX_BIN") or shutil.which("codex")
    if not binary:
        raise RuntimeError("Install the pinned Codex CLI or set CODEX_BIN to its executable.")
    main.CODEX_BIN = binary

    # Keep only operating-system essentials. In particular, do not inherit API
    # keys, auth tokens, provider overrides, or the developer's configuration.
    allowed_environment = {"PATH", "PATHEXT", "SYSTEMROOT", "WINDIR", "COMSPEC", "TEMP", "TMP", "TMPDIR"}
    clean_environment = {key: value for key, value in os.environ.items() if key.upper() in allowed_environment}

    with tempfile.TemporaryDirectory(prefix="codex-provider-compat-") as directory:
        root = Path(directory)
        codex_home = root / "user"
        codex_home.mkdir()
        # Even --version/schema generation loads CODEX_HOME/.env during Codex
        # startup. Pin its home before running any command, not just sessions.
        clean_environment["CODEX_HOME"] = str(codex_home)
        clean_environment["HOME"] = str(codex_home)
        with mock.patch.dict(os.environ, clean_environment, clear=True):
            version = subprocess.run(
                [binary, "--version"], capture_output=True, text=True, check=True, timeout=10
            ).stdout.strip()
            check(version == f"codex-cli {expected_version}", f"Codex version is {expected_version}")

            schema_root = root / "schema"
            subprocess.run(
                [binary, "app-server", "generate-json-schema", "--out", str(schema_root)],
                capture_output=True, text=True, check=True, timeout=30,
            )
            login_schema = json.loads((schema_root / "v2" / "LoginAccountParams.json").read_text(encoding="utf-8"))
            check(
                any("chatgptDeviceCode" in variant["properties"]["type"].get("enum", []) for variant in login_schema["oneOf"]),
                "device-code login remains in the generated protocol (no login attempted)",
            )
            account_schema = json.loads((schema_root / "v2" / "GetAccountParams.json").read_text(encoding="utf-8"))
            check(
                account_schema.get("properties", {}).get("refreshToken", {}).get("type") == "boolean",
                "account/read accepts the refreshToken boolean used for managed-auth refresh",
            )

            session = main.JsonRpcSession(codex_home)
            try:
                session.start(initialize_timeout=15)
                account = session.request("account/read", {"refreshToken": False}, timeout=15)
                check(account.get("account") is None, "real app-server starts with no connected account")

                capabilities = session.request("modelProvider/capabilities/read", {}, timeout=15)
                check(
                    isinstance(capabilities.get("imageGeneration"), bool),
                    "capability probe returns the imageGeneration boolean consumed by the sidecar",
                )
                check(
                    main.normalize_capabilities_payload(capabilities)["imageGeneration"] is capabilities["imageGeneration"],
                    "sidecar preserves the advertised image capability",
                )
                for image_generation in (False, True):
                    kind = "image" if image_generation else "text"
                    thread = session.request("thread/start", main.generation_thread_params(
                        image_generation=image_generation,
                    ), timeout=15)
                    check(thread.get("approvalPolicy") == "never", f"{kind} threads never wait for approval")
                    check(thread.get("sandbox", {}).get("type") == "readOnly", f"{kind} threads use a read-only sandbox")
                    check(thread["thread"].get("environments") == [], f"{kind} threads get no host environment")
                    check(main.generation_policy_mismatch(thread) is None,
                          f"sidecar accepts the {kind} thread policy echo")
                    features = main.thread_feature_states(session, thread["thread"]["id"], timeout=15)
                    # apps and plugins come from the app-server launch flags,
                    # the rest from the thread policy; both must reach the thread.
                    # The names are listed here, not read from main, so that
                    # the smoke test checks the sidecar's own list too.
                    for name in ("shell_tool", "stable_environment_tools", "view_image", "multi_agent", "multi_agent_v2",
                                 "apps", "plugins"):
                        check(features.get(name) is False, f"{kind} thread disables the real {name} feature")
                    check(features.get("image_generation") is image_generation,
                          f"{kind} thread {'enables' if image_generation else 'disables'} native image generation")
                check(not (codex_home / "auth.json").exists(), "smoke test creates no authentication credentials")
            finally:
                session.close()

            # The sidecar diagnostics run the same probe through their own
            # throwaway app-server under a separate storage root.
            with mock.patch.object(main, "STORAGE_ROOT", root / "storage"):
                rows = {row["id"]: row for row in main.run_diagnostics()["checks"]}
            check(rows.get("app_server", {}).get("status") == "pass", "diagnostics app-server handshake passes")
            check(rows.get("generation_policy", {}).get("status") == "pass",
                  "diagnostics confirm the generation policy on the real app-server")
            expected_version_status = "pass" if expected_version == main.QUALIFIED_CODEX_VERSION else "warn"
            check(rows.get("codex_version", {}).get("status") == expected_version_status,
                  f"diagnostics report {expected_version} as {expected_version_status} against the qualified "
                  f"{main.QUALIFIED_CODEX_VERSION}")
            check(not (root / "storage" / "_diagnostics").exists(), "diagnostics remove their throwaway Codex home")

            # A second process with the same isolated home catches lifecycle and
            # stale-process failures without depending on an authenticated user.
            restarted = main.JsonRpcSession(codex_home)
            try:
                restarted.start(initialize_timeout=15)
                account = restarted.request("account/read", {"refreshToken": False}, timeout=15)
                check(account.get("account") is None, "ephemeral app-server restarts cleanly")
            finally:
                restarted.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--version", required=True, help="Exact expected codex-cli version")
    args = parser.parse_args()
    run(args.version)
