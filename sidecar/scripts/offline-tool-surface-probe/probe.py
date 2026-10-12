#!/usr/bin/env python3
"""Observational tool-surface probe for the Codex WordPress sidecar.

Runs the real sidecar generation code against the real Codex app-server and
the matching code-mode host installed, against a loopback HTTPS mock of the
ChatGPT model backend. Run through run.sh; see README.md for the scope.

Enforced invariants:
- The container runs with --network none (strictly loopback only).
- No real credentials are used (synthetic, unsigned token file).
- The mock is purely observational: it never sends tool calls back to the model.
- Tool specs offered to direct-tool and code-mode models must belong to the audited allowlist.
- ChatGPT Apps and Plugins endpoints (/ps/*, /plugins/*, /ps/mcp) must never be contacted.
- No websocket connection may succeed.
"""
from __future__ import annotations

import base64
import hashlib
import http.server
import json
import os
import re
import shutil
import ssl
import sys
import threading
import time
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, "/sidecar/app")
import main  # noqa: E402

OUT = Path(os.environ.get("PROBE_OUT", "/out"))
ROOT = Path("/tmp/codex-probe-root")
MODELS_FILE = Path("/inputs/models.json")
MODELS = json.loads(MODELS_FILE.read_text(encoding="utf-8"))

# Base tool allowlist observed on Codex 0.162.1 with --disable apps --disable plugins
# and the sidecar generation thread policy.
AUDITED_TOOL_ALLOWLIST = frozenset({
    "request_user_input",
    "request_user_input_async",
    "get_goal",
    "create_goal",
    "update_goal",
    "list_mcp_resources",
    "list_mcp_resource_templates",
    "read_mcp_resource",
    "clock.sleep",
    "functions.exec",
    "functions.wait",
    "functions.request_user_input",
    "functions.request_user_input_async",
})

IMAGE_TOOL = "image_gen.imagegen"
DIRECT_MODEL = "gpt-5.5"
CODE_MODE_MODEL = "gpt-6-astra"
# Code-mode names are separate from model-facing namespace names. Keep these
# explicit: accepting functions.exec alone does not constrain what it exposes.
CODE_MODE_ALLOWLIST = frozenset({
    "get_goal", "create_goal", "update_goal", "list_mcp_resources",
    "list_mcp_resource_templates", "read_mcp_resource", "clock__curr_time",
})
EXPECTED_PATHS = frozenset({
    "/backend-api/codex/models",
    "/backend-api/codex/responses",
    "/backend-api/wham/usage",
    "/backend-api/wham/accounts/check",
    "/backend-api/wham/settings/user",
    "/backend-api/wham/rate-limit-reset-credits",
    "/backend-api/codex/analytics-events/events",
})

STATE: dict = {"scenario": None, "requests": [], "port": 0}
LOCK = threading.Lock()
RPC_LOG: list[dict] = []


class ObservingSession(main.JsonRpcSession):
    """Record policy echoes and received turn status without changing transport."""

    def request(self, method, *args, **kwargs):
        result = super().request(method, *args, **kwargs)
        if method == "thread/start":
            approval, sandbox_type, environments = main.thread_policy_echo(result)
            RPC_LOG.append({"method": method, "approvalPolicy": approval,
                            "sandboxType": sandbox_type, "environments": environments})
        return result

    def wait_for_notification(self, *args, **kwargs):
        message = super().wait_for_notification(*args, **kwargs)
        params = message.get("params") or {}
        RPC_LOG.append({"method": message.get("method"),
                        "turnStatus": (params.get("turn") or {}).get("status"),
                        "itemType": (params.get("item") or {}).get("type")})
        return message


def b64url(obj: dict) -> str:
    return base64.urlsafe_b64encode(json.dumps(obj).encode()).rstrip(b"=").decode()


