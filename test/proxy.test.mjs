/**
 * Integration tests for public_html/api/gp/index.php against a fake GuestPoint.
 *
 * These exist because guestpoint.js's mock is kinder than the real API and has
 * hidden real bugs: the whole extras-and-card flow passed `npm test` while
 * every successful charge returned 502 from the PHP. Nothing here touches the
 * browser mock — every assertion goes through the real proxy over HTTP.
 *
 * Run with:  npm run test:proxy      (add HARNESS_SLOW=1 for the timeout case)
 *
 * IDs listed in KNOWN_BROKEN are expected to fail; they track findings not yet
 * fixed on this branch. A KNOWN_BROKEN test that starts passing is reported as
 * an error, so the list cannot rot.
 */
import { startHarness } from "./harness/server.mjs";
import { baseState, postedExtra, FIREWOOD, DRYING_ROOM } from "./harness/fixtures.mjs";

const KNOWN_BROKEN = new Set([]);

const results = { pass: 0, fail: 0, xfail: 0, xpass: 0 };
const failures = [];

function check(id, condition, detail = "") {
  const broken = KNOWN_BROKEN.has(id);
  if (condition) {
    if (broken) { results.xpass++; failures.push(`${id}: listed in KNOWN_BROKEN but PASSED — remove it from the list`); console.log(`XPASS ${id}  ${detail}`); }
    else { results.pass++; console.log(`PASS  ${id}  ${detail}`); }
  } else if (broken) {
    results.xfail++; console.log(`XFAIL ${id}  (known, fix pending)  ${detail}`);
  } else {
    results.fail++; failures.push(`${id}: ${detail}`); console.log(`FAIL  ${id}  ${detail}`);
  }
}

const h = await startHarness();
const items = (...rows) => rows;
const firewood = (quantity, total) => ({ id: FIREWOOD, quantity, childQuantity: 0, total });
const dryingRoom = (on) => ({ id: DRYING_ROOM, quantity: on ? 1 : 0, childQuantity: 0, total: on ? 39 : 0 });

/** Reset to a clean booking and hand back a fresh portal token. */
async function scenario(overrides = {}, mode = "") {
  h.setMode(mode);
  h.setState(baseState(overrides));
  h.resetCallLog();
  h.clearRateLimit();
  return h.portalToken();
}

const activeExtras = state => (state.tx ?? []).filter(t => t.AddonID && !t.IsReversed && !t.ReversedTransactionItemID);
const netAddonTotal = state => (state.tx ?? []).filter(t => t.AddonID).reduce((sum, t) => sum + Number(t.AmountInc || 0), 0);
const paymentRows = state => (state.tx ?? []).filter(t => Number(t.TransactionType) === 11);
const feeRows = state => (state.tx ?? []).filter(t => /^Cancellation Fees?\b/i.test(String(t.Description || "")) && !t.IsReversed && !t.ReversedTransactionItemID);

