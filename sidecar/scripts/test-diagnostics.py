import io
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "app"))

import main  # noqa: E402


class ServerIdentityTest(unittest.TestCase):
    def test_returns_service_and_version_only(self):
        self.assertEqual(
            main._server_identity(),
            {"service": "codex-wp-sidecar", "version": "2.1.4"},
        )


from unittest import mock  # noqa: E402


class StartTimeoutTest(unittest.TestCase):
    def test_start_forwards_initialize_timeout(self):
        session = main.JsonRpcSession(Path("/tmp/codex-diag-test"))
        with mock.patch.object(session, "request") as request, mock.patch.object(
            main.subprocess, "Popen"
        ), mock.patch.object(main.threading, "Thread"):
            main.subprocess.Popen.return_value.stdin = io.StringIO()
            session.start(initialize_timeout=7.5)
        self.assertEqual(request.call_args.args[0], "initialize")
        self.assertEqual(request.call_args.kwargs["timeout"], 7.5)


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
            rows = main._check_app_server()
        self.assertEqual([row["id"] for row in rows], ["app_server"])
        self.assertEqual(rows[0]["status"], "fail")
        fake.close.assert_called_once()
        # No policy row without a handshake: one failure row per root cause.
        fake.request.assert_not_called()

    def test_run_diagnostics_aggregates_ok(self):
        passing = {"id": "x", "label": "X", "status": "pass", "detail": ""}
        failing = {"id": "y", "label": "Y", "status": "fail", "detail": "nope"}
        with mock.patch.object(main, "_check_python_version", return_value=passing), \
            mock.patch.object(main, "_check_codex_cli", return_value=passing), \
            mock.patch.object(main, "_check_storage_root", return_value=passing), \
            mock.patch.object(main, "_check_user_config_overrides", return_value=passing), \
            mock.patch.object(main, "_check_app_server", return_value=[failing]):
            result = main.run_diagnostics()
        self.assertFalse(result["ok"])
        self.assertEqual(result["service"], "codex-wp-sidecar")
        self.assertEqual(len(result["checks"]), 6)

    def test_run_diagnostics_skips_app_server_when_cli_fails(self):
        passing = {"id": "x", "label": "X", "status": "pass", "detail": ""}
        cli_failing = {"id": "codex_cli", "label": "Codex CLI", "status": "fail", "detail": "missing"}
        overrides = {"id": "user_config_overrides", "label": "No per-user Codex configuration", "status": "pass",
                     "detail": ""}
        with mock.patch.object(main, "_check_python_version", return_value=passing), \
            mock.patch.object(main, "_check_codex_cli", return_value=cli_failing), \
            mock.patch.object(main, "_check_storage_root", return_value=passing), \
            mock.patch.object(main, "_check_user_config_overrides", return_value=overrides), \
            mock.patch.object(main, "_check_app_server") as app_server:
            result = main.run_diagnostics()
        app_server.assert_not_called()
        self.assertFalse(result["ok"])
        ids = [check["id"] for check in result["checks"]]
        self.assertNotIn("app_server", ids)
        self.assertNotIn("codex_version", ids)
        self.assertNotIn("generation_policy", ids)
        # The configuration scan reads only the storage root, not the CLI.
        self.assertIn("user_config_overrides", ids)

    def test_app_server_removes_diagnostics_home(self):
        with tempfile.TemporaryDirectory() as tmp:
            storage = Path(tmp) / "store"
            home = storage / "_diagnostics"
            fake = FakeDiagnosticSession()
            fake.start = mock.Mock(side_effect=lambda *a, **k: home.mkdir(parents=True, exist_ok=True))
            fake.close = mock.Mock()
            with mock.patch.object(main, "STORAGE_ROOT", storage), \
                mock.patch.object(main, "JsonRpcSession", return_value=fake):
                rows = main._check_app_server()
            self.assertEqual(
                [(row["id"], row["status"]) for row in rows],
                [("app_server", "pass"), ("generation_policy", "pass")],
            )
            self.assertFalse(home.exists())
            fake.close.assert_called_once()

    def test_run_diagnostics_reports_version_and_policy_rows(self):
        python = {"id": "python_version", "label": "Python runtime", "status": "pass", "detail": "Python 3.11.9"}
        storage = {"id": "storage_root", "label": "Storage root writable", "status": "pass", "detail": ""}
        overrides = {"id": "user_config_overrides", "label": "No per-user Codex configuration", "status": "pass",
                     "detail": ""}
        app_rows = [
            {"id": "app_server", "label": "app-server handshake", "status": "pass", "detail": ""},
            {"id": "generation_policy", "label": "Generation policy enforced", "status": "pass", "detail": ""},
        ]
        for installed, ok in (("0.162.1", True), ("0.163.0", False)):
            with self.subTest(installed=installed):
                cli = {"id": "codex_cli", "label": "Codex CLI", "status": "pass",
                       "detail": f"codex-cli {installed} at /usr/local/bin/codex"}
                with mock.patch.object(main, "_check_python_version", return_value=python), \
                    mock.patch.object(main, "_check_codex_cli", return_value=cli), \
                    mock.patch.object(main, "_check_storage_root", return_value=storage), \
                    mock.patch.object(main, "_check_user_config_overrides", return_value=overrides), \
                    mock.patch.object(main, "_check_app_server", return_value=app_rows):
                    result = main.run_diagnostics()
                self.assertEqual(
                    [check["id"] for check in result["checks"]],
                    ["python_version", "codex_cli", "storage_root", "user_config_overrides", "codex_version",
                     "app_server", "generation_policy"],
                )
                # A version warning alone is enough to report the runtime as not ok.
                self.assertIs(result["ok"], ok)


