import { chromium } from "playwright";
import { spawn } from "node:child_process";
import { setTimeout as sleep } from "node:timers/promises";

const port = 8822;
const server = spawn("python3", ["dev-server.py", String(port)], {
  cwd: new URL("..", import.meta.url).pathname,
  stdio: "ignore",
});
process.on("exit", () => server.kill());

await sleep(900);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
const pageErrors = [];
page.on("pageerror", error => pageErrors.push(error.message));
const pass = (condition, label) => {
  if (!condition) throw new Error(label);
  console.log("PASS  " + label);
};

await page.goto(`http://localhost:${port}/manage`, { waitUntil: "networkidle" });
pass(page.url().endsWith("/manage"), "manage page identity is correct");
pass((await page.locator("body").innerText()).includes("Manage your booking"), "manage page is not blank");
await page.getByPlaceholder("KTP-48213").fill("1");
await page.getByPlaceholder("Nguyen").fill("1");
await page.getByPlaceholder("0412 345 678").fill("1");
await page.getByRole("button", { name: "Email me a secure code" }).click();
await page.getByText("Demo verification code: 123456").waitFor({ timeout: 5000 });
pass(await page.getByText("Demo verification code: 123456").isVisible(), "demo mode shows the test-only verification code");
await page.screenshot({ path: "/tmp/kosipark-manage-email-otp.png", fullPage: false });
await page.getByLabel("Six-digit email code").fill("123456");
await page.getByRole("button", { name: "Verify and view booking" }).click();
await page.getByText("Booking reference: 1").waitFor({ timeout: 5000 });
pass(await page.getByText("Booking reference: 1").isVisible(), "1 / 1 / 1 plus OTP opens the demo booking");

for (const section of ["Amend booking", "Guest numbers", "Guest details", "Arrival & vehicles", "View extras", "Special request"]) {
  await page.getByRole("button", { name: section, exact: true }).click();
  const visiblePortalText = await page.locator("body").innerText();
  pass(!/verification code[^.]*\b(?:mobile|phone)\b|\b(?:mobile|phone)\b[^.]*verification code/i.test(visiblePortalText), section + " has no mobile verification wording");
}

await page.getByRole("button", { name: "Amend booking" }).click();
pass(await page.getByRole("button", { name: "Change dates" }).isEnabled(), "amendment choice comes before acceptance");
pass(await page.getByRole("button", { name: "Guest numbers" }).isVisible(), "guest numbers is a separate portal action");
pass(await page.getByRole("button", { name: "Change guest numbers" }).count() === 0, "guest numbers is not inside Amend booking");

await page.getByRole("button", { name: "Cancel booking" }).click();
const confirmCancel = page.getByRole("button", { name: "Confirm cancellation" });
pass(!(await confirmCancel.isEnabled()), "cancellation remains locked until the final acceptance");
await page.getByRole("checkbox").check();
await confirmCancel.click();
const cancellationEmailConfirmation = page.getByText(/fresh verification code will be sent to the email held on the booking/i);
await cancellationEmailConfirmation.waitFor();
pass(await cancellationEmailConfirmation.isVisible(), "cancellation confirmation consistently uses email verification");
await cancellationEmailConfirmation.scrollIntoViewIfNeeded();
await page.screenshot({ path: "/tmp/kosipark-manage-cancellation-email-verification.png", fullPage: false });

await page.getByRole("button", { name: "View extras" }).click();
await page.getByText("Drying room access").waitFor({ timeout: 5000 });
pass(await page.getByRole("button", { name: "Add", exact: true }).count() > 0, "eligible extras have visible Add buttons");
await page.getByRole("button", { name: "Add", exact: true }).first().click();
await page.getByText(/Selected extras: \$/).waitFor({ timeout: 3000 });
pass(await page.getByText(/Selected extras: \$/).isVisible(), "selected extras show a calculated total");

await page.getByRole("button", { name: "Add another stay" }).click();
pass(await page.getByText("Go to the booking page?").isVisible(), "adding another stay warns before leaving");
await page.screenshot({ path: "/tmp/kosipark-manage-leave-warning.png", fullPage: false });
await page.setViewportSize({ width: 390, height: 844 });
const mobileDialog = await page.getByRole("alertdialog").boundingBox();
pass(!!mobileDialog && mobileDialog.x >= 0 && mobileDialog.x + mobileDialog.width <= 390, "leave warning fits a mobile screen");
await page.screenshot({ path: "/tmp/kosipark-manage-leave-warning-mobile.png", fullPage: false });
await page.setViewportSize({ width: 1280, height: 1000 });
await page.getByRole("button", { name: "Exit" }).click();
pass(!(await page.getByText("Go to the booking page?").isVisible()), "Exit keeps the current booking open");

await page.getByRole("button", { name: "Look up another booking" }).click();
pass(await page.getByText("Look up another booking?").isVisible(), "looking up another booking warns before clearing");
await page.getByRole("button", { name: "Exit" }).click();
pass(await page.getByText("Booking reference: 1").isVisible(), "Exit keeps the current booking details visible");

await page.getByRole("button", { name: "Guest numbers" }).click();
await page.getByLabel("Adults").fill("10");
await page.getByRole("button", { name: "Check capacity and price" }).click();
await page.getByText(/maximum of 6 guests|cannot be accepted online/i).first().waitFor({ timeout: 5000 });
pass(!(await page.getByRole("button", { name: "Accept price and continue" }).isVisible().catch(() => false)), "over-occupancy cannot reach acceptance");
pass(pageErrors.length === 0, "portal interactions have no page errors");

await page.getByRole("button", { name: "Look up another booking" }).click();
await page.getByRole("button", { name: "Continue" }).click();
pass(await page.getByRole("button", { name: "Email me a secure code" }).isVisible(), "Continue returns to the booking lookup form");

await browser.close();
server.kill();
console.log("manage.test.mjs: all assertions passed");
