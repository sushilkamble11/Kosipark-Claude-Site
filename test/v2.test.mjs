import assert from "node:assert/strict";
import fs from "node:fs";

const read = path => fs.readFileSync(new URL("../" + path, import.meta.url), "utf8");
const bookingBar = read("public_html/BookingBar.dc.html");
const nav = read("public_html/SiteNav.dc.html");
const routes = read("dev-server.py");
const proxy = read("public_html/api/gp/index.php");
const footer = read("public_html/SiteFooter.dc.html");
const terms = read("public_html/Terms.dc.html");
const bookingConfig = read("public_html/booking-config.js");
const home = read("public_html/Kosipark.dc.html");
const accommodation = read("public_html/Accommodation.dc.html");
const room = read("public_html/Room.dc.html");

assert.match(bookingBar, /startDate/);
assert.match(bookingBar, /numAdults/);
assert.match(bookingBar, /numChildren/);
assert.match(bookingBar, /numNights/);
assert.doesNotMatch(nav, /data-cart=/);
assert.match(nav, /label: "Availability"/);
assert.doesNotMatch(routes, /Checkout\.dc\.html/);
assert.doesNotMatch(routes, /Manage\.dc\.html/);
assert.doesNotMatch(proxy, /['"]reservations/);
assert.doesNotMatch(proxy, /portal\/update/);
assert.doesNotMatch(footer, /href="\/manage"/);
assert.doesNotMatch(terms, /href="\/manage"/);
assert.match(bookingConfig, /coachmans-eden\.bookus\.direct\/booking-details/);
assert.match(home, /src="\/booking-config\.js"/);
assert.match(accommodation, /src="\/booking-config\.js"/);
assert.match(room, /src="\/booking-config\.js"/);

console.log("Kosipark V2 booking-boundary checks passed.");
