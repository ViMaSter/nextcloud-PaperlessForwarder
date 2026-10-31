import hashlib
import json
import re
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlparse

TOKEN = "e2e-token"
documents = {}
tasks = {}
uploads = []


class Handler(BaseHTTPRequestHandler):
    def reply(self, status, payload):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def authorized(self):
        if self.headers.get("Authorization") != f"Token {TOKEN}":
            self.reply(401, {"detail": "unauthorized"})
            return False
        return True

    def do_GET(self):
        url = urlparse(self.path)
        if url.path == "/uploads":
            return self.reply(200, uploads)
        if not self.authorized():
            return
        if url.path == "/api/tasks/":
            task = tasks.get(parse_qs(url.query).get("task_id", [""])[0])
            return self.reply(200, [task] if task else [])
        match = re.fullmatch(r"/api/documents/(\d+)/", url.path)
        if match and int(match.group(1)) in documents.values():
            return self.reply(200, {"id": int(match.group(1)), "deleted_at": None})
        self.reply(404, {"detail": "not found"})

    def do_POST(self):
        if urlparse(self.path).path != "/api/documents/post_document/":
            return self.reply(404, {"detail": "not found"})
        if not self.authorized():
            return
        body = self.rfile.read(int(self.headers.get("Content-Length", "0")))
        boundary = body.split(b"\r\n", 1)[0]
        content = body.split(b"\r\n\r\n", 1)[-1].rsplit(b"\r\n" + boundary, 1)[0]
        digest = hashlib.sha256(content).hexdigest()
        task_id = str(uuid.uuid4())
        if digest in documents:
            tasks[task_id] = {
                "task_id": task_id,
                "status": "FAILURE",
                "result_data": {"duplicate_of": documents[digest], "duplicate_in_trash": False},
            }
        else:
            documents[digest] = len(documents) + 1
            tasks[task_id] = {
                "task_id": task_id,
                "status": "SUCCESS",
                "result_data": {"document_id": documents[digest]},
            }
        uploads.append({"task_id": task_id, "status": tasks[task_id]["status"]})
        self.reply(200, task_id)

    def log_message(self, *args):
        pass


ThreadingHTTPServer(("0.0.0.0", 8000), Handler).serve_forever()
