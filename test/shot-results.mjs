import { chromium } from "playwright";
import { spawn } from "node:child_process";
import { setTimeout as sleep } from "node:timers/promises";
const srv = spawn("python3", ["dev-server.py", "8766"], { cwd: new URL("..", import.meta.url).pathname, stdio: "ignore" });
await sleep(900);
const b = await chromium.launch();
const url = "http://localhost:8766/book?arrival=2026-10-02&departure=2026-10-05&adults=2";
for (const [w,h,tag] of [[1440,1000,"results-desktop"],[390,844,"results-mobile"]]) {
  const p = await b.newPage({ viewport:{width:w,height:h} });
  await p.goto(url, { waitUntil:"networkidle" });
  await sleep(2200);
  await p.screenshot({ path: new URL(`shots/${tag}.png`, import.meta.url).pathname, fullPage:false });
  console.log(tag);
  await p.close();
}
await b.close(); srv.kill();
