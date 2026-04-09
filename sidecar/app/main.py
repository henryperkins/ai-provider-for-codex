from __future__ import annotations

import hmac
import json
import os
import subprocess
import threading
import time
import uuid
from contextlib import contextmanager
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any, Callable
from urllib.parse import parse_qs, urlparse

CODEX_BIN = os.environ.get("CODEX_BIN", "/usr/local/bin/codex")
STORAGE_ROOT = Path(os.environ.get("CODEX_WP_STORAGE_ROOT", "/var/lib/codex-wp"))
HOST = os.environ.get("CODEX_WP_HOST", "127.0.0.1")
PORT = int(os.environ.get("CODEX_WP_PORT", "4317"))
AUTH_TOKEN = os.environ.get("CODEX_WP_BEARER_TOKEN", "")
REQUEST_TIMEOUT = float(os.environ.get("CODEX_RUNTIME_REQUEST_TIMEOUT", "60"))
TURN_TIMEOUT = float(os.environ.get("CODEX_RUNTIME_TURN_TIMEOUT", "300"))
LOGIN_TIMEOUT = float(os.environ.get("CODEX_RUNTIME_LOGIN_TIMEOUT", "1800"))
INITIALIZE_CAPABILITY_OPTOUTS = [
    "codex/event/agent_message_content_delta",
    "codex/event/reasoning_content_delta",
    "codex/event/item_started",
    "codex/event/item_completed",
    "codex/event/task_started",
    "codex/event/task_complete",
]


class JsonRpcError(RuntimeError):
    def __init__(self, message: str, *, code: int | None = None, data: Any = None) -> None:
        super().__init__(message)
        self.code = code
        self.data = data


class HttpError(RuntimeError):
    def __init__(self, code: str, message: str, status: int) -> None:
        super().__init__(message)
        self.code = code
        self.status = status