def qualified_thread(**overrides):
    result = {
        "thread": {"id": "thread_diag", "environments": []},
        "approvalPolicy": "never",
        "sandbox": {"type": "readOnly"},
    }
    result.update(overrides)
    return result


class FakeDiagnosticSession:
    """Answers thread/start and a two-page experimentalFeature/list like Codex 0.162.1."""

    def __init__(self, thread_started=None, features=None, *, endless=False):
        self.thread_started = qualified_thread() if thread_started is None else thread_started
        self.features = {name: False for name in main.GENERATION_DISABLED_FEATURES} if features is None else features
        self.endless = endless
        self.requests = []

    def request(self, method, params=None, *, timeout=None):
        self.requests.append((method, params, timeout))
        if method == "thread/start":
            return self.thread_started
        if method == "experimentalFeature/list":
            items = [{"name": name, "enabled": enabled} for name, enabled in self.features.items()]
            if self.endless:
                return {"data": items[:1], "nextCursor": "again"}
            if params["cursor"] is None:
                return {"data": items[:3], "nextCursor": "page-2"}
            return {"data": items[3:], "nextCursor": None}
        raise AssertionError(f"unexpected request {method}")


class UserConfigOverridesCheckTest(unittest.TestCase):
    def scan(self, layout):
        with tempfile.TemporaryDirectory() as tmp:
            storage = Path(tmp) / "store"
            for relative, content in layout.items():
                path = storage / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                if content is None:
                    path.mkdir()
                else:
                    path.write_text(content, encoding="utf-8")
            with mock.patch.object(main, "STORAGE_ROOT", storage):
                row = main._check_user_config_overrides()
            # Paths in the detail are absolute; make them comparable.
            row["detail"] = row["detail"].replace(str(storage), "<root>")
            return row

    def test_override_file_list_is_pinned(self):
        self.assertEqual(
            main.USER_CONFIG_OVERRIDE_FILES,
            ("config.toml", "hooks.json", ".env", "AGENTS.md", "AGENTS.override.md"),
        )

    def test_passes_before_any_user_connects(self):
        row = self.scan({})
        self.assertEqual((row["id"], row["status"]), ("user_config_overrides", "pass"))

    def test_passes_with_only_auth_and_runtime_state(self):
        row = self.scan({
            "users/7/auth.json": "{}",
            "users/7/state_5.sqlite": "",
            "users/7/models_cache.json": "{}",
            "users/7/skills/.system/SKILL.md": "system skill",
            "users/7/sessions": None,
            "_diagnostics/config.toml": "probe homes are not user homes",
        })
        self.assertEqual(row["status"], "pass")

    def test_warns_and_lists_each_override_file(self):
        row = self.scan({
            "users/3/config.toml": "[mcp_servers.example]\ncommand = \"example\"\n",
            "users/3/auth.json": "{}",
            "users/8/hooks.json": "{}",
            "users/9/.env": "EXAMPLE=1\n",
            "users/9/AGENTS.md": "instructions",
        })
        self.assertEqual(row["status"], "warn")
        self.assertEqual(
            row["detail"],
            "<root>/users/3/config.toml, <root>/users/8/hooks.json, <root>/users/9/.env, <root>/users/9/AGENTS.md",
        )

    def test_lists_at_most_five_paths(self):
        row = self.scan({f"users/{user}/config.toml": "" for user in range(1, 8)})
        self.assertEqual(row["status"], "warn")
        self.assertEqual(row["detail"].count("config.toml"), 5)
        self.assertTrue(row["detail"].endswith(" and 2 more"))

    def test_flags_a_dangling_symlink(self):
        with tempfile.TemporaryDirectory() as tmp:
            storage = Path(tmp) / "store"
            home = storage / "users" / "4"
            home.mkdir(parents=True)
            (home / "config.toml").symlink_to(Path(tmp) / "missing.toml")
            with mock.patch.object(main, "STORAGE_ROOT", storage):
                row = main._check_user_config_overrides()
        self.assertEqual(row["status"], "warn")
        self.assertIn("config.toml", row["detail"])

    def test_scan_errors_warn_instead_of_raising(self):
        with mock.patch.object(main.Path, "iterdir", side_effect=PermissionError("denied")), \
            tempfile.TemporaryDirectory() as tmp:
            (Path(tmp) / "users").mkdir()
            with mock.patch.object(main, "STORAGE_ROOT", Path(tmp)):
                row = main._check_user_config_overrides()
        self.assertEqual(row["status"], "warn")
        self.assertIn("could not scan", row["detail"])