// ------------------------------------------------ amendment (transfer) fee --
// The conditions attach a transfer fee to a date change inside 15 days, and to
// any snow-season move. It was shown to the guest and never reached GuestPoint:
// the booking moved and the fee was simply lost. It now posts to the room
// account like an extra, and is charged when a card is on file.
const dayOffset = n => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);
const feeStay = (n) => ({ arrival: dayOffset(n), departure: dayOffset(n + 3), nights: 3 });
// Snow season (Jun-Sep) forbids a change inside 14 days, so an arrival 12 days
// out carries a fee only off-peak. Asserting the right one keeps this stable
// whatever today's date is.
const snowAt = iso => { const m = Number(iso.slice(5, 7)); return m >= 6 && m <= 9; };
const amendFeeRows = state => (state.tx ?? []).filter(t => /^Amendment Fee\b/i.test(String(t.Description || "")) && !t.IsReversed && !t.ReversedTransactionItemID);
{
  const stay = feeStay(12);
  const expectFee = !snowAt(stay.arrival);
  const token = await scenario(stay);
  const { status, body } = await h.post("/portal/amend", {
    ConfNum: "R1001", PortalToken: token, Acknowledged: true,
    Proposal: { checkIn: dayOffset(20), checkOut: dayOffset(23), adults: 2, children: 0, infants: 0 },
  });
  const state = h.readState();
  // roomTotal 300 over 3 nights: max($50, 10% of 300, one night 100) = 100.
  check("amend-fee-posted", status === 200 && Number(body?.AmendmentFee || 0) === (expectFee ? 100 : 0),
    `arrival ${stay.arrival} (${expectFee ? "off-peak" : "snow, change not permitted inside 14 days"}) fee=${body?.AmendmentFee}`);
  check("amend-fee-on-account", amendFeeRows(state).length === (expectFee ? 1 : 0),
    `${amendFeeRows(state).length} unreversed "Amendment Fee" rows on the account`);
  if (expectFee) {
    check("amend-fee-charged", body?.AmendmentFeeCharged === true && (state.payments ?? []).length === 1,
      `charged=${body?.AmendmentFeeCharged} gatewayCharges=${(state.payments ?? []).length}`);
    check("amend-fee-not-double-posted", paymentRows(state).length === 1,
      `${paymentRows(state).length} payment rows (GuestPoint posts its own; the proxy must not add another)`);
  }
}
{
  // No card: the fee still belongs on the account, and the guest is told it is
  // payable at reception rather than being refused the change.
  const stay = feeStay(12);
  const expectFee = !snowAt(stay.arrival);
  const token = await scenario(stay, "no-card");
  const { status, body } = await h.post("/portal/amend", {
    ConfNum: "R1001", PortalToken: token, Acknowledged: true,
    Proposal: { checkIn: dayOffset(20), checkOut: dayOffset(23), adults: 2, children: 0, infants: 0 },
  });
  const state = h.readState();
  check("amend-fee-no-card-posted", status === 200 && amendFeeRows(state).length === (expectFee ? 1 : 0),
    `status=${status} rows=${amendFeeRows(state).length}`);
  check("amend-fee-no-card-not-charged", body?.AmendmentFeeCharged !== true && (state.payments ?? []).length === 0,
    `charged=${body?.AmendmentFeeCharged} gatewayCharges=${(state.payments ?? []).length}`);
  check("amend-fee-no-card-payable", Number(body?.PayableAtReception || 0) === (expectFee ? 100 : 0),
    `payableAtReception=${body?.PayableAtReception}`);
}
{
  // Inside 8 days no change is permitted, so no fee may be invented for one.
  const token = await scenario(feeStay(3));
  const { body } = await h.post("/portal/amend", {
    ConfNum: "R1001", PortalToken: token, Acknowledged: true,
    Proposal: { adults: 2, children: 0, infants: 0 },
  });
  const state = h.readState();
  check("amend-fee-none-inside-8-days", Number(body?.AmendmentFee || 0) === 0 && amendFeeRows(state).length === 0,
    `fee=${body?.AmendmentFee} rows=${amendFeeRows(state).length}`);
}

