#!/usr/bin/env python3
"""Standalone unit tests for sidecar image-generation helpers.

Run: python3 sidecar/scripts/test-image-generation.py
"""

from __future__ import annotations

import sys
import tempfile
import unittest
from contextlib import contextmanager
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "app"))

import main  # noqa: E402


PNG_BASE64 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII="


class FakeSession:
    def __init__(self, responses, notifications=()):
        self.responses = responses
        self.notifications = list(notifications)
        self.requests = []

    def request(self, method, params=None, *, timeout=main.REQUEST_TIMEOUT):
        self.requests.append((method, params or {}, timeout))
        if method not in self.responses:
            raise AssertionError(f"Unexpected request: {method}")
        response = self.responses[method]
        return response(method, params or {}) if callable(response) else response

    def wait_for_notification(self, predicate, *, timeout=None):
        for index, notification in enumerate(self.notifications):
            if predicate(notification):
                return self.notifications.pop(index)
        raise TimeoutError("No matching fake notification.")


def stored_auth_temp_root():
    temp = tempfile.TemporaryDirectory()
    storage_root = Path(temp.name)
    codex_home = storage_root / "users" / "123"
    codex_home.mkdir(parents=True)
    (codex_home / "auth.json").write_text("{}", encoding="utf-8")
    return temp, storage_root


class CapabilitiesTest(unittest.TestCase):
    def test_normalizes_missing_capabilities_to_false(self):
        self.assertEqual(
            main.normalize_capabilities_payload(None),
            {"imageGeneration": False, "namespaceTools": False, "webSearch": False},
        )

    def test_normalizes_capability_booleans(self):
        self.assertEqual(
            main.normalize_capabilities_payload(
                {"imageGeneration": True, "namespaceTools": 1, "webSearch": ""}
            ),
            {"imageGeneration": True, "namespaceTools": True, "webSearch": False},
        )


class ImageItemExtractionTest(unittest.TestCase):
    def test_accepts_completed_item_with_result_even_when_status_is_generating(self):
        item = main.extract_image_item(
            {
                "method": "item/completed",
                "params": {
                    "turnId": "turn_123",
                    "item": {
                        "type": "imageGeneration",
                        "status": "generating",
                        "result": PNG_BASE64,
                        "revisedPrompt": "A tiny blue square.",
                        "savedPath": "/home/dev/.codex/generated_images/session/call.png",
                    },
                },
            }
        )

        self.assertEqual(item["imageBase64"], PNG_BASE64)
        self.assertEqual(item["mimeType"], "image/png")
        self.assertEqual(item["revisedPrompt"], "A tiny blue square.")
        self.assertEqual(
            item["artifacts"],
            {"savedPath": "/home/dev/.codex/generated_images/session/call.png"},
        )

    def test_ignores_image_item_without_result(self):
        self.assertIsNone(
            main.extract_image_item(
                {
                    "method": "item/completed",
                    "params": {
                        "turnId": "turn_123",
                        "item": {
                            "type": "imageGeneration",
                            "status": "completed",
                        },
                    },
                }
            )
        )

    def test_ignores_non_image_items(self):
        self.assertIsNone(
            main.extract_image_item(
                {
                    "method": "item/completed",
                    "params": {
                        "turnId": "turn_123",
                        "item": {"type": "agentMessage", "text": "not an image"},
                    },
                }
            )
        )


