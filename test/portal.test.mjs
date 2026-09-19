import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { cancelReservation, normaliseMobile, normaliseManagedReservation, quoteManagedStayChange, quoteManagedExtras, modifyReservation, lookupPortalBooking } from "../public_html/guestpoint.js";

assert.equal(normaliseMobile("0412 345 678"), "61412345678");
assert.equal(normaliseMobile("+61 412 345 678"), "61412345678");
assert.equal(normaliseMobile("0011 61 412 345 678"), "61412345678");

const directBooking = await lookupPortalBooking({ confNum: "1", surname: "1" });
assert.equal(directBooking.Reservation.ConfNum, "1");
assert.equal(directBooking.PortalToken, "mock-portal-token");

const booking = normaliseManagedReservation({
  PortalToken: "signed-test-token",
  Reservation: {
    ID: 42,
    ConfNum: "ABC123",
    Status: "Modified",
    ReservationTotalAfterTax: "900",
    PaymentRequired: "300",
    Adults: 2,
    Children: 1,
    Guests: [{ ID: "guest-1", FirstName: "Alex", LastName: "Nguyen", Email: "alex@example.com", Mobile: "+61412345678" }],
    BookingContact: { ID: "guest-1", FirstName: "Alex", LastName: "Nguyen", Email: "alex@example.com", Mobile: "+61412345678" },
    RoomStays: [{
      Arrival: "2026-10-10",
      Departure: "2026-10-13",
      RoomTypeId: "cedar-cabin",
      RoomTypeName: "Cedar Cabin",
      RoomTotal: "900",
      IsCancelled: false,
      PolicyText: "Standard conditions",
      RateDetails: [{ RatePlanId: "standard", RatePlanName: "Standard rate" }]
    }]
  },
  CurrentExtras: [{ Id: "firewood", Quantity: 2, ChildQuantity: 0, Total: 40 }],
  Login: { ViewReservation: true, Cancel: false, SpecialRequests: true, PayNow: true },
  PaymentDetails: { Session: { PayUrl: "https://pay.example.test/session" } }
});

assert.equal(booking.ref, "ABC123");
assert.equal(booking.room, "Cedar Cabin");
assert.equal(booking.nightly, 300);
assert.equal(booking.paid, 600);
assert.equal(booking.balance, 300);
assert.equal(booking.permissions.Cancel, false);
assert.equal(booking.permissions.SpecialRequests, true);
assert.equal(booking.roomTypeId, "cedar-cabin");
assert.equal(booking.ratePlanId, "standard");
assert.equal(booking.stayCount, 1);
assert.equal(booking.guests[0].Email, "alex@example.com");
assert.equal(booking.bookingContact.ID, "guest-1");
assert.equal(booking.portalToken, "signed-test-token");
assert.equal(booking.currentExtras[0].Quantity, 2);
assert.equal(booking.paymentUrl, "https://pay.example.test/session");

const quote = await quoteManagedStayChange(booking, {
  checkIn: "2027-03-02", checkOut: "2027-03-04", adults: 2, children: 1
});
assert.equal(quote.available, true);
assert.equal(quote.room, "Cedar Cabin");
assert.equal(quote.rate, "Standard rate");
assert.ok(quote.newTotal > 0);

const updated = await modifyReservation("ABC123", { EstimatedArrival: "18:30" }, "signed-test-token", true);
assert.equal(updated.Updated, true);
assert.equal(updated.NotificationSent, true);

const cancellation = await cancelReservation("ABC123", "signed-test-token", true);
assert.equal(cancellation.Cancelled, true);

const extrasQuote = await quoteManagedExtras("ABC123", [{ id: "firewood", quantity: 3, childQuantity: 0, total: 60 }], "signed-test-token");
assert.equal(extrasQuote.NewTotal, 60);
assert.equal(extrasQuote.ChargeAmount, 20);
assert.equal(extrasQuote.Card.Available, true);

