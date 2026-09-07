// Availability counts must come from the API, per room type — not a shared
// number. Two categories on the same nights should be able to differ.
import { spawn } from "node:child_process";
import { setTimeout as sleep } from "node:timers/promises";
const srv = spawn("python3", ["dev-server.py", "8756"], { cwd: new URL("..", import.meta.url).pathname, stdio: "ignore" });
await sleep(700);
globalThis.window = { KOSIPARK_MOCK: true };
globalThis.location = { protocol: "http:", hostname: "localhost" };
globalThis.localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
const gp = await import("../public_html/guestpoint.js");
gp.CONFIG.mock = true;

const span = { fromDate: "2026-07-10", toDate: "2026-07-17" };
const cedar = await gp.availabilityCalendar({ room: "cedar-cabin", ...span });
const unp   = await gp.availabilityCalendar({ room: "unpowered-site", ...span });

let fails = 0;
const line = (ok, label, extra = "") => { if (!ok) fails++; console.log(`${ok ? "PASS" : "FAIL"}  ${label}${extra ? "  " + extra : ""}`); };

const dates = Object.keys(cedar).sort();
line(dates.length > 0, "calendar returns nights", `${dates.length} nights`);
line(dates.every(d => typeof cedar[d].count === "number"), "every night carries a real count");
line(dates.some(d => typeof cedar[d].rate === "number" && cedar[d].rate > 0), "nights carry a nightly rate");

const cedarCounts = dates.map(d => cedar[d].count).join(",");
const unpCounts   = dates.map(d => (unp[d] || {}).count).join(",");
line(cedarCounts !== unpCounts, "counts differ between categories",
     `cedar=[${cedarCounts}] unpowered=[${unpCounts}]`);
line(dates.some(d => cedar[d].count !== cedar[dates[0]].count), "counts vary night to night");

srv.kill();
console.log(fails === 0 ? "\nAll checks passed." : `\n${fails} failed.`);
process.exit(fails === 0 ? 0 : 1);
