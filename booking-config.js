// Guest-facing language deliberately says "Secure Online Booking" rather
// than exposing the name of the hosted booking software.
const isLocalPreview = /^(localhost|127\.0\.0\.1)$/.test(window.location.hostname);

window.KOSIPARK_V2 = Object.assign({
  // The other-property destination is strictly limited to local demonstrations.
  // Replace the empty live value with Kosipark's URL when GuestPoint supplies it.
  secureBookingUrl: isLocalPreview
    ? "https://coachmans-eden.bookus.direct/booking-details"
    : ""
}, window.KOSIPARK_V2 || {});