class JsonRpcSession:
    def __init__(self, codex_home: Path) -> None:
        self._codex_home = codex_home
        self._proc: subprocess.Popen[str] | None = None
        self._reader: threading.Thread | None = None
        self._write_lock = threading.Lock()
        self._state_lock = threading.RLock()
        self._notification_condition = threading.Condition(self._state_lock)
        self._next_request_id = 1
        self._pending: dict[int, dict[str, Any]] = {}
        self._notifications: list[dict[str, Any]] = []
        self._transport_error: str | None = None
        self._closed = False

    def start(self) -> JsonRpcSession:
        if self._proc is not None:
            return self

        ensure_codex_home(self._codex_home)
        env = os.environ.copy()
        env["CODEX_HOME"] = str(self._codex_home)
        env["HOME"] = str(self._codex_home)

        self._proc = subprocess.Popen(
            [CODEX_BIN, "app-server"],
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            text=True,
            bufsize=1,
            env=env,
        )
        self._reader = threading.Thread(target=self._reader_loop, daemon=True)
        self._reader.start()
        self.request(
            "initialize",
            {
                "protocolVersion": "1",
                "clientInfo": {
                    "name": "codex-wp-sidecar",
                    "version": "0.1.0",
                },
                "capabilities": {
                    "optOutNotificationMethods": INITIALIZE_CAPABILITY_OPTOUTS,
                },
            },
            timeout=REQUEST_TIMEOUT,
        )
        return self

    def close(self) -> None:
        with self._state_lock:
            if self._closed:
                return
            self._closed = True
            proc = self._proc
            self._proc = None
            self._transport_error = self._transport_error or "Session closed."
            for waiter in self._pending.values():
                waiter["event"].set()
            self._notification_condition.notify_all()

        if proc is None:
            return

        if proc.stdin is not None and not proc.stdin.closed:
            proc.stdin.close()

        if proc.poll() is None:
            proc.terminate()
            try:
                proc.wait(timeout=2)
            except subprocess.TimeoutExpired:
                proc.kill()
                proc.wait(timeout=2)

    def request(
        self,
        method: str,
        params: dict[str, Any] | None = None,
        *,
        timeout: float = REQUEST_TIMEOUT,
    ) -> Any:
        self.start()

        with self._state_lock:
            self._raise_if_broken()
            request_id = self._next_request_id
            self._next_request_id += 1
            waiter = {
                "event": threading.Event(),
                "response": None,
            }
            self._pending[request_id] = waiter

        self._send(
            {
                "jsonrpc": "2.0",
                "id": request_id,
                "method": method,
                "params": params,
            }
        )

        if not waiter["event"].wait(timeout):
            with self._state_lock:
                self._pending.pop(request_id, None)
            raise TimeoutError(f"{method} timed out after {timeout:.1f}s")

        response = waiter["response"]
        if not isinstance(response, dict):
            raise RuntimeError(f"{method} returned an invalid JSON-RPC response.")

        error = response.get("error")
        if isinstance(error, dict):
            raise JsonRpcError(
                str(error.get("message") or f"{method} failed."),
                code=error.get("code") if isinstance(error.get("code"), int) else None,
                data=error.get("data"),
            )

        return response.get("result")

    def wait_for_notification(
        self,
        predicate: Callable[[dict[str, Any]], bool],
        *,
        timeout: float | None = None,
    ) -> dict[str, Any]:
        deadline = None if timeout is None else time.monotonic() + timeout

        with self._notification_condition:
            while True:
                self._raise_if_broken()

                for index, notification in enumerate(self._notifications):
                    if predicate(notification):
                        return self._notifications.pop(index)

                if deadline is None:
                    self._notification_condition.wait()
                    continue

                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise TimeoutError("Timed out waiting for notification.")
                self._notification_condition.wait(timeout=remaining)

    def _send(self, payload: dict[str, Any]) -> None:
        proc = self._proc
        if proc is None or proc.stdin is None or proc.stdin.closed:
            raise RuntimeError("JSON-RPC session is not connected.")

        line = json.dumps(payload, separators=(",", ":")) + "\n"

        with self._write_lock:
            try:
                proc.stdin.write(line)
                proc.stdin.flush()
            except Exception as exc:
                self._set_transport_error(f"Failed writing to Codex app-server: {exc}")
                raise RuntimeError("Failed writing to Codex app-server.") from exc

    def _reader_loop(self) -> None:
        proc = self._proc
        if proc is None or proc.stdout is None:
            self._set_transport_error("Codex app-server process is unavailable.")
            return

        try:
            while True:
                line = proc.stdout.readline()
                if not line:
                    break

                message = json.loads(line)
                with self._notification_condition:
                    if isinstance(message, dict) and "id" in message:
                        request_id = message.get("id")
                        if isinstance(request_id, int):
                            waiter = self._pending.pop(request_id, None)
                            if waiter is not None:
                                waiter["response"] = message
                                waiter["event"].set()
                        continue

                    if isinstance(message, dict):
                        self._notifications.append(message)
                        self._notification_condition.notify_all()
        except Exception as exc:
            self._set_transport_error(f"Codex app-server transport failed: {exc}")
            return

        return_code = proc.poll()
        self._set_transport_error(
            f"Codex app-server exited unexpectedly with code {return_code}."
        )

    def _set_transport_error(self, message: str) -> None:
        with self._notification_condition:
            if self._transport_error is None:
                self._transport_error = message
            for waiter in self._pending.values():
                waiter["event"].set()
            self._notification_condition.notify_all()

    def _raise_if_broken(self) -> None:
        if self._transport_error:
            raise RuntimeError(self._transport_error)


def ensure_storage_root() -> None:
    STORAGE_ROOT.mkdir(parents=True, exist_ok=True)


def user_codex_home(wp_user_id: int) -> Path:
    if wp_user_id <= 0:
        raise RuntimeError("wpUserId must be a positive integer.")
    return STORAGE_ROOT / "users" / str(wp_user_id)


def ensure_codex_home(codex_home: Path) -> None:
    codex_home.mkdir(parents=True, exist_ok=True)


def auth_file_path(codex_home: Path) -> Path:
    return codex_home / "auth.json"


def now_timestamp() -> int:
    return int(time.time())


@contextmanager
def app_server_session(codex_home: Path) -> Any:
    session = JsonRpcSession(codex_home).start()
    try:
        yield session
    finally:
        session.close()


