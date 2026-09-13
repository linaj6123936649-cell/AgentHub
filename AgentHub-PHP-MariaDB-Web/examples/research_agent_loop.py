#!/usr/bin/env python3
"""Polling and direct data-channel loop for an external Agent.

Standard-library only. The Agent opens a small HTTP receiver for files. AgentHub
streams each attachment directly to that receiver; AgentHub does not keep a
copy of the file. Set AGENTHUB_RECEIVE_URL to an address reachable from Hub.
"""

import hmac
import http.server
import json
import os
import pathlib
import re
import secrets
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime

BASE_URL = os.environ.get("AGENTHUB_URL", "http://127.0.0.1:8780/")
AGENT_NAME = os.environ.get("AGENTHUB_AGENT_NAME", "Research-Agent")
POLL_INTERVAL = max(1.0, float(os.environ.get("AGENTHUB_POLL_INTERVAL", "3")))
START_FROM = os.environ.get("AGENTHUB_START_FROM", "latest").lower()

SCRIPT_DIR = pathlib.Path(__file__).resolve().parent
INBOX_QUEUE = pathlib.Path(os.environ.get("AGENTHUB_INBOX_QUEUE", SCRIPT_DIR / "inbox_queue.json"))
REPLY_QUEUE = pathlib.Path(os.environ.get("AGENTHUB_REPLY_QUEUE", SCRIPT_DIR / "reply_queue.json"))
LAST_ID_FILE = pathlib.Path(os.environ.get("AGENTHUB_LAST_ID_FILE", SCRIPT_DIR / "last_id.txt"))
RECEIVED_FILES_DIR = pathlib.Path(os.environ.get("AGENTHUB_RECEIVED_FILES", SCRIPT_DIR / "inbox_files"))
RECEIVE_HOST = os.environ.get("AGENTHUB_RECEIVE_HOST", "0.0.0.0")
RECEIVE_PORT = int(os.environ.get("AGENTHUB_RECEIVE_PORT", "8766"))
RECEIVE_URL = os.environ.get("AGENTHUB_RECEIVE_URL", "").strip()
TRANSFER_TOKEN = os.environ.get("AGENTHUB_TRANSFER_TOKEN", "").strip() or secrets.token_urlsafe(32)
MAX_FILE_BYTES = 100 * 1024 * 1024


def now():
    return datetime.now().strftime("%H:%M:%S")


def url(path):
    return BASE_URL.rstrip("/") + "/" + path.lstrip("/")