// ------------------------- per-person extras follow the party they are for --
// Drying Room is per person per night. A booking that adds or drops guests has
// to reprice it: 2 guests over 3 nights is not the same money as 4, and leaving
// the old figure posted either short-changes the park or overcharges the guest.
{
  const token = await scenario({ adults: 2, children: 0 });
  const { body } = await h.post("/portal/extras/quote", { ConfNum: "R1001", PortalToken: token, Items: items(dryingRoom(true)) });
  // service + perPersonPerNight: 2 adults x 3 nights x $5.
  check("per-person-priced-for-party", Number(body?.NewTotal) === 30, `2 adults, 3 nights, $5 each = $30, got ${body?.NewTotal}`);
}
{
  const token = await scenario({ adults: 4, children: 0 });
  const { body } = await h.post("/portal/extras/quote", { ConfNum: "R1001", PortalToken: token, Items: items(dryingRoom(true)) });
  check("per-person-follows-more-guests", Number(body?.NewTotal) === 60, `4 adults, 3 nights, $5 each = $60, got ${body?.NewTotal}`);
}
{
  // Children are priced on their own rate, not the adult one.
  const token = await scenario({ adults: 2, children: 2 });
  const { body } = await h.post("/portal/extras/quote", { ConfNum: "R1001", PortalToken: token, Items: items(dryingRoom(true)) });
  check("per-person-uses-child-rate", Number(body?.NewTotal) === 48, `(2 x $5 + 2 x $3) x 3 nights = $48, got ${body?.NewTotal}`);
}
{
  // A longer stay costs more per head: the "per night" half of the rule.
  const token = await scenario({ adults: 2, children: 0, nights: 5, departure: "2026-12-06" });
  const { body } = await h.post("/portal/extras/quote", { ConfNum: "R1001", PortalToken: token, Items: items(dryingRoom(true)) });
  check("per-person-follows-nights", Number(body?.NewTotal) === 50, `2 adults, 5 nights, $5 each = $50, got ${body?.NewTotal}`);
}
{
  // Dropping guests must reprice down, and the difference is a credit rather
  // than a new charge.
  const token = await scenario({ adults: 2, children: 0, tx: [postedExtra(DRYING_ROOM, { amount: 60, quantity: 4, description: "Drying room" })] });
  const { body } = await h.post("/portal/extras/quote", { ConfNum: "R1001", PortalToken: token, Items: items(dryingRoom(true)) });
  check("per-person-reprices-down", Number(body?.NewTotal) === 30 && Number(body?.CreditAmount) === 30 && Number(body?.ChargeAmount) === 0,
    `posted $60 for 4, party is now 2: new=${body?.NewTotal} credit=${body?.CreditAmount} charge=${body?.ChargeAmount}`);
}

// ------------------------------------- extras are named as GuestPoint names --
// The Booking Engine catalogue carries the web fields, which live are a
// description ("Firewood Desc") or nothing at all. Reception, the room account
// and the guest's invoice all use the Phoenix add-on name, so the catalogue the
// site renders has to say the same thing.
{
  await scenario();
  const { status, body } = await h.post("/extras", { RoomStays: [{ Arrival: "2026-07-01", Departure: "2026-07-03", RoomTypeId: "RT1", RatePlanId: "RP1" }] });
  const rows = Array.isArray(body) ? body : (body?.data ?? body?.Extras ?? []);
  const byId = Object.fromEntries(rows.map(r => [String(r.Id).toLowerCase(), r]));
  check("extras-name-from-pms", status === 200 && byId[FIREWOOD.toLowerCase()]?.Name === "Firewood",
    `catalogue said "Firewood Desc", GuestPoint says "Firewood", site shows ${JSON.stringify(byId[FIREWOOD.toLowerCase()]?.Name)}`);
  check("extras-blank-name-replaced", byId[DRYING_ROOM.toLowerCase()]?.Name === "Drying room",
    `catalogue name was empty, site shows ${JSON.stringify(byId[DRYING_ROOM.toLowerCase()]?.Name)}`);
}

// -------------------------------------- no saved card: post to the account --
// Plenty of bookings have no card. Refusing the extra outright was a dead end;
// the charge belongs on the room account, settled at reception. What must never
// happen is the site claiming a card was charged when there is no card.
{
  const token = await scenario({}, "no-card");
  const { status, body } = await h.post("/portal/extras/quote", { ConfNum: "R1001", PortalToken: token, Items: items(firewood(2, 40)) });
  check("no-card-quote-completable", status === 200 && body?.CanComplete === true && body?.PaymentMethod === "account",
    `status=${status} method=${body?.PaymentMethod} canComplete=${body?.CanComplete}`);
  check("no-card-quote-names-reception", Number(body?.PayableAtReception) === Number(body?.ChargeAmount) && Number(body?.ChargeAmount) > 0,
    `payableAtReception=${body?.PayableAtReception} of charge=${body?.ChargeAmount}`);
}
{
  const token = await scenario({}, "no-card");
  const { status, body } = await h.post("/portal/extras", { ConfNum: "R1001", PortalToken: token, Acknowledged: true, PaymentMethod: "account", Items: items(firewood(2, 40)) });
  const state = h.readState();
  check("no-card-extras-posted", status === 200 && body?.Updated === true && activeExtras(state).length > 0,
    `status=${status} updated=${body?.Updated} activeExtras=${activeExtras(state).length}`);
  check("no-card-not-charged", body?.Charged === false && (state.payments ?? []).length === 0,
    `charged=${body?.Charged} gatewayCharges=${(state.payments ?? []).length}`);
  check("no-card-payable-at-reception", Number(body?.PayableAtReception) === 40 && body?.PaymentMethod === "account",
    `payableAtReception=${body?.PayableAtReception} method=${body?.PaymentMethod}`);
  check("no-card-no-invented-payment-row", paymentRows(state).length === 0,
    `${paymentRows(state).length} payment rows — nothing was paid, so nothing may look paid`);
}
{
  // The guest accepted a card charge; by save time the card is gone. Applying
  // it on the account anyway would change the deal they agreed to.
  const token = await scenario({}, "no-card");
  const { status, body } = await h.post("/portal/extras", { ConfNum: "R1001", PortalToken: token, Acknowledged: true, PaymentMethod: "card", Items: items(firewood(2, 40)) });
  const state = h.readState();
  check("payment-method-change-refused", status === 409 && activeExtras(state).length === 0,
    `status=${status} activeExtras=${activeExtras(state).length}`);
}

