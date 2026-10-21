#!/usr/bin/env python3
"""Probe evidence regressions, without Codex, Docker, sockets, or credentials."""

from __future__ import annotations

import importlib.util
import io
import json
import sys
import unittest
from pathlib import Path
from unittest import mock

SCRIPTS = Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPTS.parent / "app"))
spec = importlib.util.spec_from_file_location("tool_surface_probe", SCRIPTS / "offline-tool-surface-probe/probe.py")
probe = importlib.util.module_from_spec(spec)
# The model catalog is an external input to the real Docker probe, not to these
# handler/checker regressions. No external files are required by this suite.
with mock.patch.object(Path, "read_text", return_value="{}"):
    spec.loader.exec_module(probe)


class ProbeEvidenceTest(unittest.TestCase):
    def setUp(self):
        patcher = mock.patch.dict(probe.STATE, {"scenario": "text-direct-tool", "requests": [], "port": 0})
        patcher.start()
        self.addCleanup(patcher.stop)

    def post(self, body):
        payload = json.dumps(body).encode()
        # Exercise the real HTTP handler against in-memory streams. Its normal
        # SSE response must still be sent after an observation error.
        handler = probe.MockBackend.__new__(probe.MockBackend)
        handler.command = "POST"
        handler.path = "/backend-api/codex/responses"
        handler.request_version = "HTTP/1.1"
        handler.requestline = f"POST {handler.path} HTTP/1.1"
        handler.headers = {"content-length": str(len(payload)), "authorization": "Bearer fixture-not-recorded"}
        handler.rfile = io.BytesIO(payload)
        handler.wfile = io.BytesIO()
        handler.do_POST()
        self.assertEqual(len(probe.STATE["requests"]), 1)
        entry = probe.STATE["requests"][0]
        self.assertIs(handler.entry, entry)
        self.assertEqual(entry["status"], 200)
        self.assertIn(b"response.completed", handler.wfile.getvalue())
        self.assertNotIn("fixture-not-recorded", json.dumps(entry))
        self.assertNotIn("fixture-prompt-not-recorded", json.dumps(entry))
        return entry

    def report(self, entry):
        return {
            "scenario": "text-direct-tool",
            "outcome": {"ok": True},
            "rpc_observations": [
                {"method": "thread/start", "approvalPolicy": "never", "sandboxType": "readOnly", "environments": []},
                {"method": "turn/completed", "turnStatus": "completed"},
            ],
            "responses_calls": 1,
            "models_requested": [probe.DIRECT_MODEL],
            "tools_offered": ["request_user_input"],
            "code_mode_nested_tools": [],
            "forbidden_traffic": [],
            "backend_requests": [entry],
        }

    def test_valid_model_request_retains_observations_and_passes(self):
        entry = self.post({
            "model": probe.DIRECT_MODEL,
            "tools": [{"type": "function", "name": "request_user_input"}],
            "input": [{"type": "message", "content": "fixture-prompt-not-recorded"}],
        })
        self.assertIsNone(entry.get("model_request_error"))
        self.assertEqual(entry["model_request"]["tool_names"], ["request_user_input"])
        self.assertEqual(probe.check_scenario(self.report(entry)), [])

    def test_malformed_tool_specs_are_recorded_without_losing_the_response(self):
        cases = {
            "not a tool object": [None],
            "missing name and type": [{}],
            "malformed namespace": [{"type": "namespace", "name": "functions", "tools": [None]}],
            "noniterable namespace": [{"type": "namespace", "name": "functions", "tools": 1}],
            "nontext nested description": [{"type": "namespace", "name": "functions", "tools": [
                {"name": "exec", "description": 1},
            ]}],
        }
        for name, tools in cases.items():
            for nested in (False, True):
                with self.subTest(name=name, additional_tools=nested):
                    probe.STATE["requests"] = []
                    body = {"model": probe.DIRECT_MODEL, "input": []}
                    if nested:
                        body["input"] = [{"type": "additional_tools", "tools": tools}]
                    else:
                        body["tools"] = tools
                    entry = self.post(body)
                    self.assertRegex(entry["model_request_error"], r"^(ValueError|TypeError): ")
                    observation = entry["model_request"]
                    self.assertEqual(observation["model"], probe.DIRECT_MODEL)
                    retained_tools = observation["additional_tools"][0]["tools"] if nested else observation["tools"]
                    self.assertEqual(retained_tools, tools)
                    failures = probe.check_scenario(self.report(entry))
                    self.assertEqual(len(failures), 1)
                    self.assertIn(entry["model_request_error"], failures[0])


if __name__ == "__main__":
    unittest.main()
