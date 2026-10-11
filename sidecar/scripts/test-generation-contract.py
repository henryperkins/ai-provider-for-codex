#!/usr/bin/env python3
"""Regression tests for generation results, using Codex 0.162.1 wire shapes.

Run: python3 sidecar/scripts/test-generation-contract.py
No Codex process, credentials, provider refresh, or generation is performed.
"""

from __future__ import annotations

import io
import json
import sys
import tempfile
import unittest
from contextlib import contextmanager
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "app"))
import main  # noqa: E402


PNG = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII="


def terminal(status="completed", items=(), error=None):
    return {"method": "turn/completed", "params": {
        "threadId": "thread_test",
        "turn": {"id": "turn_test", "items": list(items), "status": status, "error": error},
    }}


def text_item(text="Final text"):
    return {"type": "agentMessage", "id": "text_test", "text": text}


def image_item(result=PNG, failure=None, status="completed", item_id="image_test"):
    return {"type": "imageGeneration", "id": item_id, "status": status,
            "revisedPrompt": None, "result": result, "failure": failure}


def completed_item(item, turn_id="turn_test"):
    return {"method": "item/completed", "params": {
        "threadId": "thread_test", "turnId": turn_id, "item": item,
    }}


class FixtureSession:
    """Only the external app-server boundary is replaced; runtime mapping is real."""

    def __init__(self, notifications, capabilities=None):
        self.notifications = list(notifications)
        self.capabilities = {"imageGeneration": True} if capabilities is None else capabilities
        self.requests = []

    def request(self, method, params=None, *, timeout=None):
        self.requests.append((method, params))
        if method == "modelProvider/capabilities/read":
            if isinstance(self.capabilities, Exception):
                raise self.capabilities
            return self.capabilities
        if method == "thread/start":
            if params.get("ephemeral") is not True:
                raise AssertionError("Generation must keep prompts in an ephemeral thread")
            return {"thread": {"id": "thread_test"}}
        if method == "turn/start":
            return {"turn": {"id": "turn_test"}}
        if method == "account/read":
            return {"account": {"type": "chatgpt", "email": "fixture@example.test", "planType": "fixture"}}
        if method == "account/rateLimits/read":
            return {"rateLimits": {}}
        if method == "model/list":
            return {"data": [{"id": "fixture", "model": "fixture", "displayName": "Fixture", "isDefault": True}]}
        if method == "thread/read":
            if params.get("includeTurns"):
                raise main.JsonRpcError("ephemeral threads do not support includeTurns", code=-32600)
            return {"thread": {"id": "thread_test", "turns": []}}
        raise AssertionError(f"Unexpected app-server request: {method}")

    def wait_for_notification(self, predicate, *, timeout=None):
        for index, notification in enumerate(self.notifications):
            if predicate(notification):
                return self.notifications.pop(index)
        raise TimeoutError("No matching notification in the protocol fixture")


class GenerationContractTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.storage = Path(directory.name)
        user_home = self.storage / "users" / "123"
        user_home.mkdir(parents=True)
        (user_home / "auth.json").write_text("{}", encoding="utf-8")
        patcher = mock.patch.object(main, "STORAGE_ROOT", self.storage)
        patcher.start()
        self.addCleanup(patcher.stop)

    @contextmanager
    def session(self, notifications, capabilities=None):
        fixture = FixtureSession(notifications, capabilities)

        @contextmanager
        def open_session(codex_home):
            self.assertEqual(codex_home, self.storage / "users" / "123")
            yield fixture

        with mock.patch.object(main, "app_server_session", open_session):
            yield fixture

    def generate(self, kind, notifications, capabilities=None, payload=None):
        with self.session(notifications, capabilities) as fixture:
            try:
                if kind == "text":
                    result = main.RuntimeState().generate_text(123, {"input": "Fixture prompt", **(payload or {})})
                else:
                    result = main.RuntimeState().generate_image(123, {"prompt": "Fixture image"})
            except Exception as error:
                result = error
        return result, fixture.requests

    def test_failed_text_turn_never_returns_partial_success(self):
        result, _ = self.generate("text", [completed_item(text_item("partial")), terminal("failed")])
        self.assertIsInstance(result, main.HttpError)
        self.assertEqual(result.code, "generation_failed")
        self.assertEqual(result.data["turnStatus"], "failed")

    def test_interrupted_text_turn_never_returns_partial_success(self):
        result, _ = self.generate("text", [completed_item(text_item("partial")), terminal("interrupted")])
        self.assertIsInstance(result, main.HttpError)
        self.assertEqual(result.code, "generation_interrupted")

    def test_failed_image_turn_never_returns_partial_success(self):
        result, _ = self.generate("image", [completed_item(image_item()), terminal("failed")])
        self.assertIsInstance(result, main.HttpError)
        self.assertEqual(result.code, "generation_failed")

    def test_interrupted_image_turn_never_returns_partial_success(self):
        result, _ = self.generate("image", [completed_item(image_item()), terminal("interrupted")])
        self.assertIsInstance(result, main.HttpError)
        self.assertEqual(result.code, "generation_interrupted")

    def test_completed_notification_requires_completed_status(self):
        for status in (None, "inProgress", "unknown", {}, []):
            with self.subTest(status=status):
                result, _ = self.generate("text", [completed_item(text_item()), terminal(status)])
                self.assertIsInstance(result, main.HttpError)
                self.assertEqual(result.code, "generation_failed")

    def test_explicit_terminal_error_preserves_its_message(self):
        result, _ = self.generate("text", [completed_item(text_item()), terminal(error={
            "message": "Fixture backend failed", "codexErrorInfo": "internalServerError", "additionalDetails": None,
        })])
        self.assertIsInstance(result, main.HttpError)
        self.assertEqual(str(result), "Fixture backend failed")

    def test_text_recovers_only_items_actually_in_terminal_payload(self):
        result, requests = self.generate("text", [terminal(items=[text_item("Terminal final text")])])
        self.assertIsInstance(result, dict)
        self.assertEqual(result["outputText"], "Terminal final text")
        self.assertNotIn("thread/read", [method for method, _ in requests])

    def test_image_recovers_only_items_actually_in_terminal_payload(self):
        result, requests = self.generate("image", [terminal(items=[image_item()])])
        self.assertIsInstance(result, dict)
        self.assertEqual(result["imageBase64"], PNG)
        self.assertEqual(result["imageCount"], 1)
        self.assertNotIn("thread/read", [method for method, _ in requests])

    def test_missing_text_fails_without_requesting_ephemeral_history(self):
        result, requests = self.generate("text", [terminal()])
        self.assertIsInstance(result, main.HttpError)
        self.assertEqual(result.code, "text_generation_failed")
        self.assertNotIn("thread/read", [method for method, _ in requests])

    def test_whitespace_only_text_is_not_successful_output(self):
        for notifications in (
            [completed_item(text_item(" \n\t ")), terminal()],
            [{"method": "item/agentMessage/delta", "params": {"turnId": "turn_test", "delta": " \n\t "}}, terminal()],
            [terminal(items=[text_item(" \n\t ")])],
        ):
            with self.subTest(notifications=notifications):
                result, requests = self.generate("text", notifications)
                self.assertIsInstance(result, main.HttpError)
                self.assertEqual(result.code, "text_generation_failed")
                self.assertNotIn("thread/read", [method for method, _ in requests])

    def test_missing_image_fails_without_requesting_ephemeral_history(self):
        result, requests = self.generate("image", [terminal()])
        self.assertIsInstance(result, main.HttpError)
        self.assertEqual(result.code, "image_generation_failed")
        self.assertNotIn("thread/read", [method for method, _ in requests])

    def test_streamed_text_still_succeeds_on_completed_turn(self):
        result, _ = self.generate("text", [{"method": "item/agentMessage/delta", "params": {
            "threadId": "thread_test", "turnId": "turn_test", "itemId": "text_test", "delta": "Streamed final text",
        }}, terminal()])
        self.assertIsInstance(result, dict)
        self.assertEqual(result["outputText"], "Streamed final text")

    def test_terminal_image_does_not_duplicate_a_completed_notification(self):
        item = image_item()
        result, _ = self.generate("image", [completed_item(item), terminal(items=[item])])
        self.assertIsInstance(result, dict)
        self.assertEqual(result["imageCount"], 1)

    def test_image_from_another_turn_is_not_returned(self):
        result, _ = self.generate("image", [completed_item(image_item(), "other_turn"), terminal()])
        self.assertIsInstance(result, main.HttpError)
        self.assertEqual(result.code, "image_generation_failed")

    def test_quota_failure_keeps_limit_and_nullable_reset(self):
        for resets_at in (1791800000, None):
            with self.subTest(resets_at=resets_at):
                failure = {"type": "usageLimitExceeded", "limitId": "image_gen", "resetsAt": resets_at}
                result, requests = self.generate("image", [completed_item(image_item("", failure, "failed")), terminal()])
                self.assertIsInstance(result, main.HttpError)
                self.assertEqual(result.code, "image_generation_usage_limit_exceeded")
                self.assertEqual(result.status, 429)
                self.assertEqual(result.data, {"type": "usageLimitExceeded", "limitId": "image_gen", "resetsAt": resets_at})
                self.assertNotIn("thread/read", [method for method, _ in requests])

    def test_successful_image_retry_overrides_an_earlier_quota_failure(self):
        failure = {"type": "usageLimitExceeded", "limitId": "image_gen", "resetsAt": None}
        result, _ = self.generate("image", [completed_item(image_item("", failure, "failed", "failed_image")),
                                            completed_item(image_item()), terminal()])
        self.assertIsInstance(result, dict)
        self.assertEqual(result["imageBase64"], PNG)

    def test_terminal_only_quota_failure_is_not_lost(self):
        failure = {"type": "usageLimitExceeded", "limitId": "image_gen", "resetsAt": 1791800000}
        result, _ = self.generate("image", [terminal(items=[image_item("", failure, "failed")])])
        self.assertIsInstance(result, main.HttpError)
        self.assertEqual(result.status, 429)

    def test_duplicate_quota_notification_and_terminal_item_preserve_one_error(self):
        failure = {"type": "usageLimitExceeded", "limitId": "image_gen", "resetsAt": 1791800000}
        item = image_item("", failure, "failed")
        result, requests = self.generate("image", [completed_item(item), terminal(items=[item])])
        self.assertIsInstance(result, main.HttpError)
        self.assertEqual(result.code, "image_generation_usage_limit_exceeded")
        self.assertEqual(result.data, failure)
        self.assertEqual([method for method, _ in requests], ["modelProvider/capabilities/read", "thread/start", "turn/start"])

    def test_untyped_failed_image_does_not_expose_raw_backend_fields(self):
        item = image_item("", {"type": "unknown", "secret": "must-not-escape"}, "failed")
        result, _ = self.generate("image", [completed_item(item), terminal()])
        self.assertIsInstance(result, main.HttpError)
        self.assertEqual(result.code, "image_generation_failed")
        self.assertNotIn("must-not-escape", str(result))

    def test_failed_image_item_cannot_become_a_success_from_partial_result(self):
        result, _ = self.generate("image", [completed_item(image_item(PNG, status="failed")), terminal()])
        self.assertIsInstance(result, main.HttpError)
        self.assertEqual(result.code, "image_generation_failed")

    def test_schema_less_json_accepts_valid_json_values(self):
        for text, expected in (("{\"answer\":42}", {"answer": 42}), ("[1,2]", [1, 2]), ("7", 7), ("null", None)):
            with self.subTest(text=text):
                result, requests = self.generate("text", [completed_item(text_item(text)), terminal()], payload={
                    "responseFormat": {"type": "json_object"}, "systemInstruction": "Fixture instruction",
                })
                self.assertIsInstance(result, dict)
                self.assertEqual(result["structuredOutput"], expected)
                thread = next(params for method, params in requests if method == "thread/start")
                turn = next(params for method, params in requests if method == "turn/start")
                self.assertIn("Fixture instruction", thread["developerInstructions"])
                self.assertIn("JSON", thread["developerInstructions"])
                self.assertNotIn("outputSchema", turn)

    def test_schema_less_json_rejects_plaintext_and_code_fences(self):
        for text in ("Plain text is not JSON", "```json\n{}\n```", "NaN", "Infinity"):
            with self.subTest(text=text):
                result, requests = self.generate("text", [completed_item(text_item(text)), terminal()], payload={
                    "responseFormat": {"type": "json_object"},
                })
                self.assertIsInstance(result, main.HttpError)
                self.assertEqual(result.code, "invalid_json_output")
                self.assertEqual(result.status, 502)
                self.assertNotIn(text, str(result))
                self.assertNotIn("account/read", [method for method, _ in requests])

    def test_schema_based_json_preserves_the_output_schema(self):
        schema = {"type": "object", "properties": {"answer": {"type": "integer"}}}
        result, requests = self.generate("text", [completed_item(text_item('{"answer":42}')), terminal()], payload={
            "responseFormat": {"type": "json_schema", "schema": schema},
        })
        self.assertIsInstance(result, dict)
        turn = next(params for method, params in requests if method == "turn/start")
        self.assertEqual(turn["outputSchema"], schema)
        self.assertEqual(result["structuredOutput"], {"answer": 42})

    def test_valid_json_with_large_numbers_keeps_the_http_envelope_valid(self):
        for response_format in ({"type": "json_object"}, {"type": "json_schema", "schema": {"type": "number"}}):
            for text in ("1e400", '{"value":1e400}', "[1e400]"):
                with self.subTest(response_format=response_format, text=text):
                    result, _ = self.generate("text", [terminal(items=[text_item(text)])], payload={
                        "responseFormat": response_format,
                    })
                    self.assertIsInstance(result, dict)
                    self.assertEqual(result["outputText"], text)
                    # JSON numbers can exceed Python floats; preserve the text
                    # without publishing a lossy non-finite parsed value.
                    self.assertIsNone(result["structuredOutput"])
                    handler = object.__new__(main.RuntimeHandler)
                    handler.wfile = io.BytesIO()
                    handler.send_response = lambda status: None
                    handler.send_header = lambda key, value: None
                    handler.end_headers = lambda: None
                    handler._json_response(result)

                    def reject_constant(value):
                        raise ValueError("The HTTP envelope must be strict JSON")

                    wire = json.loads(handler.wfile.getvalue(), parse_constant=reject_constant)
                    self.assertEqual(wire["outputText"], text)

    def test_image_capability_rpc_failure_preserves_original_cause(self):
        error = main.JsonRpcError("Capability provider failed", code=-32001)
        result, requests = self.generate("image", [], error)
        self.assertIsInstance(result, main.JsonRpcError)
        self.assertEqual(str(result), "Capability provider failed")
        self.assertEqual(result.code, -32001)
        self.assertEqual([method for method, _ in requests], ["modelProvider/capabilities/read"])

    def test_snapshot_capability_rpc_failure_preserves_original_cause(self):
        with self.session([], main.JsonRpcError("Snapshot capability failed", code=-32002)):
            try:
                result = main.RuntimeState().account_snapshot(123)
            except Exception as error:
                result = error
        self.assertIsInstance(result, main.JsonRpcError)
        self.assertEqual(str(result), "Snapshot capability failed")
        self.assertEqual(result.code, -32002)

    def test_quota_details_survive_the_real_http_dispatch_boundary(self):
        failure = {"type": "usageLimitExceeded", "limitId": "image_gen", "resetsAt": 1791800000}
        with self.session([completed_item(image_item("", failure, "failed")), terminal()]):
            payload, status = self.dispatch("/v1/responses/image", {"wpUserId": 123, "prompt": "Fixture image"})
        self.assertEqual(status, 429)
        self.assertEqual(payload["error"]["code"], "image_generation_usage_limit_exceeded")
        self.assertEqual(payload["error"]["data"], failure)

    def test_capability_rpc_code_survives_the_real_http_dispatch_boundary(self):
        with self.session([], main.JsonRpcError("Capability provider failed", code=-32001, data={"secret": "not-public"})):
            payload, status = self.dispatch("/v1/responses/image", {"wpUserId": 123, "prompt": "Fixture image"})
        self.assertEqual(status, 502)
        self.assertEqual(payload["error"]["message"], "Capability provider failed")
        self.assertEqual(payload["error"]["data"], {"rpcCode": -32001})
        self.assertNotIn("not-public", json.dumps(payload))

    def dispatch(self, path, body):
        handler = object.__new__(main.RuntimeHandler)
        raw = json.dumps(body).encode()
        handler.command = "POST"
        handler.path = path
        handler.client_address = ("127.0.0.1", 1)
        handler.headers = {"authorization": "Bearer fixture-token", "content-length": str(len(raw))}
        handler.rfile = io.BytesIO(raw)
        responses = []
        handler._json_response = lambda payload, status=200: responses.append((payload, status))
        with mock.patch.object(main, "AUTH_TOKEN", "fixture-token"), mock.patch.object(main, "STATE", main.RuntimeState()):
            handler._dispatch()
        return responses[0]