// ---------------------------------------------------------------- lookup ----
{
  const token = await scenario();
  const { body } = await h.post("/portal/lookup", { ConfNum: "R1001", Surname: "Smith" });
  const root = body?.data ?? body;
  check("lookup-token", typeof token === "string" && token.includes("."), "signed portal token issued");
  check("lookup-no-staff-notes", !JSON.stringify(body).includes("never show this to a guest"), "reception ExtraInfo is not disclosed");
  check("lookup-card-token-withheld", !JSON.stringify(body).includes("CCMAP-HARNESS"), "saved-card token never reaches the browser");
  check("lookup-card-mask", root?.StoredCard?.mask === "411111****1111", "card mask is available for display");
}

// ------------------------------------------------------- extras repricing ----
{
  const token = await scenario();
  const { body } = await h.post("/portal/extras/quote", { ConfNum: "R1001", PortalToken: token, Items: items(dryingRoom(true)) });
  // 2 adults @ $5 + 1 child @ $3, over 3 nights.
  check("quote-per-person-per-night", body?.NewTotal === 39, `NewTotal=${body?.NewTotal}`);
  check("quote-charge-equals-delta", body?.ChargeAmount === 39, `ChargeAmount=${body?.ChargeAmount}`);
}
{
  // The same selection costs more once the party grows — this is the delta the
  // portal must let the guest settle after a guest-number amendment (H1).
  const token = await scenario({ tx: [postedExtra(DRYING_ROOM, { amount: 39, quantity: 2, childQuantity: 1, description: "Drying room" })] });
  const before = await h.post("/portal/extras/quote", { ConfNum: "R1001", PortalToken: token, Items: items(dryingRoom(true)) });
  h.patchState({ adults: 4 });
  const after = await h.post("/portal/extras/quote", { ConfNum: "R1001", PortalToken: token, Items: items(dryingRoom(true)) });
  check("quote-reprices-on-party-change", before.body?.ChargeAmount === 0 && after.body?.ChargeAmount === 30,
    `before=${before.body?.ChargeAmount} after=${after.body?.ChargeAmount}`);
}

// ------------------------------------------ C1 payment verification ---------
{
  const token = await scenario();
  const { status, body } = await h.post("/portal/extras", { ConfNum: "R1001", PortalToken: token, Acknowledged: true, Items: items(firewood(2, 40)) });
  const state = h.readState();
  check("C1-verified", status === 200 && body?.Updated === true && body?.Charged === true, `status=${status} charged=${body?.Charged}`);
  check("C1-no-duplicate", paymentRows(state).length === 1, `${paymentRows(state).length} TransactionType 11 rows (Phoenix posts its own; the proxy must not add another)`);
  check("C1-charged-once", (state.payments ?? []).length === 1, `${(state.payments ?? []).length} gateway charges`);
  // ProcessPaymentUsingProxyPost takes the card details in the query string but
  // the room account in the BODY. Ship it without the body and the gateway
  // charges the card while GuestPoint posts no payment row against the booking
  // — money taken, account unchanged. Captured from the Phoenix client.
  check("C1-payment-attributed", state.lastPaymentAttributed === true,
    `payment body carried PersonID/RoomAllocationID/AppuserID/SelectedTransactionAccountID: ${state.lastPaymentAttributed}`);
}
{
  // Phoenix confirms the charge but the room-account row has not landed yet.
  // That is a reporting delay, not a failure: the guest must not be told the
  // change failed after their card was charged.
  const token = await scenario({}, "no-postback");
  const { status, body } = await h.post("/portal/extras", { ConfNum: "R1001", PortalToken: token, Acknowledged: true, Items: items(firewood(2, 40)) });
  const state = h.readState();
  check("C1-pending", status === 200 && body?.Updated === true && body?.PaymentPendingVerification === true,
    `status=${status} pending=${body?.PaymentPendingVerification}`);
  check("C1-pending-no-invented-row", paymentRows(state).length === 0,
    `${paymentRows(state).length} payment rows — the proxy must not invent one it cannot prove is unique`);
  check("C1-pending-charged-once", (state.payments ?? []).length === 1, "card charged exactly once");
}

