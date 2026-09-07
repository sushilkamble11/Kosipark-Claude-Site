import { chromium } from "playwright";
import { spawn } from "node:child_process";
import { setTimeout as sleep } from "node:timers/promises";
const srv = spawn("python3", ["dev-server.py", "8744"], { cwd: "/root/kosipark-site", stdio: "ignore" });
await sleep(900);
const b = await chromium.launch();
const cart = [{ id:"x1", roomTypeId:"motorhome-site", name:"Motorhome Site", ratePlanId:"standard",
  arrival:"2026-09-18", departure:"2026-09-25", nights:7, adults:2, children:0, infants:0,
  total:488, addedAt: Date.now() }];
for (const [w,h,tag] of [[390,844,"mobile"],[1440,900,"desktop"]]) {
  const p = await b.newPage({ viewport:{width:w,height:h} });
  await p.goto("http://localhost:8744/");
  await p.evaluate(c => localStorage.setItem("kosipark-cart", JSON.stringify(c)), cart);
  await p.goto("http://localhost:8744/book/checkout", { waitUntil:"networkidle" });
  await sleep(2200);
  await p.screenshot({ path:`/root/kosipark-site/test/shots/checkout-${tag}.png`, fullPage:false });
  console.log(tag, "captured");
  await p.close();
}
await b.close(); srv.kill();