def synthetic_jwt() -> str:
    claims = {
        "email": "probe@example.test",
        "exp": int(time.time()) + 30 * 86400,
        "https://api.openai.com/auth": {
            "chatgpt_plan_type": "plus",
            "chatgpt_user_id": "user-probe",
            "chatgpt_account_id": "acct-probe",
        },
    }
    return f"{b64url({'alg': 'none', 'typ': 'JWT'})}.{b64url(claims)}.unsigned"


def offered_tools(body: dict) -> list[dict]:
    # Direct-tool models receive tools in body["tools"].
    # Code-mode / Responses Lite models receive tools inside input[type=additional_tools].
    tools = list(body.get("tools") or [])
    for item in body.get("input") or []:
        if isinstance(item, dict) and item.get("type") == "additional_tools":
            tools.extend(item.get("tools") or [])
    return tools


def tool_names(body: dict) -> list[str]:
    names: list[str] = []
    for tool in offered_tools(body):
        if not isinstance(tool, dict):
            raise ValueError("Malformed tool spec")
        if tool.get("type") == "namespace":
            for nested in tool.get("tools") or []:
                if not isinstance(nested, dict) or not nested.get("name"):
                    raise ValueError("Malformed namespace tool spec")
                names.append(f"{tool.get('name')}.{nested['name']}")
        else:
            names.append(tool.get("name") or tool.get("type"))
    if any(not isinstance(name, str) or not name for name in names):
        raise ValueError("Tool spec has no name or type")
    return sorted(set(names))


def nested_code_mode_tools(body: dict) -> list[str]:
    names: set[str] = set()
    for tool in offered_tools(body):
        for nested in (tool.get("tools") or []) if tool.get("type") == "namespace" else [tool]:
            if nested.get("name") == "exec":
                names.update(
                    re.findall(
                        r"declare const tools: \{ ([A-Za-z0-9_]+)\(",
                        nested.get("description") or "",
                    )
                )
    return sorted(names)


def observed_request(body: dict) -> dict:
    # Save the offered specs and routing data, not auth headers or full prompt
    # histories. Everything here comes from synthetic probe requests.
    return {
        "model": body.get("model"),
        "tools": body.get("tools") or [],
        "additional_tools": [item for item in body.get("input") or []
                             if isinstance(item, dict) and item.get("type") == "additional_tools"],
        "input_types": [item.get("type") for item in body.get("input") or [] if isinstance(item, dict)],
        "tool_names": tool_names(body),
        "code_mode_nested_tools": nested_code_mode_tools(body),
    }