// ------------------------------------------ C2 compensation on decline ------
{
  const token = await scenario({}, "declined");
  const { status } = await h.post("/portal/extras", { ConfNum: "R1001", PortalToken: token, Acknowledged: true, Items: items(firewood(2, 40)) });
  const state = h.readState();
  check("C2-declined-errors", status >= 400, `status=${status}`);
  check("C2-reversed", Math.abs(netAddonTotal(state)) < 0.005 && activeExtras(state).length === 0,
    `net addon total on the account = ${netAddonTotal(state)}`);
}
{
  // The dangerous half of C2: once extras are posted they count as "current",
  // so a retry prices the delta at zero and hands them over free.
  const token = await scenario({}, "declined");
  await h.post("/portal/extras", { ConfNum: "R1001", PortalToken: token, Acknowledged: true, Items: items(firewood(2, 40)) });
  h.setMode("");
  const requote = await h.post("/portal/extras/quote", { ConfNum: "R1001", PortalToken: token, Items: items(firewood(2, 40)) });
  check("C2-no-free-extras", requote.body?.ChargeAmount === 40, `re-quote after a declined charge asks for ${requote.body?.ChargeAmount}, expected 40`);
}
{
  // When the rollback itself is refused, the guest must be told the booking
  // needs a human — not that nothing changed.
  const token = await scenario({}, "rollback-reject");
  const { status, body } = await h.post("/portal/extras", { ConfNum: "R1001", PortalToken: token, Acknowledged: true, Items: items(firewood(2, 40)) });
  check("C2-rollback-failure-is-honest", status >= 400 && /call reception/i.test(String(body?.Error?.Message ?? "")) && !/nothing was changed/i.test(String(body?.Error?.Message ?? "")),
    `message=${body?.Error?.Message}`);
}
{
  // A 5xx from a payment endpoint is not a decline. Unwinding here could hand
  // back extras the guest has actually paid for.
  const token = await scenario({}, "gateway-500");
  const { status, body } = await h.post("/portal/extras", { ConfNum: "R1001", PortalToken: token, Acknowledged: true, Items: items(firewood(2, 40)) });
  const state = h.readState();
  check("C2-5xx-not-reversed", status >= 400 && /may still have gone through/i.test(String(body?.Error?.Message ?? "")),
    `status=${status} message=${body?.Error?.Message}`);
  check("C2-5xx-extras-left-alone", Math.abs(netAddonTotal(state) - 40) < 0.005,
    `net addon total ${netAddonTotal(state)} — an unknown payment outcome must not be unwound`);
}
if (process.env.HARNESS_SLOW === "1") {
  // Indeterminate: the gateway never answered, so we cannot know whether money
  // moved. Reversing here would refund a charge that may have succeeded.
  const token = await scenario({}, "gateway-timeout");
  const { status, body } = await h.post("/portal/extras", { ConfNum: "R1001", PortalToken: token, Acknowledged: true, Items: items(firewood(2, 40)) });
  check("C2-indeterminate-not-reversed", status >= 400 && /reception|check|not retry/i.test(String(body?.Error?.Message ?? "")),
    `status=${status} message=${body?.Error?.Message}`);
}

