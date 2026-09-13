#!/usr/bin/env python3
"""Local-only preview server for Kosipark V2."""
import http.server
import base64
import json
import os
import re
import socketserver
import sys
import urllib.parse
import urllib.error
import urllib.request

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "public_html")
GP_BASE_URL = os.environ.get("GP_BE_BASE_URL", "https://beapi.guestpoint.dev/api/v1").rstrip("/")
GP_API_KEY = os.environ.get("GP_API_KEY", "").strip()
GP_PROPERTY_ID = os.environ.get("GP_PROPERTY_ID", "").strip()
GP_HAR_FILE = os.environ.get("GP_HAR_FILE", "").strip()

GP_GET_ROUTES = {"availabilities", "bestavailablerates", "beprofilefields"}

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


def _har_response(har, endpoint):
    """Return the final successful GET response for an admin API endpoint."""
    matches = [
        entry for entry in har.get("log", {}).get("entries", [])
        if entry.get("request", {}).get("method") == "GET"
        and endpoint in entry.get("request", {}).get("url", "")
        and entry.get("response", {}).get("status") == 200
        and entry.get("response", {}).get("content", {}).get("text")
    ]
    if not matches:
        return []
    content = matches[-1]["response"]["content"]
    text = content["text"]
    if content.get("encoding") == "base64":
        text = base64.b64decode(text).decode("utf-8")
    return json.loads(text)


def load_har_snapshot(path):
    """Build a small Booking Engine-shaped, read-only snapshot from the HAR."""
    if not path:
        return None
    with open(path, "r", encoding="utf-8") as handle:
        har = json.load(handle)
    packages = _har_response(har, "GetPackageInfo3s")
    package_days = _har_response(har, "GetPackageDays")
    inventory = _har_response(har, "GetInventoryInfo")
    if not packages or not package_days or not inventory:
        raise ValueError("HAR is missing package, package-day, or inventory data")

    inventory_by_day = {
        (row.get("RoomTypeID"), str(row.get("Date", ""))[:10]): row
        for row in inventory
    }
    days_by_package = {}
    for row in package_days:
        days_by_package.setdefault(row.get("PackageID"), []).append(row)

    room_types = []
    for package in packages:
        room_id = package.get("RoomTypeID")
        rates = []
        availability = []
        for day in sorted(days_by_package.get(package.get("PackageID"), []), key=lambda x: x.get("Date", "")):
            date = str(day.get("Date", ""))[:10]
            stock = inventory_by_day.get((room_id, date), {})
            for_sale = int(stock.get("AvailableInventory", 0) or 0)
            stop_sell = bool(day.get("StopSellRate") or stock.get("StopSell"))
            availability.append({"Date": date, "ForSale": for_sale, "Closed": stop_sell})
            rates.append({
                "Date": date,
                "SellRate": str(day.get("Rate", 0)),
                "Closed": stop_sell,
                "ClosedToArrival": bool(day.get("Cta")),
                "ClosedToDeparture": bool(day.get("Ctd")),
                "MinStayArrival": int(day.get("MinimumStay", 1) or 1),
            })
        room_types.append({
            "Id": room_id,
            "Name": package.get("Name") or "Test category",
            "MaxOccupancy": package.get("GuestsIncludedInPrice") or 2,
            "Availabilities": availability,
            "RatePlans": [{
                "Id": package.get("PackageID"),
                "Name": "Standard rate",
                "Rates": rates,
            }],
        })

    # Keep the category used in the earlier preview first and deterministic.
    room_types.sort(key=lambda room: (room.get("Name") != "Single Room", room.get("Name", "")))
    return room_types


try:
    GP_HAR_ROOM_TYPES = load_har_snapshot(GP_HAR_FILE)
except (OSError, ValueError, json.JSONDecodeError) as error:
    print(f"GuestPoint HAR snapshot could not be loaded: {error}", file=sys.stderr, flush=True)
    GP_HAR_ROOM_TYPES = None


