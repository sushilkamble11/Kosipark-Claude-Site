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
import http.server, os, re, socketserver, sys, urllib.parse

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "public_html")

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

        if path.startswith("/api/gp"):
            return self._json(503, b'{"Error":{"Code":503,'
                                   b'"Message":"Booking service is not configured yet."}}')

        # An existing file always wins, exactly as the .htaccess -f check does.
        candidate = os.path.normpath(os.path.join(ROOT, path.lstrip("/")))
        if candidate.startswith(ROOT) and os.path.isfile(candidate):
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

    def _json(self, code, body):
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
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
