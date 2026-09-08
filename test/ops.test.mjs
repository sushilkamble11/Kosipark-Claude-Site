import { chromium } from "playwright";
import { spawn } from "node:child_process";
import { setTimeout as sleep } from "node:timers/promises";

const port = 8833;
const server = spawn("python3", ["dev-server.py", String(port)], {
  cwd: new URL("..", import.meta.url).pathname,
  stdio: "ignore",
});
process.on("exit", () => server.kill());

const pass = (condition, label) => {
  if (!condition) throw new Error(label);
  console.log("PASS  " + label);
};

await sleep(900);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const errors = [];
page.on("pageerror", error => errors.push(error.message));

await page.goto(`http://localhost:${port}/ops/`, { waitUntil: "networkidle" });
pass(await page.locator("#username").isVisible(), "operations requires a username");
pass(await page.getByLabel("Password", { exact: true }).isVisible(), "operations requires a password");
const plaque = await page.locator(".login-brand").evaluate(element => getComputedStyle(element).backgroundColor);
pass(plaque !== "rgba(0, 0, 0, 0)", "transparent logo sits on a light plaque");

await page.getByRole("button", { name: "Forgot password?" }).click();
pass(await page.getByRole("heading", { name: "Reset password" }).isVisible(), "password recovery is available");
await page.getByRole("button", { name: "Back to sign in" }).click();
await page.locator("#username").fill("admin");
await page.getByLabel("Password", { exact: true }).fill("demo");
await page.getByRole("button", { name: "Open operations" }).click();
await page.locator("#app-view").waitFor({ state: "visible" });
pass(await page.getByText("Connections & secrets").isVisible(), "authenticated dashboard opens");
pass(await page.getByRole("button", { name: /AI diagnostic/ }).isVisible(), "AI diagnostics has a dedicated guarded workspace");
await page.screenshot({ path: "/tmp/kosipark-ops-dashboard.png", fullPage: true });
pass(errors.length === 0, "operations interactions have no page errors");

await browser.close();
server.kill();
console.log("ops.test.mjs: all assertions passed");