class UsageContractTest(unittest.TestCase):
    def test_single_turn_usage_counts_all_model_responses(self):
        self.assertEqual(main.extract_turn_token_usage({
            "total": {"inputTokens": 100, "outputTokens": 50, "reasoningOutputTokens": 30},
            "last": {"inputTokens": 10, "outputTokens": 5, "reasoningOutputTokens": 3},
        }), {"inputTokens": 100, "outputTokens": 50, "reasoningOutputTokens": 30})

    def test_total_bucket_falls_back_to_last_when_missing_or_malformed(self):
        for total in (None, "invalid", []):
            with self.subTest(total=total):
                self.assertEqual(main.extract_turn_token_usage({
                    "total": total, "last": {"inputTokens": 10, "outputTokens": 5, "reasoningOutputTokens": 3},
                }), {"inputTokens": 10, "outputTokens": 5, "reasoningOutputTokens": 3})


class HandshakeContractTest(unittest.TestCase):
    def test_initialize_uses_declared_fields_and_sends_initialized_before_requests(self):
        with tempfile.TemporaryDirectory() as directory:
            session = main.JsonRpcSession(Path(directory))
            with mock.patch.object(main.subprocess, "Popen") as spawn, mock.patch.object(main.threading, "Thread"), mock.patch.object(session, "request") as request:
                spawn.return_value.stdin = io.StringIO()
                session.start()
            initialize = request.call_args.args[1]
            self.assertNotIn("protocolVersion", initialize)
            messages = [json.loads(line) for line in spawn.return_value.stdin.getvalue().splitlines()]
            self.assertEqual([message["method"] for message in messages], ["initialized"])
            self.assertNotIn("id", messages[0])


if __name__ == "__main__":
    unittest.main()