class CodexVersionCheckTest(unittest.TestCase):
    def version_row(self, detail):
        return main._check_codex_version({"id": "codex_cli", "label": "Codex CLI", "status": "pass", "detail": detail})

    def test_qualified_release_passes(self):
        self.assertEqual(main.QUALIFIED_CODEX_VERSION, "0.162.1")
        row = self.version_row("codex-cli 0.162.1 at /usr/local/bin/codex")
        self.assertEqual((row["id"], row["label"], row["status"]), ("codex_version", "Codex version qualified", "pass"))

    def test_other_release_warns(self):
        row = self.version_row("codex-cli 0.163.0 at /usr/local/bin/codex")
        self.assertEqual(row["status"], "warn")
        self.assertEqual(row["detail"], "0.163.0 installed; this sidecar release is qualified against 0.162.1")

    def test_prerelease_of_the_qualified_version_warns(self):
        self.assertEqual(self.version_row("codex-cli 0.162.1-alpha.2 at /opt/codex")["status"], "warn")

    def test_unrecognized_version_output_warns(self):
        row = self.version_row("codex 1.2.3 at /usr/local/bin/codex")
        self.assertEqual(row["status"], "warn")
        self.assertTrue(row["detail"].startswith("An unrecognized version installed;"))


class GenerationPolicyCheckTest(unittest.TestCase):
    def test_disabled_feature_list_is_pinned(self):
        self.assertEqual(
            main.GENERATION_DISABLED_FEATURES,
            ("shell_tool", "stable_environment_tools", "view_image", "multi_agent", "multi_agent_v2", "apps", "plugins"),
        )

    def test_passes_when_codex_confirms_policy_and_features(self):
        session = FakeDiagnosticSession()
        row = main._check_generation_policy(session)
        self.assertEqual(
            (row["id"], row["label"], row["status"]),
            ("generation_policy", "Generation policy enforced", "pass"),
        )
        method, params, _timeout = session.requests[0]
        self.assertEqual((method, params), ("thread/start", main.generation_thread_params()))
        listing = session.requests[1:]
        self.assertEqual([request[0] for request in listing], ["experimentalFeature/list"] * 2)
        self.assertEqual([request[1]["cursor"] for request in listing], [None, "page-2"])
        self.assertTrue(all(request[1]["threadId"] == "thread_diag" for request in listing))

    def test_probe_requests_stay_within_the_policy_budget(self):
        session = FakeDiagnosticSession()
        main._check_generation_policy(session)
        self.assertTrue(all(0 < request[2] <= main.DIAGNOSTIC_POLICY_TIMEOUT for request in session.requests))

    def test_fails_when_apps_is_enabled(self):
        features = {name: False for name in main.GENERATION_DISABLED_FEATURES}
        features["apps"] = True
        row = main._check_generation_policy(FakeDiagnosticSession(features=features))
        self.assertEqual(row["status"], "fail")
        self.assertIn("apps", row["detail"])

    def test_fails_when_a_feature_is_not_reported(self):
        features = {name: False for name in main.GENERATION_DISABLED_FEATURES if name != "plugins"}
        row = main._check_generation_policy(FakeDiagnosticSession(features=features))
        self.assertEqual(row["status"], "fail")
        self.assertIn("plugins", row["detail"])

    def test_fails_without_listing_features_when_the_echo_differs(self):
        host_thread = qualified_thread()
        host_thread["thread"] = {"id": "thread_diag", "environments": [{"environmentId": "local", "cwd": "/"}]}
        cases = {
            "approvalPolicy": qualified_thread(approvalPolicy="on-request"),
            "sandbox": qualified_thread(sandbox={"type": "workspaceWrite"}),
            "environments": host_thread,
        }
        for name, thread_started in cases.items():
            with self.subTest(name=name):
                session = FakeDiagnosticSession(thread_started=thread_started)
                row = main._check_generation_policy(session)
                self.assertEqual(row["status"], "fail")
                self.assertIn(name, row["detail"])
                self.assertEqual([request[0] for request in session.requests], ["thread/start"])

    def test_fails_when_feature_listing_never_ends(self):
        row = main._check_generation_policy(FakeDiagnosticSession(endless=True))
        self.assertEqual(row["status"], "fail")
        self.assertIn("20 pages", row["detail"])

    def test_fails_when_the_probe_times_out(self):
        session = mock.Mock()
        session.request.side_effect = TimeoutError("thread/start timed out after 4.0s")
        row = main._check_generation_policy(session)
        self.assertEqual(row["status"], "fail")
        self.assertIn("timed out", row["detail"])

    def test_diagnostic_limits_fit_the_wordpress_request_timeout(self):
        # close() can wait two seconds after terminate and two after kill.
        # Include both waits, not just the successful-probe budgets, inside
        # Runtime\Client::DIAGNOSTICS_TIMEOUT (30 s). PHP regressions pin it.
        self.assertLess(5 + main.DIAGNOSTIC_HANDSHAKE_TIMEOUT + main.DIAGNOSTIC_POLICY_TIMEOUT + 4, 30)


if __name__ == "__main__":
    unittest.main()