class MockBackend(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args) -> None:
        return

    def _record(self, body_bytes: bytes) -> dict:
        try:
            body = json.loads(body_bytes) if body_bytes else None
        except ValueError:
            body = {"parse_error": True}
        entry = {
            "scenario": STATE["scenario"],
            "method": self.command,
            "path": self.path,
            "upgrade": self.headers.get("upgrade"),
            "content_encoding": self.headers.get("content-encoding"),
            "model_request": observed_request(body) if isinstance(body, dict) and "input" in body else None,
            "invalid_json": isinstance(body, dict) and body.get("parse_error") is True,
            "status": None,
        }
        self.entry = entry
        with LOCK:
            STATE["requests"].append(entry)
        return entry

    def _send(self, status: int, payload: bytes, content_type: str = "application/json") -> None:
        self.entry["status"] = status
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(payload)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(payload)
        self.close_connection = True

    def do_GET(self) -> None:  # noqa: N802
        self._record(b"")
        path = self.path.split("?")[0]
        if self.headers.get("upgrade", "").lower() == "websocket":
            self._send(426, b'{"detail":"websocket upgrade refused"}')
        elif path.endswith("/codex/models"):
            self._send(200, json.dumps(MODELS).encode())
        elif path.endswith("/wham/usage"):
            self._send(200, json.dumps({"plan_type": "plus"}).encode())
        elif path.endswith("/wham/accounts/check"):
            self._send(
                200,
                json.dumps({
                    "accounts": [{
                        "id": "acct-probe",
                        "plan_type": "plus",
                        "workspace_backend_origin": "NO_CONSTRAINT",
                        "account_routing_override": "NO_CONSTRAINT",
                        "structure": "personal",
                    }],
                    "account_ordering": ["acct-probe"],
                    "default_account_id": "acct-probe",
                }).encode(),
            )
        else:
            self._send(404, b'{"detail":"not found"}')

    def do_POST(self) -> None:  # noqa: N802
        length = int(self.headers.get("content-length") or 0)
        raw = self.rfile.read(length) if length else b""
        self._record(raw)
        path = self.path.split("?")[0]
        if path.endswith("/codex/responses"):
            rid = f"resp_{time.monotonic_ns()}"
            # A plain message completes every turn without invoking a tool.
            # Image-thread scenarios observe the offered surface and must reject
            # this text-only result rather than pretending an image was made.
            items = [{
                "type": "message",
                "role": "assistant",
                "id": f"msg_{time.monotonic_ns()}",
                "content": [{"type": "output_text", "text": "PROBE_TEXT_OK"}],
            }]
            events = [{"type": "response.created", "response": {"id": rid}}]
            events += [{"type": "response.output_item.done", "item": item} for item in items]
            events.append({
                "type": "response.completed",
                "response": {
                    "id": rid,
                    "usage": {
                        "input_tokens": 10,
                        "input_tokens_details": {"cached_tokens": 0},
                        "output_tokens": 5,
                        "output_tokens_details": {"reasoning_tokens": 0},
                        "total_tokens": 15,
                    },
                },
            })
            payload = "".join(f"event: {e['type']}\ndata: {json.dumps(e)}\n\n" for e in events).encode()
            self._send(200, payload, "text/event-stream")
        else:
            self._send(404, b'{"detail":"not found"}')


def prepare_user_home(config_model: str | None = None) -> Path:
    if ROOT.exists():
        shutil.rmtree(ROOT)
    home = ROOT / "users" / "123"
    home.mkdir(parents=True)
    jwt = synthetic_jwt()
    (home / "auth.json").write_text(
        json.dumps({
            "auth_mode": "chatgpt",
            "OPENAI_API_KEY": None,
            "tokens": {
                "id_token": jwt,
                "access_token": jwt,
                "refresh_token": "rt-probe",
                "account_id": "acct-probe",
            },
            "last_refresh": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        }),
        encoding="utf-8",
    )
    base = f"https://127.0.0.1:{STATE['port']}"
    (home / "config.toml").write_text(
        (f'model = "{config_model}"\n' if config_model else "")
        + f'openai_base_url = "{base}/backend-api/codex"\n'
        f'chatgpt_base_url = "{base}/backend-api/"\n'
        "[features]\nenable_request_compression = false\n",
        encoding="utf-8",
    )
    return home


def run_scenario(name: str, call, *, config_model: str | None = None) -> dict:
    STATE["scenario"] = name
    prepare_user_home(config_model)
    with LOCK:
        start_req_count = len(STATE["requests"])
    start_rpc_count = len(RPC_LOG)
    start_time = time.monotonic()
    try:
        result = call()
        if result.get("outputText") != "PROBE_TEXT_OK":
            raise AssertionError("Sidecar did not return the mock text completion exactly")
        outcome = {
            "ok": True,
            "result": {
                k: (v if k != "imageBase64" else f"<{len(v)} b64 chars>")
                for k, v in result.items()
                if k not in {"account", "rateLimits"}
            },
        }
    except Exception as error:  # noqa: BLE001
        outcome = {
            "ok": False,
            "error": f"{type(error).__name__}: {error}",
            "code": getattr(error, "code", None),
        }
    elapsed = round(time.monotonic() - start_time, 2)
    with LOCK:
        requests = list(STATE["requests"][start_req_count:])
    response_requests = [
        r for r in requests if r["path"].split("?")[0].endswith("/codex/responses") and r["method"] == "POST"
    ]
    all_tools: set[str] = set()
    all_nested: set[str] = set()
    for req in response_requests:
        observation = req.get("model_request") or {}
        all_tools.update(observation.get("tool_names") or [])
        all_nested.update(observation.get("code_mode_nested_tools") or [])

    paths = sorted({
        f"{r['method']} {r['path'].split('?')[0]}" + (" (ws upgrade)" if r.get("upgrade") else "")
        for r in requests
    })
    forbidden_traffic = [p for p in paths if "/ps/" in p or "/plugins/" in p or p.endswith("/ps/mcp")]
    upgrades = [{"path": req["path"], "status": req["status"]} for req in requests if req.get("upgrade")]
    rpc = RPC_LOG[start_rpc_count:]

    return {
        "scenario": name,
        "seconds": elapsed,
        "outcome": outcome,
        "paths": paths,
        "forbidden_traffic": forbidden_traffic,
        "responses_calls": len(response_requests),
        "models_requested": sorted({(req.get("model_request") or {}).get("model") or "<missing>"
                                    for req in response_requests}),
        "tools_offered": sorted(all_tools),
        "code_mode_nested_tools": sorted(all_nested),
        "websocket_upgrades": upgrades,
        "rpc_observations": rpc,
        "image_delivery_qualified": False if name.startswith("image") else None,
        "backend_requests": requests,
    }


