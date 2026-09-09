#!/usr/bin/env python3
"""Local-only preview server for Kosipark V2."""
import http.server
import json
import os
import re
import socketserver
import sys
import urllib.parse

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "public_html")

ROUTES = [
    (re.compile(r"^/$"), lambda m: ("/Kosipark.dc.html", "")),
    (re.compile(r"^/accommodation/?$"), lambda m: ("/Accommodation.dc.html", "")),
    (re.compile(r"^/accommodation/([a-z0-9-]+)/?$"), lambda m: ("/Room.dc.html", "room=" + m.group(1))),
    (re.compile(r"^/availability/?$"), lambda m: ("/Calendar.dc.html", "")),
    (re.compile(r"^/book/calendar/?$"), lambda m: ("/Calendar.dc.html", "")),
    (re.compile(r"^/terms/?$"), lambda m: ("/Terms.dc.html", "")),
    (re.compile(r"^/privacy/?$"), lambda m: ("/Privacy.dc.html", "")),
    (re.compile(r"^/attractions/?$"), lambda m: ("/Attractions.dc.html", "")),
    (re.compile(r"^/gallery/?$"), lambda m: ("/Gallery.dc.html", "")),
    (re.compile(r"^/contact/?$"), lambda m: ("/Contact.dc.html", "")),
]


class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=ROOT, **kwargs)

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        path, query = parsed.path, parsed.query

        if path.startswith("/api/gp"):
            body = json.dumps({"Unconfigured": True}).encode()
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return

        candidate = os.path.normpath(os.path.join(ROOT, path.lstrip("/")))
        if candidate.startswith(ROOT) and path != "/" and os.path.isfile(candidate):
            return super().do_GET()

        for pattern, build in ROUTES:
            match = pattern.match(path)
            if match:
                target, extra = build(match)
                merged = "&".join(value for value in (extra, query) if value)
                self.path = target + ("?" + merged if merged else "")
                return super().do_GET()

        self.path = "/404.html"
        return super().do_GET()


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8772
    socketserver.ThreadingTCPServer.allow_reuse_address = True
    socketserver.ThreadingTCPServer.daemon_threads = True
    with socketserver.ThreadingTCPServer(("127.0.0.1", port), Handler) as server:
        print(f"Kosipark V2 preview: http://localhost:{port}", flush=True)
        server.serve_forever()