// ------------------------------------------ C3 cancellation fee -------------
{
  const token = await scenario();
  const { status, body } = await h.post("/portal/cancel", { ConfNum: "R1001", PortalToken: token, Acknowledged: true });
  const state = h.readState();
  check("C3-match-cancels", status === 200 && body?.Cancelled === true, `status=${status}`);
  check("C3-fee-posted-once", feeRows(state).length === 1, `${feeRows(state).length} cancellation fee rows`);
}
{
  // The booking's money moves between the guest opening the page and ticking
  // the box — here a deposit lands, which changes the refund. Charging the
  // recalculated figure silently is how someone who accepted $30 is billed
  // $100; the portal must refuse and re-quote instead.
  const token = await scenario({ departureValue: 0 });
  h.patchState({ departureValue: 300 });
  const { status, body } = await h.post("/portal/cancel", { ConfNum: "R1001", PortalToken: token, Acknowledged: true });
  const state = h.readState();
  check("C3-mismatch-rejected", status === 409 && feeRows(state).length === 0 && !state.cancelled,
    `status=${status} fees=${feeRows(state).length} cancelled=${!!state.cancelled} message=${body?.Error?.Message ?? body?.PolicyFee}`);
  check("C3-mismatch-explains", /cost has changed/i.test(String(body?.Error?.Message ?? "")) && /Nothing has been changed or charged/i.test(String(body?.Error?.Message ?? "")),
    "the refusal names both figures and says nothing was charged");
}
{
  // The quote shown at lookup and the one the cancel path recalculates must
  // come from the same numbers, or the guard above fires on every cancellation.
  const token = await scenario({ departureValue: 300, payLater: 0 });
  const lookup = await h.post("/portal/lookup", { ConfNum: "R1001", Surname: "Smith" });
  const shown = (lookup.body?.data ?? lookup.body)?.CancellationQuote;
  const { status, body } = await h.post("/portal/cancel", { ConfNum: "R1001", PortalToken: token, Acknowledged: true });
  check("C3-quote-agrees-end-to-end", status === 200 && Math.abs(Number(body?.PolicyFee) - Number(shown?.fee)) < 0.005,
    `shown fee=${shown?.fee} charged fee=${body?.PolicyFee}`);
}
{
  const token = await scenario({ tx: [{ TransactionItemID: "existing-fee", TransactionAccountID: "ACCT-FEE", TransactionType: 2, RoomAllocationID: baseState().roomAllocationId, AmountInc: 100, Description: "Cancellation Fees", Quantity: 1, QuantityChild: 0 }] });
  await h.post("/portal/cancel", { ConfNum: "R1001", PortalToken: token, Acknowledged: true });
  check("C3-fee-not-duplicated", feeRows(h.readState()).length === 1, `${feeRows(h.readState()).length} fee rows after cancelling a booking that already had one`);
}

// ------------------------------------------ travel fields (H2) --------------
{
  const token = await scenario();
  const { status, body } = await h.post("/portal/update", { ConfNum: "R1001", PortalToken: token, Changes: {
    EstimatedArrival: "16:30",
    ProfileFields: [{ Id: "PF-REGO", Value: "ABC123" }, { Id: "PF-DIM", Value: "7m x 5m" }],
  } });
  check("travel-saves", status === 200 && body?.Updated === true, `status=${status}`);
  check("travel-eta-written", h.readState().eta === "04:30 PM", `eta=${h.readState().eta}`);
}
{
  const token = await scenario({}, "ignore-profiles");
  const { status, body } = await h.post("/portal/update", { ConfNum: "R1001", PortalToken: token, Changes: {
    EstimatedArrival: "16:30",
    ProfileFields: [{ Id: "PF-REGO", Value: "ABC123" }],
  } });
  const message = JSON.stringify(body);
  check("H2-partial-write-is-honest", status >= 400 && /arrival time was saved|ETA was saved/i.test(message),
    `the ETA persisted (${h.readState().eta}) so the response must not claim nothing changed — got ${message.slice(0, 160)}`);
}
{
  const token = await scenario();
  const { status } = await h.post("/portal/update", { ConfNum: "R1001", PortalToken: token, Changes: { ProfileFields: [{ Id: "PF-NOTE", Value: "pwned" }] } });
  check("travel-field-allowlist", status === 400, `writing a non-vehicle profile field returned ${status}`);
}

