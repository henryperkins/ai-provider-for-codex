#!/usr/bin/env python3
"""Exercise the real child-process boundary with an inert app-server fixture.

No Codex process, credentials, login, or billable generation is used.
"""

from __future__ import annotations

import contextlib
import io
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
            "HTTP_PROXY": "http://proxy.example.test:3128",
            "ALL_PROXY": "socks5://proxy.example.test:1080",
            "no_proxy": "127.0.0.1,localhost",
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
            # Proxy settings are operator network configuration: Codex honors
            # them by default and proxy-only hosts cannot reach chatgpt.com
            # without them. Lowercase spellings pass through as written.
            "HTTPS_PROXY": "https://fixture-credential@proxy.example.test",
            "HTTP_PROXY": "http://proxy.example.test:3128",
            "ALL_PROXY": "socks5://proxy.example.test:1080",
            "no_proxy": "127.0.0.1,localhost",
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

    def test_login_start_failures_terminate_the_real_child(self):
        # start_login owns the app-server until its watcher thread takes over.
        # A child that never answers initialize, or a failed device-code
        # request, must not leave that child running with the user's CODEX_HOME.
        login_fixture = self.root / "login-error-fixture.py"
        login_fixture.write_text(
            "import json, sys\n"
            "for line in sys.stdin:\n"
            "    message = json.loads(line)\n"
            "    if 'id' not in message:\n"
            "        continue\n"
            "    if message.get('method') == 'initialize':\n"
            "        print(json.dumps({'id': message['id'], 'result': {}}), flush=True)\n"
            "    else:\n"
            "        print(json.dumps({'id': message['id'], 'error': {'code': -32000, 'message': 'login unavailable'}}), flush=True)\n",
            encoding="utf-8",
        )
        scenarios = {
            "initialize timeout": ([sys.executable, "-c", "import time; time.sleep(30)"], TimeoutError, 0.3),
            "device-code request error": ([sys.executable, str(login_fixture)], main.JsonRpcError, 10),
        }
        for name, (argv, expected, request_timeout) in scenarios.items():
            with self.subTest(name):
                processes = []

                def launch(_command, **kwargs):
                    process = self.real_popen(argv, **kwargs)
                    processes.append(process)
                    return process

                state = main.RuntimeState()
                try:
                    with mock.patch.object(main.subprocess, "Popen", side_effect=launch), \
                        mock.patch.object(main, "REQUEST_TIMEOUT", request_timeout):
                        with self.assertRaises(expected):
                            state.start_login(123)
                    self.assertEqual(len(processes), 1)
                    self.assertIsNotNone(processes[0].poll(), f"{name} left the login child running")
                    self.assertEqual(state._login_sessions, {})
                finally:
                    for process in processes:
                        if process.poll() is None:
                            process.kill()
                        process.wait(timeout=5)
                        for stream in (process.stdout, process.stderr):
                            if stream is not None:
                                stream.close()

    def test_launch_failure_reports_codex_stderr(self):
        # Codex explains a refused launch (for example an unknown `--disable`
        # feature name) only on stderr and exits before answering initialize.
        # The session must surface a short tail of it instead of just the code.
        script = (
            "import sys\n"
            "for index in range(1, 9):\n"
            "    print(f'line-{index} ' + 'x' * 300, file=sys.stderr)\n"
            "print('Error: Unknown feature flag: apps', file=sys.stderr)\n"
            "sys.exit(1)\n"
        )
        processes = []

        def launch(_command, **kwargs):
            process = self.real_popen([sys.executable, "-c", script], **kwargs)
            processes.append(process)
            return process

        session = main.JsonRpcSession(self.home)
        journal = io.StringIO()
        try:
            with mock.patch.object(main.subprocess, "Popen", side_effect=launch), \
                contextlib.redirect_stderr(journal):
                with self.assertRaises(RuntimeError) as raised:
                    session.start(initialize_timeout=5)
                # The journal line is written by the reader thread after the
                # waiter wakes; join it while stderr is still redirected.
                if session._reader is not None:
                    session._reader.join(timeout=5)
        finally:
            session.close()
            for thread in (session._reader, session._stderr_reader):
                if thread is not None:
                    thread.join(timeout=5)
            for process in processes:
                if process.stdout is not None:
                    process.stdout.close()
        message = str(raised.exception)
        self.assertIn("exited unexpectedly with code 1", message)
        self.assertIn("Codex reported:", message)
        self.assertIn("Unknown feature flag: apps", message)
        # Only a short, bounded tail is kept: the oldest lines drop out and
        # each retained line is truncated.
        self.assertNotIn("line-1 ", message)
        self.assertIn("line-8", message)
        self.assertLess(len(message), 1400)
        # The same message reaches the service journal once.
        self.assertEqual(journal.getvalue().count("Unknown feature flag: apps"), 1)

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