def api(method, path, payload=None):
    data = None if payload is None else json.dumps(payload, ensure_ascii=False).encode("utf-8")
    headers = {"Accept": "application/json"}
    if payload is not None:
        headers["Content-Type"] = "application/json; charset=utf-8"
    request = urllib.request.Request(url(path), data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            raw = response.read().decode("utf-8")
            return json.loads(raw) if raw else None
    except urllib.error.HTTPError as error:
        detail = error.read().decode("utf-8", "replace")
        raise RuntimeError(f"HTTP {error.code}: {detail or error.reason}") from error


def poll(after):
    query = urllib.parse.urlencode({"agent": AGENT_NAME, "after": after})
    return api("GET", "/api/messages?" + query)


def register():
    """Register this Agent and publish its directly reachable data channel."""
    if not RECEIVE_URL:
        raise RuntimeError("set AGENTHUB_RECEIVE_URL to the Agent data-channel URL")
    parsed = urllib.parse.urlsplit(RECEIVE_URL)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        raise RuntimeError("AGENTHUB_RECEIVE_URL must be a full http or https URL")
    return api("POST", "/api/agents", {
        "name": AGENT_NAME,
        "transfer_url": RECEIVE_URL,
        "transfer_token": TRANSFER_TOKEN,
    })


def safe_filename(name):
    name = pathlib.PurePath(str(name).replace("\\", "/")).name
    name = re.sub(r'[\x00-\x1f\x7f<>:"/|?*]', "_", name).strip()
    return name[:180] or "received.bin"


def receive_path(transfer_id, name):
    return RECEIVED_FILES_DIR / transfer_id / safe_filename(name)


class ReceiveHandler(http.server.BaseHTTPRequestHandler):
    server_version = "AgentHubDataChannel/1.0"

    def log_message(self, format_string, *args):
        return

    def send_json(self, status, value):
        body = json.dumps(value, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        received_path = urllib.parse.urlsplit(RECEIVE_URL).path or "/agenthub/receive"
        if self.path.split("?", 1)[0] != received_path:
            self.send_json(404, {"error": "not found"})
            return
        supplied = urllib.parse.unquote(self.headers.get("X-AgentHub-Token", ""))
        if not hmac.compare_digest(supplied, TRANSFER_TOKEN):
            self.send_json(401, {"error": "invalid data-channel token"})
            return
        transfer_id = self.headers.get("X-AgentHub-Transfer-Id", "")
        if not re.fullmatch(r"[a-f0-9]{32}", transfer_id):
            self.send_json(400, {"error": "invalid transfer id"})
            return
        try:
            size = int(self.headers.get("Content-Length", "-1"))
        except ValueError:
            size = -1
        if size < 0 or size > MAX_FILE_BYTES:
            self.send_json(400, {"error": "invalid content length"})
            return
        name = safe_filename(urllib.parse.unquote(self.headers.get("X-AgentHub-Filename", "received.bin")))
        destination = receive_path(transfer_id, name)
        destination.parent.mkdir(parents=True, exist_ok=True)
        temporary = destination.with_name(destination.name + ".part")
        remaining = size
        try:
            with temporary.open("wb") as output:
                while remaining:
                    chunk = self.rfile.read(min(1024 * 1024, remaining))
                    if not chunk:
                        raise OSError("unexpected end of upload")
                    output.write(chunk)
                    remaining -= len(chunk)
            os.replace(temporary, destination)
        except Exception as error:
            try:
                temporary.unlink()
            except OSError:
                pass
            self.send_json(500, {"error": str(error)})
            return
        self.send_json(201, {"ok": True, "transfer_id": transfer_id, "name": name, "size": size})


def start_receive_server():
    parsed = urllib.parse.urlsplit(RECEIVE_URL)
    if not RECEIVE_URL or not parsed.netloc:
        raise RuntimeError("set AGENTHUB_RECEIVE_URL before starting the data channel")
    receive_path = parsed.path or "/agenthub/receive"
    RECEIVED_FILES_DIR.mkdir(parents=True, exist_ok=True)
    server = http.server.ThreadingHTTPServer((RECEIVE_HOST, RECEIVE_PORT), ReceiveHandler)
    thread = threading.Thread(target=server.serve_forever, name="agenthub-data-channel", daemon=True)
    thread.start()
    print(f"[{now()}] Data channel listening on {RECEIVE_HOST}:{RECEIVE_PORT}{receive_path}", flush=True)
    return server


def initialize_cursor():
    if LAST_ID_FILE.exists():
        try:
            return max(0, int(LAST_ID_FILE.read_text(encoding="utf-8").strip()))
        except (OSError, ValueError):
            pass
    if START_FROM not in {"latest", "0"}:
        raise ValueError("AGENTHUB_START_FROM must be latest or 0")
    if START_FROM == "0":
        return 0
    return max((message["id"] for message in poll(0)), default=0)


def write_json_atomic(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(json.dumps(value, ensure_ascii=False, indent=2), encoding="utf-8")
    temporary.replace(path)


def save_last_id(last_id):
    LAST_ID_FILE.parent.mkdir(parents=True, exist_ok=True)
    temporary = LAST_ID_FILE.with_suffix(LAST_ID_FILE.suffix + ".tmp")
    temporary.write_text(str(last_id), encoding="utf-8")
    temporary.replace(LAST_ID_FILE)


def add_local_paths(message):
    for attachment in message.get("attachments", []):
        if not isinstance(attachment, dict):
            continue
        transfer_id = str(attachment.get("transfer_id", ""))
        name = str(attachment.get("name", "received.bin"))
        path = receive_path(transfer_id, name) if re.fullmatch(r"[a-f0-9]{32}", transfer_id) else None
        attachment["local_path"] = str(path) if path and path.is_file() else ""
        attachment["received"] = bool(path and path.is_file())
    return message


def append_inbox(message):
    queue = []
    if INBOX_QUEUE.exists():
        try:
            queue = json.loads(INBOX_QUEUE.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            queue = []
    queue.append(add_local_paths(message))
    write_json_atomic(INBOX_QUEUE, queue)


def send_reply(target, content):
    target = str(target).strip()
    if target in {"*", "all", "@all"}:
        mention, destination = "@all", "*"
    else:
        mention = target if target.startswith("@") else "@" + target
        destination = mention[1:]
    result = api("POST", "/api/messages", {"sender": AGENT_NAME, "target": destination, "content": f"{mention} {content}"})
    print(f"[{now()}] Sent reply to {mention}: ID={result.get('id')}", flush=True)


def process_reply_queue():
    if not REPLY_QUEUE.exists():
        return
    try:
        replies = json.loads(REPLY_QUEUE.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return
    if not replies:
        return
    failed = []
    for reply in replies:
        try:
            send_reply(reply["target"], reply["content"])
        except Exception as error:
            print(f"[{now()}] Failed to send reply: {error}", flush=True)
            failed.append(reply)
    write_json_atomic(REPLY_QUEUE, failed)


def main():
    server = start_receive_server()
    try:
        print(f"[{now()}] Starting {AGENT_NAME} polling loop at {BASE_URL}", flush=True)
        register()
        last_id = initialize_cursor()
        while True:
            try:
                for message in poll(last_id):
                    last_id = max(last_id, message["id"])
                    if message["sender"] == AGENT_NAME:
                        continue
                    append_inbox(message)
                    print(f"[{now()}] New message #{message['id']} from {message['sender']}", flush=True)
                save_last_id(last_id)
                process_reply_queue()
            except Exception as error:
                print(f"[{now()}] Error: {error}", flush=True)
            time.sleep(POLL_INTERVAL)
    finally:
        server.shutdown()


if __name__ == "__main__":
    main()
