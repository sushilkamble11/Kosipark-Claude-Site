#!/usr/bin/env python3
"""
Local preview server. Applies the same routes as public_html/.htaccess, so what
you click here is what Hostinger will serve.

    python3 dev-server.py            # http://localhost:8000
    python3 dev-server.py 8080

Mock mode turns itself on for localhost, so the whole site is clickable without
GuestPoint credentials. PHP is not run: /api/gp is answered with a 503 shaped
like the real proxy's "not configured yet" reply.
"""
import http.server, json, os, re, socketserver, sys, urllib.parse

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "public_html")
OPS_STATE = {
    "mappings": [],
    "inventory": [
        {"lock_id": "7120041", "name": "Cabin 1 · Front door", "battery": 87, "has_gateway": True},
        {"lock_id": "7120042", "name": "Cabin 2 · Front door", "battery": 64, "has_gateway": True},
    ],
    "sync": {"ttlock_at": None, "guestpoint_at": None},
    "issues": [],
    "audit": [],
}

# Mirrors the RewriteRules in public_html/.htaccess, in the same order.
ROUTES = [
    (re.compile(r"^/$"),                                 lambda m: ("/Kosipark.dc.html", "")),
    (re.compile(r"^/accommodation/?$"),                  lambda m: ("/Accommodation.dc.html", "")),
    (re.compile(r"^/accommodation/([a-z0-9-]+)/?$"),     lambda m: ("/Room.dc.html", "room=" + m.group(1))),
    (re.compile(r"^/book/?$"),                           lambda m: ("/Availability.dc.html", "")),
    (re.compile(r"^/book/calendar/?$"),                  lambda m: ("/Calendar.dc.html", "")),
    (re.compile(r"^/book/checkout/?$"),                  lambda m: ("/Checkout.dc.html", "")),
    (re.compile(r"^/manage/?$"),                         lambda m: ("/Manage.dc.html", "")),
    (re.compile(r"^/terms/?$"),                          lambda m: ("/Terms.dc.html", "")),
    (re.compile(r"^/privacy/?$"),                        lambda m: ("/Privacy.dc.html", "")),
    (re.compile(r"^/attractions/?$"),                    lambda m: ("/Attractions.dc.html", "")),
    (re.compile(r"^/gallery/?$"),                        lambda m: ("/Gallery.dc.html", "")),
    (re.compile(r"^/contact/?$"),                        lambda m: ("/Contact.dc.html", "")),
]