class RuntimeState:
    def __init__(self) -> None:
        self._lock = threading.RLock()
        self._login_sessions: dict[str, dict[str, Any]] = {}

    def start_login(self, wp_user_id: int) -> dict[str, Any]:
        with self._lock:
            existing = self._find_active_login_session(wp_user_id)
            if existing is not None:
                return self._public_login_payload(existing)

            auth_session_id = f"auth_{uuid.uuid4().hex[:20]}"
            codex_home = user_codex_home(wp_user_id)
            session = JsonRpcSession(codex_home).start()
            response = session.request(
                "account/login/start",
                {"type": "chatgptDeviceCode"},
                timeout=REQUEST_TIMEOUT,
            )

            if not isinstance(response, dict) or "chatgptDeviceCode" != response.get("type"):
                session.close()
                raise RuntimeError("Device-code login did not return a device-code response.")

            login_id = optional_string(response.get("loginId"))
            verification_url = optional_string(response.get("verificationUrl"))
            user_code = optional_string(response.get("userCode"))
            if not login_id or not verification_url or not user_code:
                session.close()
                raise RuntimeError("Device-code login response was incomplete.")

            login_session = {
                "authSessionId": auth_session_id,
                "wpUserId": wp_user_id,
                "codexHome": codex_home,
                "loginId": login_id,
                "verificationUrl": verification_url,
                "userCode": user_code,
                "status": "pending",
                "error": None,
                "updatedAt": now_timestamp(),
                "_rpc": session,
            }
            self._login_sessions[auth_session_id] = login_session

            watcher = threading.Thread(
                target=self._watch_login_completion,
                args=(auth_session_id,),
                daemon=True,
            )
            watcher.start()

            return self._public_login_payload(login_session)

    def login_status(self, wp_user_id: int, auth_session_id: str) -> tuple[dict[str, Any], int]:
        with self._lock:
            session = self._login_sessions.get(auth_session_id)

            if not session or session.get("wpUserId") != wp_user_id:
                return (
                    {
                        "authSessionId": auth_session_id,
                        "status": "missing",
                        "authStored": auth_file_path(user_codex_home(wp_user_id)).is_file(),
                        "verificationUrl": None,
                        "userCode": None,
                        "error": "Login session was not found in the local runtime.",
                    },
                    HTTPStatus.OK,
                )

            payload = self._public_login_payload(session)
            payload["authStored"] = auth_file_path(session["codexHome"]).is_file()
            return payload, HTTPStatus.OK

    def account_snapshot(self, wp_user_id: int) -> dict[str, Any]:
        codex_home = user_codex_home(wp_user_id)
        if not auth_file_path(codex_home).is_file():
            raise HttpError(
                "auth_required",
                "No stored ChatGPT or Codex auth is available for this WordPress user.",
                HTTPStatus.CONFLICT,
            )

        with app_server_session(codex_home) as session:
            account = session.request("account/read", {"refresh": True}, timeout=REQUEST_TIMEOUT)
            rate_limits = session.request("account/rateLimits/read", timeout=REQUEST_TIMEOUT)
            models = session.request("model/list", {"includeHidden": False}, timeout=REQUEST_TIMEOUT)

        account_payload = normalize_account_payload(account)
        if not account_payload["authMode"]:
            raise HttpError(
                "auth_required",
                "No active Codex account is available for this WordPress user.",
                HTTPStatus.CONFLICT,
            )

        return {
            "account": account_payload,
            "authStored": auth_file_path(codex_home).is_file(),
            "defaultModel": select_default_model(models),
            "models": normalize_models_payload(models),
            "rateLimits": normalize_rate_limits_payload(rate_limits),
        }

    def generate_text(self, wp_user_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        codex_home = user_codex_home(wp_user_id)
        if not auth_file_path(codex_home).is_file():
            raise HttpError(
                "auth_required",
                "No stored ChatGPT or Codex auth is available for this WordPress user.",
                HTTPStatus.CONFLICT,
            )

        input_text = require_string(payload.get("input"), "input")
        request_id = optional_string(payload.get("requestId")) or str(uuid.uuid4())
        model = optional_string(payload.get("model"))
        reasoning_effort = optional_string(payload.get("reasoningEffort"))
        system_instruction = optional_string(payload.get("systemInstruction"))
        response_format = payload.get("responseFormat")

        with app_server_session(codex_home) as session:
            thread_params: dict[str, Any] = {"ephemeral": True}
            if model:
                thread_params["model"] = model
            if system_instruction:
                thread_params["developerInstructions"] = system_instruction

            thread_started = session.request("thread/start", thread_params, timeout=REQUEST_TIMEOUT)
            thread_id = require_nested_string(thread_started, "thread", "id")

            turn_payload: dict[str, Any] = {
                "threadId": thread_id,
                "input": [{"type": "text", "text": input_text}],
            }
            if model:
                turn_payload["model"] = model
            if reasoning_effort:
                turn_payload["effort"] = reasoning_effort
            if (
                isinstance(response_format, dict)
                and "json_schema" == response_format.get("type")
                and isinstance(response_format.get("schema"), dict)
            ):
                turn_payload["outputSchema"] = response_format["schema"]

            started = session.request("turn/start", turn_payload, timeout=REQUEST_TIMEOUT)
            turn_id = require_nested_string(started, "turn", "id")
            output_parts: list[str] = []
            output_text: str | None = None
            finish_reason = "stop"

            while True:
                notification = session.wait_for_notification(
                    lambda message: notification_matches_turn(message, turn_id),
                    timeout=TURN_TIMEOUT,
                )
                method = notification.get("method")
                params = notification.get("params")

                if "item/agentMessage/delta" == method and isinstance(params, dict):
                    delta = params.get("delta")
                    if isinstance(delta, str):
                        output_parts.append(delta)
                    continue

                if "item/completed" == method and isinstance(params, dict):
                    item = params.get("item")
                    if isinstance(item, dict) and "agentMessage" == item.get("type"):
                        item_text = item.get("text")
                        if isinstance(item_text, str):
                            output_text = item_text
                    continue

                if "turn/completed" != method or not isinstance(params, dict):
                    continue

                turn = params.get("turn")
                if isinstance(turn, dict) and turn.get("error"):
                    raise RuntimeError(str(turn["error"]))
                break

            if not output_text:
                output_text = "".join(output_parts).strip()

            if not output_text:
                output_text = self._read_final_agent_message(session, thread_id, turn_id)

            rate_limits = session.request("account/rateLimits/read", timeout=REQUEST_TIMEOUT)
            account = session.request("account/read", {"refresh": False}, timeout=REQUEST_TIMEOUT)

        structured_output = None
        if response_format and output_text:
            try:
                structured_output = json.loads(output_text)
            except json.JSONDecodeError:
                structured_output = None

        return {
            "account": normalize_account_payload(account),
            "authStored": auth_file_path(codex_home).is_file(),
            "finishReason": finish_reason,
            "model": model or None,
            "outputText": output_text,
            "rateLimits": normalize_rate_limits_payload(rate_limits),
            "requestId": request_id,
            "structuredOutput": structured_output,
            "usage": {
                "inputTokens": 0,
                "outputTokens": 0,
            },
        }

    def clear_session(self, wp_user_id: int) -> None:
        clear_auth_json(user_codex_home(wp_user_id))

    def _read_final_agent_message(
        self,
        session: JsonRpcSession,
        thread_id: str,
        turn_id: str,
    ) -> str:
        response = session.request(
            "thread/read",
            {"threadId": thread_id, "includeTurns": True},
            timeout=REQUEST_TIMEOUT,
        )
        if not isinstance(response, dict):
            return ""

        thread = response.get("thread")
        if not isinstance(thread, dict):
            return ""

        turns = thread.get("turns")
        if not isinstance(turns, list):
            return ""

        for turn in turns:
            if not isinstance(turn, dict) or turn.get("id") != turn_id:
                continue
            items = turn.get("items")
            if not isinstance(items, list):
                continue
            for item in reversed(items):
                if not isinstance(item, dict) or "agentMessage" != item.get("type"):
                    continue
                item_text = item.get("text")
                if isinstance(item_text, str) and item_text.strip():
                    return item_text.strip()
        return ""

    def _watch_login_completion(self, auth_session_id: str) -> None:
        session_record: dict[str, Any] | None = None
        rpc_session: JsonRpcSession | None = None
        login_id = ""

        try:
            with self._lock:
                session_record = self._login_sessions.get(auth_session_id)
                if not session_record:
                    return
                rpc_session = session_record.get("_rpc")
                login_id = str(session_record.get("loginId") or "")

            if not rpc_session or not login_id:
                raise RuntimeError("Runtime login watcher could not access the app-server session.")

            notification = rpc_session.wait_for_notification(
                lambda message: is_login_completed_notification(message, login_id),
                timeout=LOGIN_TIMEOUT,
            )
            params = notification.get("params")
            if not isinstance(params, dict):
                raise RuntimeError("Login completion notification was malformed.")

            with self._lock:
                current = self._login_sessions.get(auth_session_id)
                if not current:
                    return
                current["updatedAt"] = now_timestamp()
                if params.get("success") is True:
                    current["status"] = "completed"
                    current["error"] = None
                else:
                    current["status"] = "error"
                    current["error"] = optional_string(params.get("error")) or "Device-code login failed."
        except Exception as exc:
            with self._lock:
                current = self._login_sessions.get(auth_session_id)
                if current:
                    current["status"] = "error"
                    current["error"] = str(exc)
                    current["updatedAt"] = now_timestamp()
        finally:
            if rpc_session is not None:
                rpc_session.close()
            with self._lock:
                current = self._login_sessions.get(auth_session_id)
                if current:
                    current.pop("_rpc", None)

    def _find_active_login_session(self, wp_user_id: int) -> dict[str, Any] | None:
        for session in self._login_sessions.values():
            if session.get("wpUserId") == wp_user_id and session.get("status") == "pending":
                return session
        return None

    def _public_login_payload(self, session: dict[str, Any]) -> dict[str, Any]:
        return {
            "authSessionId": session["authSessionId"],
            "verificationUrl": session["verificationUrl"],
            "userCode": session["userCode"],
            "status": session["status"],
            "error": session["error"],
        }


def is_login_completed_notification(message: dict[str, Any], login_id: str) -> bool:
    if "account/login/completed" != message.get("method"):
        return False
    params = message.get("params")
    return isinstance(params, dict) and params.get("loginId") == login_id


def notification_matches_turn(message: dict[str, Any], turn_id: str) -> bool:
    method = message.get("method")
    params = message.get("params")
    if not isinstance(params, dict):
        return False

    direct_turn_id = params.get("turnId")
    if isinstance(direct_turn_id, str):
        return direct_turn_id == turn_id

    turn = params.get("turn")
    if isinstance(turn, dict) and isinstance(turn.get("id"), str):
        return turn["id"] == turn_id

    return "turn/completed" == method


def optional_string(value: Any) -> str | None:
    return value.strip() if isinstance(value, str) and value.strip() else None


def require_string(value: Any, field_name: str) -> str:
    if not isinstance(value, str) or not value.strip():
        raise RuntimeError(f"{field_name} is required.")
    return value.strip()


def require_user_id(value: Any, field_name: str) -> int:
    if isinstance(value, str) and value.isdigit():
        return int(value)
    if isinstance(value, int) and value > 0:
        return value
    raise RuntimeError(f"{field_name} must be a positive integer.")


def require_nested_string(payload: Any, *path: str) -> str:
    current = payload
    for segment in path:
        if not isinstance(current, dict):
            raise RuntimeError(f"Missing {'.'.join(path)} in response payload.")
        current = current.get(segment)
    if not isinstance(current, str) or not current:
        raise RuntimeError(f"Missing {'.'.join(path)} in response payload.")
    return current


def clear_auth_json(codex_home: Path) -> None:
    path = auth_file_path(codex_home)
    if path.exists():
        path.unlink()


def normalize_account_payload(payload: Any) -> dict[str, Any]:
    if not isinstance(payload, dict):
        return {
            "authMode": None,
            "email": None,
            "planType": None,
            "type": None,
        }

    account = payload.get("account")
    if not isinstance(account, dict):
        return {
            "authMode": None,
            "email": None,
            "planType": None,
            "type": None,
        }

    account_type = optional_string(account.get("type"))
    email = optional_string(account.get("email"))
    plan_type = optional_string(account.get("planType"))

    return {
        "authMode": account_type,
        "email": email,
        "planType": plan_type,
        "type": account_type,
    }


def normalize_rate_limits_payload(payload: Any) -> dict[str, Any]:
    if not isinstance(payload, dict):
        return {}
    rate_limits = payload.get("rateLimits")
    return rate_limits if isinstance(rate_limits, dict) else payload


def normalize_models_payload(payload: Any) -> list[dict[str, Any]]:
    if not isinstance(payload, dict):
        return []
    data = payload.get("data")
    return data if isinstance(data, list) else []


def select_default_model(payload: Any) -> str | None:
    models = normalize_models_payload(payload)
    for model in models:
        if isinstance(model, dict) and model.get("isDefault") and isinstance(model.get("model"), str):
            return model["model"]
    if models and isinstance(models[0], dict) and isinstance(models[0].get("model"), str):
        return models[0]["model"]
    return None


STATE = RuntimeState()


class RuntimeHandler(BaseHTTPRequestHandler):
    server_version = "CodexWPSidecar/0.1"

    def do_GET(self) -> None:  # noqa: N802
        self._dispatch()

    def do_POST(self) -> None:  # noqa: N802
        self._dispatch()

    def log_message(self, format: str, *args: Any) -> None:  # noqa: A003
        return

    def _dispatch(self) -> None:
        try:
            parsed = urlparse(self.path)

            if "GET" == self.command and parsed.path in {"/ping", "/healthz"}:
                self._json_response(
                    {
                        "ok": True,
                        "service": "codex-wp-sidecar",
                        "version": "0.1.0",
                        "codexBin": CODEX_BIN,
                    }
                )
                return

            self._ensure_local_client()
            self._ensure_authorized()

            if "POST" == self.command and "/v1/login/start" == parsed.path:
                payload = self._read_json_body()
                wp_user_id = require_user_id(payload.get("wpUserId"), "wpUserId")
                self._json_response(STATE.start_login(wp_user_id))
                return

            if "GET" == self.command and "/v1/login/status" == parsed.path:
                query = parse_qs(parsed.query)
                wp_user_id = require_user_id(query.get("wpUserId", [None])[0], "wpUserId")
                auth_session_id = require_string(query.get("authSessionId", [None])[0], "authSessionId")
                payload, status = STATE.login_status(wp_user_id, auth_session_id)
                self._json_response(payload, status=status)
                return

            if "GET" == self.command and "/v1/account/snapshot" == parsed.path:
                query = parse_qs(parsed.query)
                wp_user_id = require_user_id(query.get("wpUserId", [None])[0], "wpUserId")
                self._json_response(STATE.account_snapshot(wp_user_id))
                return

            if "POST" == self.command and "/v1/responses/text" == parsed.path:
                payload = self._read_json_body()
                wp_user_id = require_user_id(payload.get("wpUserId"), "wpUserId")
                self._json_response(STATE.generate_text(wp_user_id, payload))
                return

            if "POST" == self.command and "/v1/session/clear" == parsed.path:
                payload = self._read_json_body()
                wp_user_id = require_user_id(payload.get("wpUserId"), "wpUserId")
                STATE.clear_session(wp_user_id)
                self._json_response({"ok": True})
                return

            self._json_error("not_found", "Runtime route not found.", HTTPStatus.NOT_FOUND)
        except HttpError as exc:
            self._json_error(exc.code, str(exc), exc.status)
        except TimeoutError as exc:
            self._json_error("request_timeout", str(exc), HTTPStatus.GATEWAY_TIMEOUT)
        except JsonRpcError as exc:
            self._json_error("runtime_error", str(exc), HTTPStatus.BAD_GATEWAY)
        except Exception as exc:
            self._json_error("runtime_error", str(exc), HTTPStatus.BAD_GATEWAY)

    def _ensure_local_client(self) -> None:
        client_host = (self.client_address[0] or "").strip()
        if client_host not in {"127.0.0.1", "::1"}:
            raise HttpError(
                "forbidden",
                "The Codex sidecar only accepts local connections.",
                HTTPStatus.FORBIDDEN,
            )

    def _ensure_authorized(self) -> None:
        if not AUTH_TOKEN:
            raise HttpError(
                "unauthorized",
                "The Codex sidecar bearer token is not configured.",
                HTTPStatus.UNAUTHORIZED,
            )

        header = self.headers.get("authorization", "")
        if not header.startswith("Bearer "):
            raise HttpError("unauthorized", "Missing bearer token.", HTTPStatus.UNAUTHORIZED)

        supplied = header[7:]
        if not hmac.compare_digest(supplied, AUTH_TOKEN):
            raise HttpError("unauthorized", "Invalid bearer token.", HTTPStatus.UNAUTHORIZED)

    def _read_json_body(self) -> dict[str, Any]:
        length = int(self.headers.get("content-length", "0") or "0")
        raw = self.rfile.read(length) if length else b""
        if not raw:
            return {}
        payload = json.loads(raw.decode("utf-8"))
        if not isinstance(payload, dict):
            raise RuntimeError("Request body must be a JSON object.")
        return payload

    def _json_response(self, payload: dict[str, Any], status: int = HTTPStatus.OK) -> None:
        encoded = json.dumps(payload, indent=2).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)

    def _json_error(self, code: str, message: str, status: int) -> None:
        self._json_response({"error": {"code": code, "message": message}}, status=status)


def main() -> None:
    ensure_storage_root()
    server = ThreadingHTTPServer((HOST, PORT), RuntimeHandler)
    server.serve_forever()


if __name__ == "__main__":
    main()
