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
await page.getByPlaceholder("Reservation number or channel booking ref").fill("1");
await page.getByPlaceholder("Nguyen").fill("1");
await page.getByRole("button", { name: "Find my booking" }).click();
await page.getByText("Booking reference: 1").waitFor({ timeout: 5000 });
pass(await page.getByText("Booking reference: 1").isVisible(), "1 / 1 / 1 opens the demo booking without OTP");

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
const cancellationConfirmation = page.getByText("Booking cancelled in GuestPoint", { exact: true });
await cancellationConfirmation.waitFor();
pass(await cancellationConfirmation.isVisible(), "verified cancellation is submitted to GuestPoint");
pass(await page.getByText("Cancelled", { exact: true }).isVisible(), "booking status changes to Cancelled after GuestPoint confirms");
await cancellationConfirmation.scrollIntoViewIfNeeded();
await page.screenshot({ path: "/tmp/kosipark-manage-cancellation-email-verification.png", fullPage: false });

// Re-open the fixture booking so the remaining independent portal controls can
// be exercised after the cancellation state correctly disables all writes.
await page.getByRole("button", { name: "Look up another booking" }).click();
await page.getByRole("button", { name: "Continue" }).click();
await page.getByPlaceholder("Reservation number or channel booking ref").fill("1");
await page.getByPlaceholder("Nguyen").fill("1");
await page.getByRole("button", { name: "Find my booking" }).click();
await page.getByText("Booking reference: 1").waitFor({ timeout: 5000 });

await page.getByRole("button", { name: "View extras" }).click();
await page.getByText("Drying room access").waitFor({ timeout: 5000 });
pass(await page.getByText("PMS firewood", { exact: true }).isVisible(), "the attached PMS extra name replaces a generic catalogue label");
pass(await page.getByText(/Already on booking: 2 · \$40/).isVisible(), "existing GuestPoint extras and quantities are shown");
await page.getByRole("button", { name: /Add Drying room access/i }).click();
await page.getByText(/Selected extras: \$/).waitFor({ timeout: 3000 });
pass(await page.getByText(/Selected extras: \$70/).isVisible(), "per-person-per-night service uses every guest and night");
await page.getByRole("button", { name: "Review price and payment" }).click();
await page.getByText("GuestPoint has confirmed this change").waitFor({ timeout: 3000 });
pass(await page.getByText(/saved card 4111\*+1111/).isVisible(), "the exact saved-card charge is shown before consent");
// Matched through the <label> rather than the text node's parent: the consent
// wording is now a binding (it changes when the booking has no saved card), and
// the renderer wraps interpolated text in its own element. Asserting that one
// label carries both the wording and the checkbox is the guarantee that matters.
const extrasCheckbox = page.locator("label").filter({ hasText: /authorise the displayed charge/i }).getByRole("checkbox");
await extrasCheckbox.check();
await page.getByRole("button", { name: "Charge card and update extras" }).click();
await page.getByText("Extras updated in GuestPoint").waitFor({ timeout: 3000 });
pass(await page.getByText(/charged \$30/).isVisible(), "accepted extras charge is confirmed");

await page.getByRole("button", { name: "Arrival & vehicles" }).click();
pass(await page.getByPlaceholder("7m x 5m").isVisible(), "vehicle dimensions are captured alongside car registration");

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
pass(await page.getByRole("button", { name: "Find my booking" }).isVisible(), "Continue returns to the booking lookup form");

// A per-person extra left over from a smaller party must be offered for
// repricing without the guest touching a stepper. Deriving both the current
// and the desired selection from the new party size made them identical, so
// the review panel stayed hidden — the portal could tell a guest to review
// their extras after a guest-number change and then show no way to do it.
await page.getByPlaceholder("Reservation number or channel booking ref").fill("KTP-48213");
await page.getByPlaceholder("Nguyen").fill("Nguyen");
await page.getByRole("button", { name: "Find my booking" }).click();
await page.getByText("Booking reference: KTP-48213").waitFor({ timeout: 8000 });
await page.getByRole("button", { name: "View extras", exact: true }).click();
await page.getByText(/Already on booking/i).first().waitFor({ timeout: 8000 });
const reprice = page.getByRole("button", { name: /Review price and payment/i });
pass(await reprice.count() > 0, "a stale per-person extra offers a reprice with no stepper interaction");
await reprice.first().click();
await page.getByText("GuestPoint has confirmed this change").waitFor({ timeout: 8000 });
pass(await page.getByText(/Extras already on booking/i).isVisible(), "the reprice quote breaks down current and new extras totals");
const acceptExtras = page.getByRole("button", { name: /Charge card and update extras|Confirm extra changes/i }).first();
pass(!(await acceptExtras.isEnabled()), "the extras charge stays locked until the guest accepts it");
pass(pageErrors.length === 0, "the extras reprice flow has no page errors");

// The calendar used to open on the current month whatever the booking's dates
// were, so a guest amending a November stay in September saw September, with
// their own dates off-screen and no clue they had to page forward to reach them.
await page.getByRole("button", { name: "Look up another booking" }).click();
await page.getByRole("button", { name: "Continue" }).click();
await page.getByPlaceholder("Reservation number or channel booking ref").fill("1");
await page.getByPlaceholder("Nguyen").fill("1");
await page.getByRole("button", { name: "Find my booking" }).click();
await page.getByText("Booking reference: 1").waitFor({ timeout: 5000 });
await page.getByRole("button", { name: "Amend booking" }).click();
await page.getByRole("button", { name: "Change dates" }).click();
const checkInField = page.getByText("Check in").first();
const checkInLabel = (await checkInField.locator("..").innerText()).trim();
await checkInField.click();
const monthTitle = (await page.locator("text=/^[A-Z][a-z]+ \\d{4}$/").first().innerText()).trim();
// The field reads "Tue, 10 Nov" and the calendar "November 2026", so compare on
// the month name the two share.
const stayMonthAbbr = (checkInLabel.match(/\b[A-Z][a-z]{2}\b/g) || []).pop();
pass(!!stayMonthAbbr && monthTitle.startsWith(stayMonthAbbr),
  `calendar opens on the stay's month, not today's: check-in "${checkInLabel.replace(/\n/g, " ")}" -> calendar "${monthTitle}"`);
pass(pageErrors.length === 0, "opening the amendment calendar has no page errors");


await browser.close();
server.kill();
console.log("manage.test.mjs: all assertions passed");