class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *a, **kw):
        super().__init__(*a, directory=ROOT, **kw)

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        path, query = parsed.path, parsed.query

        if path.startswith("/api/ops"):
            if "kosipark_ops_dev=1" not in self.headers.get("Cookie", ""):
                return self._json(401, b'{"ok":false,"message":"Please sign in."}')
            return self._ops_overview()

        if path.startswith("/api/gp"):
            return self._json(503, b'{"Error":{"Code":503,'
                                   b'"Message":"Booking service is not configured yet."}}')

        # An existing file always wins, exactly as the .htaccess -f check does.
        candidate = os.path.normpath(os.path.join(ROOT, path.lstrip("/")))
        if candidate.startswith(ROOT) and (os.path.isfile(candidate) or (path != "/" and os.path.isdir(candidate))):
            return super().do_GET()

        for pattern, build in ROUTES:
            m = pattern.match(path)
            if m:
                target, extra = build(m)
                merged = "&".join(x for x in (extra, query) if x)
                self.path = target + ("?" + merged if merged else "")
                return super().do_GET()

        self.path = "/404.html"
        self.send_response_only(404)
        return super().do_GET()

    def do_POST(self):
        parsed = urllib.parse.urlparse(self.path)
        if not parsed.path.startswith("/api/ops"):
            return self._json(404, b'{"ok":false,"message":"Unknown endpoint."}')
        action = urllib.parse.parse_qs(parsed.query).get("action", ["overview"])[0]
        size = min(int(self.headers.get("Content-Length", "0") or 0), 65536)
        try:
            body = json.loads(self.rfile.read(size) or b"{}")
        except json.JSONDecodeError:
            return self._json(400, b'{"ok":false,"message":"Body must be JSON."}')
        if action == "login":
            if body.get("username") != "admin" or body.get("password") != "demo":
                return self._json(401, b'{"ok":false,"message":"For this local preview use admin / demo."}')
            return self._json(200, b'{"ok":true,"csrf":"local-preview","user":{"username":"admin","role":"administrator"}}', {"Set-Cookie": "kosipark_ops_dev=1; Path=/api/ops; HttpOnly; SameSite=Strict"})
        if action == "forgot-password":
            return self._json(202, b'{"ok":true,"message":"Local preview: use admin / demo. Email delivery runs only on the hosted backend."}')
        if action == "reset-password":
            return self._json(200, b'{"ok":true,"message":"Local preview password reset simulated. Use admin / demo to sign in."}')
        if "kosipark_ops_dev=1" not in self.headers.get("Cookie", ""):
            return self._json(401, b'{"ok":false,"message":"Please sign in."}')
        if action == "logout":
            return self._json(200, b'{"ok":true}', {"Set-Cookie": "kosipark_ops_dev=; Max-Age=0; Path=/api/ops"})
        if action == "mapping":
            mapping = {
                "id": body.get("id") or os.urandom(8).hex(),
                "unit_id": str(body.get("unit_id", "")).strip(),
                "unit_name": str(body.get("unit_name", "")).strip(),
                "lock_id": str(body.get("lock_id", "")).strip(),
                "lock_name": str(body.get("lock_name", "")).strip(),
                "gate_access": bool(body.get("gate_access")),
                "updated_at": "2026-09-08T01:00:00Z",
            }
            if not mapping["unit_id"] or not mapping["unit_name"] or not mapping["lock_id"]:
                return self._json(422, b'{"ok":false,"message":"Unit name, room ID and lock ID are required."}')
            OPS_STATE["mappings"] = [m for m in OPS_STATE["mappings"] if m["id"] != mapping["id"]]
            OPS_STATE["mappings"].append(mapping)
            self._ops_audit(f'{mapping["unit_name"]} linked to {mapping["lock_name"] or mapping["lock_id"]}')
            return self._json(200, json.dumps({"ok": True, "mapping": mapping}).encode())
        if action == "sync-ttlock":
            OPS_STATE["sync"]["ttlock_at"] = "2026-09-08T01:00:00Z"
            self._ops_audit("2 locks imported")
            return self._json(200, b'{"ok":true,"count":2}')
        if action == "check-guestpoint":
            OPS_STATE["sync"]["guestpoint_at"] = "2026-09-08T01:00:00Z"
            self._ops_audit("3 changed reservations found (dry run)")
            return self._json(200, b'{"ok":true,"changed_reservations":3,"since":"2026-09-06"}')
        if action == "diagnose":
            return self._json(200, b'{"ok":true,"provider":"demo","model":"read-only preview","status":"proposal_only","text":"The selected trace should be validated before a guarded retry. No action was executed."}')
        return self._json(404, b'{"ok":false,"message":"Unknown operations action."}')

    def do_DELETE(self):
        parsed = urllib.parse.urlparse(self.path)
        query = urllib.parse.parse_qs(parsed.query)
        if not parsed.path.startswith("/api/ops") or query.get("action", [""])[0] != "mapping":
            return self._json(404, b'{"ok":false,"message":"Unknown endpoint."}')
        if "kosipark_ops_dev=1" not in self.headers.get("Cookie", ""):
            return self._json(401, b'{"ok":false,"message":"Please sign in."}')
        mapping_id = query.get("id", [""])[0]
        OPS_STATE["mappings"] = [m for m in OPS_STATE["mappings"] if m["id"] != mapping_id]
        self._ops_audit("Lock mapping removed")
        return self._json(200, b'{"ok":true}')

    def _ops_audit(self, detail):
        OPS_STATE["audit"].insert(0, {"id": os.urandom(6).hex(), "at": "2026-09-08T01:00:00Z", "detail": detail})

    def _ops_overview(self):
        payload = {"ok": True, "csrf": "local-preview", "user": {"username": "admin", "role": "administrator"}, "providers": {
            "guestpoint": {"configured": True, "key": "••••9K2A", "property": "••••31F8"},
            "ttlock": {"configured": True, "client_id": "••••82DC", "token": "••••07AB"},
            "boomgate": {"configured": False, "park_id": None, "note": "Waiting for the vendor to confirm control direction and production authentication."},
        }, **OPS_STATE}
        return self._json(200, json.dumps(payload).encode())

    def _json(self, code, body, headers=None):
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        for name, value in (headers or {}).items():
            self.send_header(name, value)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt, *args):
        sys.stderr.write("%s %s\n" % (self.address_string(), fmt % args))


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8000
    socketserver.TCPServer.allow_reuse_address = True
    with socketserver.TCPServer(("127.0.0.1", port), Handler) as httpd:
        print(f"Kosipark preview on http://localhost:{port}  (Ctrl-C to stop)")
        httpd.serve_forever()
