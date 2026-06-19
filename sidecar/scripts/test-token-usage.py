#!/usr/bin/env python3
"""Standalone unit test for token-usage extraction (no pytest dependency).

Run: python3 sidecar/scripts/test-token-usage.py

Fixtures use the real ``thread/tokenUsage/updated`` shape captured from the
codex app-server (codex-cli 0.140.0).
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "app"))

from main import extract_turn_token_usage  # noqa: E402


def check(name: str, got: object, want: object) -> int:
    if got != want:
        print(f"FAIL {name}: got {got!r}, want {want!r}")
        return 1
    print(f"ok   {name}")
    return 0


failures = 0

# Real captured payload (params.tokenUsage) from codex app-server 0.140.0.
captured = {
    "total": {"totalTokens": 18079, "inputTokens": 18074, "cachedInputTokens": 2432, "outputTokens": 5, "reasoningOutputTokens": 0},
    "last": {"totalTokens": 18079, "inputTokens": 18074, "cachedInputTokens": 2432, "outputTokens": 5, "reasoningOutputTokens": 0},
    "modelContextWindow": 258400,
}
failures += check(
    "captured prefers .last",
    extract_turn_token_usage(captured),
    {"inputTokens": 18074, "outputTokens": 5, "reasoningOutputTokens": 0},
)

# Reasoning tokens are surfaced raw as an informational detail. Codex counts
# them inside outputTokens (reasoningOutputTokens is a subset of outputTokens, so
# it never exceeds it), which is why the PHP model logs outputTokens alone rather
# than adding the two together.
reasoning = {"last": {"inputTokens": 100, "outputTokens": 220, "reasoningOutputTokens": 200}}
failures += check(
    "reasoning surfaced",
    extract_turn_token_usage(reasoning),
    {"inputTokens": 100, "outputTokens": 220, "reasoningOutputTokens": 200},
)

# Falls back to .total when .last is absent.
only_total = {"total": {"inputTokens": 7, "outputTokens": 8, "reasoningOutputTokens": 1}}
failures += check(
    "falls back to .total",
    extract_turn_token_usage(only_total),
    {"inputTokens": 7, "outputTokens": 8, "reasoningOutputTokens": 1},
)

# Missing / malformed usage degrades to zeros (matches pre-fix behaviour: the
# writer omits zeros, so the log shows no tokens rather than wrong tokens).
failures += check(
    "none -> zeros",
    extract_turn_token_usage(None),
    {"inputTokens": 0, "outputTokens": 0, "reasoningOutputTokens": 0},
)
failures += check(
    "non-int -> zeros",
    extract_turn_token_usage({"last": {"inputTokens": "x", "outputTokens": None}}),
    {"inputTokens": 0, "outputTokens": 0, "reasoningOutputTokens": 0},
)

if failures:
    print(f"\n{failures} failing assertion(s)")
    sys.exit(1)
print("\nall token-usage extraction tests passed")