class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=ROOT, **kwargs)

    def send_json(self, status, payload):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def guestpoint_route(self, method):
        parsed = urllib.parse.urlparse(self.path)
        prefix = "/api/gp/properties/self/"
        if not parsed.path.startswith(prefix):
            self.send_json(404, {"Error": {"Message": "Unknown GuestPoint route."}})
            return

        endpoint = parsed.path[len(prefix):].strip("/")
        allowed = (
            (method == "GET" and (endpoint in GP_GET_ROUTES or endpoint.startswith("promocodes/")))
            or (method == "POST" and endpoint == "extras")
        )
        if not allowed:
            self.send_json(405, {"Error": {"Message": "That GuestPoint operation is not available in V2."}})
            return

        if method == "GET" and GP_HAR_ROOM_TYPES and endpoint in {"availabilities", "bestavailablerates"}:
            query = urllib.parse.parse_qs(parsed.query)
            wanted = (query.get("roomTypes") or query.get("roomType") or [""])[0]
            start = (query.get("arrivalDate") or query.get("fromDate") or [""])[0]
            end = (query.get("departureDate") or query.get("toDate") or [""])[0]
            rooms = [room for room in GP_HAR_ROOM_TYPES if not wanted or room.get("Id") == wanted]

            if endpoint == "availabilities":
                shaped = []
                for room in rooms:
                    copy = dict(room)
                    copy["Availabilities"] = [
                        day for day in room.get("Availabilities", [])
                        if (not start or day["Date"] >= start) and (not end or day["Date"] <= end)
                    ]
                    copy["RatePlans"] = [dict(plan, Rates=[
                        day for day in plan.get("Rates", [])
                        if (not start or day["Date"] >= start) and (not end or day["Date"] <= end)
                    ]) for plan in room.get("RatePlans", [])]
                    shaped.append(copy)
                self.send_json(200, {
                    "DataSource": "guestpoint-har-snapshot",
                    "Properties": [{"Id": "har-test-property", "Name": "KosiPark test snapshot", "RoomTypes": shaped}],
                })
                return

            properties = []
            for room in rooms:
                plan = (room.get("RatePlans") or [{}])[0]
                stock = {day["Date"]: day for day in room.get("Availabilities", [])}
                days = {}
                for rate in plan.get("Rates", []):
                    date = rate["Date"]
                    if (start and date < start) or (end and date > end):
                        continue
                    available = int(stock.get(date, {}).get("ForSale", 0) or 0)
                    days[date] = {
                        "BestRate": rate.get("SellRate"),
                        "RoomAvailable": available > 0 and not rate.get("Closed"),
                        "Available": available,
                        "MinimumStay": rate.get("MinStayArrival", 1),
                        "ClosedToArrival": bool(rate.get("ClosedToArrival")),
                        "ClosedToDeparture": bool(rate.get("ClosedToDeparture")),
                    }
                properties.append({"RoomTypeId": room.get("Id"), "Days": days})
            self.send_json(200, {"DataSource": "guestpoint-har-snapshot", "Properties": properties})
            return

        if not GP_API_KEY or not GP_PROPERTY_ID:
            self.send_json(200, {"Unconfigured": True})
            return

        target = (
            GP_BASE_URL + "/properties/" + urllib.parse.quote(GP_PROPERTY_ID, safe="")
            + "/" + endpoint + (("?" + parsed.query) if parsed.query else "")
        )
        data = None
        if method == "POST":
            length = min(int(self.headers.get("Content-Length", "0") or 0), 256 * 1024)
            data = self.rfile.read(length) if length else b"{}"

        request = urllib.request.Request(
            target,
            data=data,
            method=method,
            headers={
                "X-API-KEY": GP_API_KEY,
                "Accept": "application/json",
                "Content-Type": "application/json",
                "User-Agent": "Kosipark-V2-Local-Preview/1.0",
            },
        )
        try:
            with urllib.request.urlopen(request, timeout=20) as response:
                body = response.read()
                status = response.status
        except urllib.error.HTTPError as error:
            body = error.read()
            status = error.code
        except (urllib.error.URLError, TimeoutError):
            self.send_json(502, {"Error": {"Message": "The availability service is not responding."}})
            return

        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self):
        if urllib.parse.urlparse(self.path).path.startswith("/api/gp"):
            return self.guestpoint_route("POST")
        self.send_json(405, {"Error": {"Message": "Method not allowed."}})

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        path, query = parsed.path, parsed.query

        if path.startswith("/api/gp"):
            return self.guestpoint_route("GET")

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