// ------------------------------------------ catalogue size (M1) -------------
{
  // The portal sends one entry per displayed extra, zeros included, because an
  // extra left out of the list reads as removed. A fixed cap of 20 therefore
  // killed the feature outright the moment the catalogue outgrew it.
  const base = baseState();
  const wide = Array.from({ length: 25 }, (_, i) => ({
    Id: `bbbbbbbb-bbbb-4bbb-8bbb-${String(i).padStart(12, "0")}`,
    Name: `Extra ${i}`, Description: "", ExtraType: "checkout", CheckoutType: "quantity",
    MaxItems: 4, PriceType: "perBooking", DisplayOrder: i, Images: null,
    Prices: [{ Id: "p1", Name: "", Price: 10 }], RatePlans: [],
  }));
  const token = await scenario({
    catalog: [...base.catalog, ...wide],
    addons: [...base.addons, ...wide.map(x => ({ AddonID: x.Id, Name: x.Name, TransactionAccountID: "ACCT-1", IsPerNight: false }))],
  });
  const selection = [firewood(0, 0), dryingRoom(false), ...wide.map((x, i) => ({ id: x.Id, quantity: i === 0 ? 1 : 0, childQuantity: 0, total: i === 0 ? 10 : 0 }))];
  const { status, body } = await h.post("/portal/extras/quote", { ConfNum: "R1001", PortalToken: token, Items: selection });
  check("M1-catalogue-size", status === 200 && body?.NewTotal === 10,
    `a ${selection.length}-entry selection over a 27-extra catalogue returned ${status} / NewTotal ${body?.NewTotal}`);
}

// ------------------------------------------ call volume (H3) ----------------
{
  // One save used to make twenty upstream calls at a 20s timeout each, inside
  // a single PHP request on shared hosting. Repeated reads are now memoised
  // and dropped explicitly after every account write.
  const token = await scenario();
  h.resetCallLog();
  const { status } = await h.post("/portal/extras", { ConfNum: "R1001", PortalToken: token, Acknowledged: true, Items: items(firewood(2, 40)) });
  const log = h.callLog();
  const counts = log.reduce((acc, line) => { const key = line.split("?")[0]; acc[key] = (acc[key] ?? 0) + 1; return acc; }, {});
  const worst = Object.entries(counts).sort((a, b) => b[1] - a[1])[0];
  check("H3-call-volume", status === 200 && log.length <= 15, `${log.length} upstream calls for one extras save (was 20)`);
  check("H3-no-repeated-reads", worst[1] <= 3, `most-repeated endpoint ${worst[0].replace(/^\w+ /, "")} called ${worst[1]}x`);
}
{
  // The transaction memo must not hide our own writes from the verification
  // that follows them, or a successful save would look unverified.
  const token = await scenario();
  const { body } = await h.post("/portal/extras", { ConfNum: "R1001", PortalToken: token, Acknowledged: true, Items: items(firewood(2, 40)) });
  check("H3-memo-invalidated-by-writes", body?.PaymentPendingVerification === false && body?.ExtrasPendingVerification === false,
    `pending flags after a clean save: payment=${body?.PaymentPendingVerification} extras=${body?.ExtrasPendingVerification}`);
}

// ------------------------------------------ session security ----------------
{
  const token = await scenario();
  const tampered = token.slice(0, -4) + "AAAA";
  const a = await h.post("/portal/extras", { ConfNum: "R1001", PortalToken: tampered, Acknowledged: true, Items: items(firewood(1, 20)) });
  const b = await h.post("/portal/extras/quote", { ConfNum: "R9999", PortalToken: token, Items: items(firewood(1, 20)) });
  check("security-tampered-token", a.status === 403, `status=${a.status}`);
  check("security-cross-booking", b.status === 403, `status=${b.status}`);
}

h.stop();
console.log(`\n${results.pass} passed, ${results.fail} failed, ${results.xfail} known-broken, ${results.xpass} unexpectedly passing`);
if (failures.length) { console.error("\n" + failures.map(f => "  - " + f).join("\n")); process.exit(1); }
console.log("Proxy integration checks complete.");
process.exit(0);
