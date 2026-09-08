import assert from "node:assert/strict";
import { normaliseMobile, normaliseManagedReservation, quoteManagedStayChange, modifyReservation, requestPortalOtp, verifyPortalOtp } from "../public_html/guestpoint.js";

assert.equal(normaliseMobile("0412 345 678"), "61412345678");
assert.equal(normaliseMobile("+61 412 345 678"), "61412345678");
assert.equal(normaliseMobile("0011 61 412 345 678"), "61412345678");

const otp = await requestPortalOtp({ confNum: "1", surname: "1", mobile: "1" });
assert.equal(otp.DemoCode, "123456");
const otpBooking = await verifyPortalOtp({ challengeId: otp.ChallengeId, code: "123456" });
assert.equal(otpBooking.Reservation.ConfNum, "1");

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
