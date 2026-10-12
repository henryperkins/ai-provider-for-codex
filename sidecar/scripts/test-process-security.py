#!/usr/bin/env python3
"""Exercise the real child-process boundary with an inert app-server fixture.

No Codex process, credentials, login, or billable generation is used.
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "app"))
import main  # noqa: E402


class ProcessSecurityTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory(prefix="codex-process-security-")
        self.addCleanup(directory.cleanup)
        self.root = Path(directory.name)
        self.home = self.root / "users" / "123"
        self.environment_report = self.root / "child-environment.json"
        fixture = self.root / "app-server-fixture.py"
        fixture.write_text(
            "import json, os, sys\n"
            "from pathlib import Path\n"
            # Tests clear the parent environment, so __ARGV__ cannot collide with
            # an inherited variable; they pop it before comparing environments.
            f"Path({str(self.environment_report)!r}).write_text(json.dumps({{**os.environ, '__ARGV__': sys.argv[1:]}}))\n"
            "if '--version' in sys.argv:\n"
            "    print('codex-cli fixture')\n"
            "    sys.exit(0)\n"
            "for line in sys.stdin:\n"
            "    message = json.loads(line)\n"
            "    if 'id' in message:\n"
            "        print(json.dumps({'id': message['id'], 'result': {}}), flush=True)\n",
            encoding="utf-8",
        )
        real_popen = subprocess.Popen
        self.real_popen = real_popen

        def launch_fixture(command, **kwargs):
            # Replace the external executable, keeping real environment, pipes,
            # reader thread, initialization and process cleanup behavior.
            return real_popen([sys.executable, str(fixture), *command[1:]], **kwargs)

        for patcher in (
            mock.patch.object(main.subprocess, "Popen", side_effect=launch_fixture),
            mock.patch.object(main, "STORAGE_ROOT", self.root),
        ):
            patcher.start()
            self.addCleanup(patcher.stop)

    def start_session(self, environment):
        with mock.patch.dict(os.environ, environment, clear=True):
            session = main.JsonRpcSession(self.home)
            try:
                session.start(initialize_timeout=5)
            finally:
                process = session._proc
                reader = session._reader
                session.close()
                if reader is not None:
                    reader.join(timeout=5)
                if process is not None and process.stdout is not None:
                    process.stdout.close()
        return json.loads(self.environment_report.read_text(encoding="utf-8"))

    def test_app_server_child_receives_only_runtime_paths_and_scoped_homes(self):
        environment = self.start_session({
            "PATH": "/fixture/bin",
            "PATHEXT": ".EXE;.CMD",
            "SYSTEMROOT": "C:\\Windows",
            "WINDIR": "C:\\Windows",
            "SSL_CERT_FILE": "/etc/ssl/certs/custom-ca.pem",
            "SSL_CERT_DIR": "/etc/ssl/certs",
            "CODEX_CA_CERTIFICATE": "/etc/ssl/certs/codex-ca.pem",
            "HOME": "/shared/service-home",
            "USERPROFILE": "C:\\Users\\service",
            "CODEX_HOME": "/shared/codex-home",
            "CODEX_WP_BEARER_TOKEN": "fixture-bearer",
            "OPENAI_API_KEY": "fixture-api-key",
            "DATABASE_PASSWORD": "fixture-password",
            "UNCLASSIFIED_CREDENTIAL": "fixture-credential",
            "HTTPS_PROXY": "https://fixture-credential@proxy.example.test",
            "CODEX_WP_STORAGE_ROOT": "/shared/storage",
            "TEMP": "/shared/temp",
        })
        # The real child receives the account-backed feature opt-outs.
        self.assertEqual(
            environment.pop("__ARGV__"),
            ["app-server", "--disable", "apps", "--disable", "plugins"],
        )
        # CPython may add LC_CTYPE while coercing the fixture's empty locale.
        environment.pop("LC_CTYPE", None)
        self.assertEqual(environment, {
            "PATH": "/fixture/bin",
            "PATHEXT": ".EXE;.CMD",
            "SYSTEMROOT": "C:\\Windows",
            "WINDIR": "C:\\Windows",
            "SSL_CERT_FILE": "/etc/ssl/certs/custom-ca.pem",
            "SSL_CERT_DIR": "/etc/ssl/certs",
            "CODEX_CA_CERTIFICATE": "/etc/ssl/certs/codex-ca.pem",
            "HOME": str(self.home),
            "USERPROFILE": str(self.home),
            "CODEX_HOME": str(self.home),
        })

    def test_app_server_starts_without_optional_parent_path_variables(self):
        environment = self.start_session({"CODEX_WP_BEARER_TOKEN": "fixture-bearer"})
        environment.pop("__ARGV__")
        environment.pop("LC_CTYPE", None)
        self.assertEqual(environment, {
            "HOME": str(self.home),
            "USERPROFILE": str(self.home),
            "CODEX_HOME": str(self.home),
        })

    def test_version_diagnostic_also_excludes_parent_secrets_and_shared_homes(self):
        with mock.patch.dict(os.environ, {
            "PATH": "/fixture/bin",
            "HOME": "/shared/service-home",
            "CODEX_WP_BEARER_TOKEN": "fixture-bearer",
            "UNCLASSIFIED_CREDENTIAL": "fixture-credential",
        }, clear=True):
            result = main._check_codex_cli()
        self.assertEqual(result["status"], "pass")
        environment = json.loads(self.environment_report.read_text(encoding="utf-8"))
        self.assertEqual(environment.pop("__ARGV__"), ["--version"])
        environment.pop("LC_CTYPE", None)
        self.assertEqual(environment, {
            "PATH": "/fixture/bin",
            "HOME": str(self.root / "_diagnostics"),
            "USERPROFILE": str(self.root / "_diagnostics"),
            "CODEX_HOME": str(self.root / "_diagnostics"),
        })

    def test_initialize_timeout_terminates_the_real_child(self):
        # An inert real child never answers initialize. A failed session must
        # not leave it running after the context manager raises the timeout.
        session = main.JsonRpcSession(self.home)
        processes = []

        def launch_unresponsive_child(_command, **kwargs):
            process = self.real_popen([sys.executable, "-c", "import time; time.sleep(30)"], **kwargs)
            processes.append(process)
            return process

        try:
            with mock.patch.object(main.subprocess, "Popen", side_effect=launch_unresponsive_child), \
                mock.patch.object(main, "JsonRpcSession", return_value=session), \
                mock.patch.object(main, "REQUEST_TIMEOUT", 0.1):
                with self.assertRaises(TimeoutError):
                    with main.app_server_session(self.home):
                        self.fail("An unanswered initialize must not enter the session")
            self.assertEqual(len(processes), 1)
            self.assertIsNotNone(processes[0].poll(), "Timed-out initialization left a child running")
        finally:
            # Keep the regression test hygienic even before the fix is applied.
            session.close()
            if session._reader is not None:
                session._reader.join(timeout=5)
            for process in processes:
                if process.stdout is not None:
                    process.stdout.close()


if __name__ == "__main__":
    unittest.main()