class GenerateImageTest(unittest.TestCase):
    def test_text_generation_passes_image_inputs_to_app_server(self):
        temp, storage_root = stored_auth_temp_root()
        self.addCleanup(temp.cleanup)
        fake = FakeSession(
            {
                "thread/start": {"thread": {"id": "thread_text"}},
                "turn/start": {"turn": {"id": "turn_text"}},
                "account/rateLimits/read": {"rateLimits": {"remaining": 5}},
                "account/read": {
                    "account": {
                        "type": "chatgpt",
                        "email": "vision@example.com",
                        "planType": "plus",
                    }
                },
            },
            [
                {
                    "method": "item/completed",
                    "params": {
                        "turnId": "turn_text",
                        "item": {
                            "type": "agentMessage",
                            "text": "A tiny transparent image.",
                        },
                    },
                },
                {
                    "method": "turn/completed",
                    "params": {"turn": {"id": "turn_text"}},
                },
            ],
        )

        @contextmanager
        def fake_session(codex_home):
            yield fake

        image_url = f"data:image/png;base64,{PNG_BASE64}"

        with mock.patch.object(main, "STORAGE_ROOT", storage_root), mock.patch.object(
            main, "app_server_session", fake_session
        ):
            result = main.RuntimeState().generate_text(
                123,
                {
                    "input": "Describe this image.",
                    "inputImages": [{"url": image_url}],
                },
            )

        self.assertEqual(result["outputText"], "A tiny transparent image.")
        turn_start = next(params for method, params, _ in fake.requests if method == "turn/start")
        self.assertEqual(
            turn_start["input"],
            [
                {"type": "text", "text": "Describe this image."},
                {"type": "image", "url": image_url},
            ],
        )

    def test_fails_closed_when_capability_is_unavailable(self):
        temp, storage_root = stored_auth_temp_root()
        self.addCleanup(temp.cleanup)
        fake = FakeSession({"modelProvider/capabilities/read": {"imageGeneration": False}})

        @contextmanager
        def fake_session(codex_home):
            yield fake

        with mock.patch.object(main, "STORAGE_ROOT", storage_root), mock.patch.object(
            main, "app_server_session", fake_session
        ):
            with self.assertRaises(main.HttpError) as caught:
                main.RuntimeState().generate_image(123, {"prompt": "Draw a blue square."})

        self.assertEqual(caught.exception.code, "image_generation_unavailable")
        self.assertEqual(caught.exception.status, main.HTTPStatus.CONFLICT)
        self.assertEqual([call[0] for call in fake.requests], ["modelProvider/capabilities/read"])

    def test_fails_closed_when_capability_probe_fails(self):
        temp, storage_root = stored_auth_temp_root()
        self.addCleanup(temp.cleanup)
        fake = FakeSession(
            {
                "modelProvider/capabilities/read": lambda method, params: (
                    (_ for _ in ()).throw(main.JsonRpcError("Unknown method."))
                )
            }
        )

        @contextmanager
        def fake_session(codex_home):
            yield fake

        with mock.patch.object(main, "STORAGE_ROOT", storage_root), mock.patch.object(
            main, "app_server_session", fake_session
        ):
            with self.assertRaises(main.HttpError) as caught:
                main.RuntimeState().generate_image(123, {"prompt": "Draw a blue square."})

        self.assertEqual(caught.exception.code, "image_generation_unavailable")
        self.assertEqual(caught.exception.status, main.HTTPStatus.CONFLICT)
        self.assertEqual([call[0] for call in fake.requests], ["modelProvider/capabilities/read"])

    def test_generates_image_without_runtime_model_override(self):
        temp, storage_root = stored_auth_temp_root()
        self.addCleanup(temp.cleanup)
        fake = FakeSession(
            {
                "modelProvider/capabilities/read": {"imageGeneration": True},
                "thread/start": {"thread": {"id": "thread_123"}},
                "turn/start": {"turn": {"id": "turn_123"}},
                "account/rateLimits/read": {"rateLimits": {"remaining": 5}},
                "account/read": {
                    "account": {
                        "type": "chatgpt",
                        "email": "image@example.com",
                        "planType": "plus",
                    }
                },
            },
            [
                {
                    "method": "thread/tokenUsage/updated",
                    "params": {
                        "turnId": "turn_123",
                        "tokenUsage": {
                            "last": {
                                "inputTokens": 7,
                                "outputTokens": 0,
                                "reasoningOutputTokens": 0,
                            }
                        },
                    },
                },
                {
                    "method": "item/completed",
                    "params": {
                        "item": {
                            "type": "imageGeneration",
                            "status": "generating",
                        },
                    },
                },
                {
                    "method": "item/completed",
                    "params": {
                        "item": {
                            "type": "imageGeneration",
                            "status": "generating",
                            "result": PNG_BASE64,
                            "revisedPrompt": "A tiny blue square.",
                        },
                    },
                },
                {
                    "method": "turn/completed",
                    "params": {"turn": {"id": "turn_123"}},
                },
            ],
        )

        @contextmanager
        def fake_session(codex_home):
            yield fake

        with mock.patch.object(main, "STORAGE_ROOT", storage_root), mock.patch.object(
            main, "app_server_session", fake_session
        ):
            result = main.RuntimeState().generate_image(
                123,
                {
                    "requestId": "request_123",
                    "prompt": "Draw a blue square.",
                    "model": "codex-image",
                },
            )

        self.assertEqual(result["requestId"], "request_123")
        self.assertEqual(result["model"], "codex-image")
        self.assertEqual(result["runtimeModel"], None)
        self.assertEqual(result["mimeType"], "image/png")
        self.assertEqual(result["imageBase64"], PNG_BASE64)
        self.assertEqual(result["revisedPrompt"], "A tiny blue square.")
        self.assertEqual(
            result["usage"],
            {"inputTokens": 7, "outputTokens": 0, "reasoningOutputTokens": 0},
        )

        thread_start = next(params for method, params, _ in fake.requests if method == "thread/start")
        turn_start = next(params for method, params, _ in fake.requests if method == "turn/start")
        self.assertNotIn("model", thread_start)
        self.assertNotIn("model", turn_start)
        self.assertEqual(
            turn_start["input"],
            [
                {
                    "type": "text",
                    "text": "Generate an image using the image_generation tool. Do not reply with text-only commentary. Use this exact image prompt:\n\nDraw a blue square.",
                }
            ],
        )


if __name__ == "__main__":
    unittest.main()