def check_scenario(report: dict) -> list[str]:
    name = report["scenario"]
    image = name.startswith("image")
    code_mode = name.endswith("code-mode")
    expected_model = CODE_MODE_MODEL if code_mode else DIRECT_MODEL
    failures = []
    if image:
        if report["outcome"].get("code") != "image_generation_failed":
            failures.append("image thread did not reject the mock's text-only completion as expected")
    elif not report["outcome"]["ok"]:
        failures.append(f"text generation failed: {report['outcome']}")
    rpc = report["rpc_observations"]
    if not any(note.get("method") == "turn/completed" and note.get("turnStatus") == "completed" for note in rpc):
        failures.append("Codex did not report a successfully completed turn")
    policy = [note for note in rpc if note.get("method") == "thread/start"]
    if len(policy) != 1 or policy[0].get("approvalPolicy") != "never" or policy[0].get("sandboxType") != "readOnly" or policy[0].get("environments") != []:
        failures.append("Codex did not echo the generation policy")
    # A subset check is vacuous if the mock never saw a model request or tools.
    if report["responses_calls"] != 1 or not report["tools_offered"]:
        failures.append("expected exactly one model HTTP request with a nonempty tool surface")
    if report["models_requested"] != [expected_model]:
        failures.append(f"expected model {expected_model}, got {report['models_requested']}")
    disallowed = set(report["tools_offered"]) - (AUDITED_TOOL_ALLOWLIST | ({IMAGE_TOOL} if image else set()))
    if disallowed:
        failures.append(f"offered disallowed tools: {sorted(disallowed)}")
    nested = set(report["code_mode_nested_tools"])
    disallowed_nested = nested - (CODE_MODE_ALLOWLIST | ({"image_gen__imagegen"} if image else set()))
    if disallowed_nested:
        failures.append(f"offered disallowed code-mode tools: {sorted(disallowed_nested)}")
    if code_mode != ("functions.exec" in report["tools_offered"]) or code_mode != bool(nested):
        failures.append("model did not expose the expected direct-tool/code-mode surface")
    if image and ("image_gen__imagegen" not in nested if code_mode else IMAGE_TOOL not in report["tools_offered"]):
        failures.append("image generation tool was not offered")
    if report["forbidden_traffic"]:
        failures.append(f"contacted Apps/Plugins endpoints: {report['forbidden_traffic']}")
    for req in report["backend_requests"]:
        path = req["path"].split("?")[0]
        if path not in EXPECTED_PATHS:
            failures.append(f"unexpected backend endpoint: {req['method']} {path}")
        if req.get("invalid_json") or req.get("content_encoding") not in (None, "identity"):
            failures.append("mock could not inspect the uncompressed JSON request")
        if req.get("status") == 101:
            failures.append("a websocket upgrade succeeded")
        if req.get("upgrade") and (path != "/backend-api/codex/responses" or req.get("status") != 426):
            failures.append(f"unexpected websocket attempt: {path} (HTTP {req.get('status')})")
        types = (req.get("model_request") or {}).get("input_types") or []
        if any(kind.endswith("_output") for kind in types if isinstance(kind, str)):
            failures.append("a tool result reached the model in an observational probe")
    return [f"{name}: {failure}" for failure in failures]


