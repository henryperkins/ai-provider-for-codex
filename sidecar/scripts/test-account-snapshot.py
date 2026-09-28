#!/usr/bin/env python3
"""Regression tests for managed-auth refresh during account snapshots."""

import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "app"))

import main  # noqa: E402


class AccountSnapshotTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory()
        self.addCleanup(temp.cleanup)
        self.storage_root = Path(temp.name)
        self.codex_home = self.storage_root / "users" / "123"
        self.codex_home.mkdir(parents=True)
        # Placeholder only: no real credentials or Codex process are used.
        (self.codex_home / "auth.json").write_text("{}", encoding="utf-8")
        patcher = mock.patch.object(main, "STORAGE_ROOT", self.storage_root)
        patcher.start()
        self.addCleanup(patcher.stop)

    def test_snapshot_requests_managed_token_refresh_before_reading_metadata(self):
        with mock.patch.object(main, "app_server_session") as open_session:
            session = open_session.return_value.__enter__.return_value
            session.request.side_effect = [
                {"account": {"type": "chatgpt", "email": "test@example.test", "planType": "plus"}},
                {"rateLimits": {"remaining": 5}},
                {"data": [{"model": "test-model", "isDefault": True}]},
                {"imageGeneration": False},
            ]

            snapshot = main.RuntimeState().account_snapshot(123)

        self.assertEqual(
            session.request.call_args_list,
            [
                mock.call("account/read", {"refreshToken": True}, timeout=main.REQUEST_TIMEOUT),
                mock.call("account/rateLimits/read", timeout=main.REQUEST_TIMEOUT),
                mock.call("model/list", {"includeHidden": False}, timeout=main.REQUEST_TIMEOUT),
                mock.call("modelProvider/capabilities/read", {}, timeout=main.REQUEST_TIMEOUT),
            ],
        )
        self.assertEqual(snapshot["account"]["authMode"], "chatgpt")
        self.assertEqual(snapshot["defaultModel"], "test-model")
        self.assertEqual(snapshot["rateLimits"], {"remaining": 5})

    def test_missing_auth_does_not_start_an_app_server(self):
        with mock.patch.object(main, "app_server_session") as open_session:
            with self.assertRaises(main.HttpError) as caught:
                main.RuntimeState().account_snapshot(456)

        self.assertEqual(caught.exception.code, "auth_required")
        open_session.assert_not_called()

    def test_failed_refresh_does_not_read_further_account_metadata(self):
        with mock.patch.object(main, "app_server_session") as open_session:
            session = open_session.return_value.__enter__.return_value
            session.request.side_effect = main.JsonRpcError("Refresh failed.")

            with self.assertRaisesRegex(main.JsonRpcError, "Refresh failed"):
                main.RuntimeState().account_snapshot(123)

        session.request.assert_called_once_with(
            "account/read", {"refreshToken": True}, timeout=main.REQUEST_TIMEOUT
        )


if __name__ == "__main__":
    unittest.main()