const proxySource = await readFile(new URL("../public_html/api/gp/index.php", import.meta.url), "utf8");
assert.match(proxySource, /'portal\/cancel'\s*=>\s*\['POST'/, "authenticated portal cancellation route is allow-listed");
assert.doesNotMatch(proxySource, /'reservations\/\*'\s*=>\s*\['DELETE'/, "direct unauthenticated cancellation route is not exposed");
assert.doesNotMatch(proxySource, /'reservations\/manage'\s*=>\s*\['POST'/, "direct unauthenticated manage lookup is not exposed");
assert.match(proxySource, /\$reqPropertyId !== 'self'/, "the browser's self property alias is accepted by the configured proxy");
assert.match(proxySource, /'portal\/lookup'\s*=>\s*\['POST'/, "portal lookup accepts reference and surname without OTP");
assert.match(proxySource, /'portal\/extras\/quote'\s*=>\s*\['POST'/, "extras are repriced server-side before final acceptance");
assert.match(proxySource, /isset\(\$payload\['ReservationNumber'\]\)/, "single-reservation Core responses are supported");
assert.match(proxySource, /function resolvePortalBookingIdentity/, "portal resolves either GuestPoint booking number to one identity");
assert.match(proxySource, /GetReservationDetailByRoomAllocationWithCurrentPackage/, "portal reads the channel reference behind a PMS reservation number");
assert.match(proxySource, /'manageReference'\s*=>\s*\$numbers\['bookingReference'\]/, "portal canonicalises lookup to the channel reference required by manage");
assert.match(proxySource, /function pmsManagedReservationPayload/, "Phoenix-only reservations receive a managed portal view");
assert.match(proxySource, /A reservation created directly in Phoenix has no Booking Engine[\s\S]*PackageID/, "Phoenix-only reservations use their PMS room and package for server-side amendment repricing");
assert.match(proxySource, /Phoenix excludes cancelled allocations[\s\S]*GetRoomAllocation\?roomAllocationID=/, "cancelled Phoenix bookings fall back to the read-only allocation endpoint");
assert.match(proxySource, /function portalCurrentExtras/, "existing Phoenix add-ons are returned to the portal");
assert.match(proxySource, /ProcessPaymentUsingProxyPost/, "saved-card extra payments use GuestPoint's card-vault operation on the server");
assert.match(proxySource, /GetHasCcMapExpired/, "the saved card is checked before it is offered");
assert.match(proxySource, /SaveTransactionItemDetails/, "extras and cancellation fees are posted to the GuestPoint room account");
assert.match(proxySource, /SaveRoomAllocationEditWithVirtualRooms/, "car rego and vehicle dimensions use Phoenix reservation profile fields");
assert.match(proxySource, /function portalAccommodationTotal/, "cancellation fees use accommodation charges without optional extras");
assert.match(proxySource, /charges already delivered are protected|retain past charges/i, "past add-on charges cannot be removed by the guest");
assert.match(proxySource, /guestPointConfirmed\(\$cancelResponse\)/, "cancellation requires GuestPoint success confirmation");
assert.match(proxySource, /\$reservationId = trim\(\(string\)\$tokenPayload\['rid'\]\)/, "PMS UUID cancellation id remains a string from the signed portal token");
assert.match(proxySource, /Reservation\/ValidateCancellation/, "Phoenix cancellation is validated before mutation");
assert.match(proxySource, /Accounts\/CalcBookingValue/, "Phoenix booking value is recalculated before cancellation");
assert.match(proxySource, /Accounts\/CalcRoomAccountBalance/, "Phoenix room account balance is recalculated before cancellation");
assert.match(proxySource, /Accounts\/CalculateDepartureValue/, "Phoenix departure value is recalculated before cancellation");
assert.match(proxySource, /\$freshLogin\['Cancel'\]/, "cancellation rechecks GuestPoint permission immediately before deletion");
assert.match(proxySource, /GuestPoint has not supplied the payment and refund operations/, "cancellation fails closed when financial settlement is unavailable");

const unpaid = normaliseManagedReservation({
  Reservation: {
    ID: 43, ConfNum: "UNPAID1", ReservationTotalAfterTax: "600",
    PaymentRequired: "0", PayLater: "600", Adults: "0", Children: "0", Infants: "0",
    RoomStays: [{ Arrival: "2027-01-10", Departure: "2027-01-12", Adults: 2, RoomTotal: "600",
      RateDetails: [{ CancelRule: { CancelRule: "none", CancelRuleText: "No refund." } }] }]
  },
  CancellationQuote: { fee: 600, refund: 0, amountDue: 600, paid: 0, balance: 600, detail: "No refund." }
});
assert.equal(unpaid.balance, 600);
assert.equal(unpaid.paid, 0);
assert.equal(unpaid.adults, 0, "a documented zero guest count is not replaced by stay data");
assert.equal(unpaid.cancelRule.CancelRule, "none");
assert.equal(unpaid.cancellationQuote.amountDue, 600);

const multi = normaliseManagedReservation({
  Reservation: {
    ConfNum: "MULTI1",
    ReservationTotal: "500",
    RoomStays: [
      { Arrival: "2026-11-03", Departure: "2026-11-05", RoomTypeName: "Cabin", Adults: 2 },
      { Arrival: "2026-11-01", Departure: "2026-11-04", RoomTypeName: "Site", Adults: 2 },
      { Arrival: "2026-10-01", Departure: "2026-10-02", RoomTypeName: "Old", IsCancelled: true }
    ]
  },
  Login: { ViewReservation: true }
});

assert.equal(multi.checkIn, "2026-11-01");
assert.equal(multi.checkOut, "2026-11-05");
assert.equal(multi.room, "Cabin + Site");
assert.equal(multi.adults, 4);

const cancelled = normaliseManagedReservation({
  Reservation: {
    ConfNum: "CANCELLED1",
    Status: "Cancelled",
    ReservationTotal: "250",
    RoomStays: [{
      Arrival: "2026-01-10", Departure: "2026-01-12",
      RoomTypeName: "Cedar Cabin", RoomTotal: "250", IsCancelled: true
    }]
  },
  Login: { ViewReservation: true }
});
assert.equal(cancelled.status, "Cancelled");
assert.equal(cancelled.room, "Cedar Cabin");
assert.equal(cancelled.checkIn, "2026-01-10");

console.log("portal.test.mjs: all assertions passed");