def main_entry() -> int:
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), MockBackend)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain("/tmp/probe-tls/server.crt", "/tmp/probe-tls/server.key")
    server.socket = context.wrap_socket(server.socket, server_side=True)
    STATE["port"] = server.server_address[1]
    threading.Thread(target=server.serve_forever, daemon=True).start()

    main.CODEX_BIN = "/codex/codex"
    main.JsonRpcSession = ObservingSession
    main.STORAGE_ROOT = ROOT
    main.REQUEST_TIMEOUT = 30
    main.TURN_TIMEOUT = 60

    state = main.RuntimeState()

    scenarios = [
        ("text-code-mode", lambda: state.generate_text(123, {"input": "Say hello.", "model": CODE_MODE_MODEL}), CODE_MODE_MODEL),
        ("text-direct-tool", lambda: state.generate_text(123, {"input": "Say hello.", "model": DIRECT_MODEL}), DIRECT_MODEL),
        ("image-code-mode", lambda: state.generate_image(123, {"prompt": "a small red square"}), CODE_MODE_MODEL),
        ("image-direct-tool", lambda: state.generate_image(123, {"prompt": "a small red square"}), DIRECT_MODEL),
    ]

    reports = []
    failed_assertions = []

    print(f"Starting observational probe across {len(scenarios)} scenarios...")
    for name, call, config_model in scenarios:
        report = run_scenario(name, call, config_model=config_model)
        reports.append(report)
        failed_assertions.extend(check_scenario(report))

        print(
            f"  {name:20} ok={str(report['outcome']['ok']):5} "
            f"time={report['seconds']}s "
            f"tools={report['tools_offered']}"
        )

    OUT.mkdir(parents=True, exist_ok=True)
    evidence = {
        "ok": not failed_assertions,
        "codex_version": main.QUALIFIED_CODEX_VERSION,
        "sidecar_version": main._server_identity()["version"],
        "sidecar_sha256": hashlib.sha256(Path(main.__file__).read_bytes()).hexdigest(),
        "probe_sha256": hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
        "models_sha256": hashlib.sha256(MODELS_FILE.read_bytes()).hexdigest(),
        "container_image": os.environ.get("PROBE_IMAGE_ID"),
        "launch_command": main.codex_app_server_command(),
        "scenarios": reports,
        "failures": failed_assertions,
    }
    (OUT / "probe-report.json").write_text(json.dumps(evidence, indent=2), encoding="utf-8")
    summary = ["| Scenario | Model | Completion handling | Tools | Nested tools | Model HTTP calls | Refused model WS upgrades |",
               "| --- | --- | --- | ---: | ---: | ---: | ---: |"]
    for report in reports:
        handling = "Text delivered" if report['outcome']['ok'] else "Text-only image result rejected"
        summary.append(f"| {report['scenario']} | {', '.join(report['models_requested'])} | "
                       f"{handling} | {len(report['tools_offered'])} | "
                       f"{len(report['code_mode_nested_tools'])} | {report['responses_calls']} | "
                       f"{len(report['websocket_upgrades'])} |")
    (OUT / "probe-summary.md").write_text("\n".join(summary) + "\n", encoding="utf-8")

    server.shutdown()
    server.server_close()

    if failed_assertions:
        print("\nPROBE FAILED ASSERTIONS:", file=sys.stderr)
        for failure in failed_assertions:
            print(f"  - {failure}", file=sys.stderr)
        return 1

    print("\nPROBE PASSED: text delivery and all four thread tool surfaces satisfy the checks; "
          "real Codex image-tool execution was not performed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main_entry())
