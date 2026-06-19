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

    def test_run_diagnostics_skips_app_server_when_cli_fails(self):
        passing = {"id": "x", "label": "X", "status": "pass", "detail": ""}
        cli_failing = {"id": "codex_cli", "label": "Codex CLI", "status": "fail", "detail": "missing"}
        with mock.patch.object(main, "_check_python_version", return_value=passing), \
            mock.patch.object(main, "_check_codex_cli", return_value=cli_failing), \
            mock.patch.object(main, "_check_storage_root", return_value=passing), \
            mock.patch.object(main, "_check_app_server") as app_server:
            result = main.run_diagnostics()
        app_server.assert_not_called()
        self.assertFalse(result["ok"])
        self.assertNotIn("app_server", [check["id"] for check in result["checks"]])

    def test_app_server_removes_diagnostics_home(self):
        with tempfile.TemporaryDirectory() as tmp:
            storage = Path(tmp) / "store"
            home = storage / "_diagnostics"
            fake = mock.Mock()
            fake.start.side_effect = lambda *a, **k: home.mkdir(parents=True, exist_ok=True)
            with mock.patch.object(main, "STORAGE_ROOT", storage), \
                mock.patch.object(main, "JsonRpcSession", return_value=fake):
                check = main._check_app_server()
            self.assertEqual(check["status"], "pass")
            self.assertFalse(home.exists())
            fake.close.assert_called_once()


if __name__ == "__main__":
    unittest.main()
