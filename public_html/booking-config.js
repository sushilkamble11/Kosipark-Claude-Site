// Guest-facing language deliberately says "Secure Online Booking" rather
// than exposing the name of the hosted booking software.
window.KOSIPARK_V2 = Object.assign({
  // Temporary local-demo destination. Replace this one value when GuestPoint
  // supplies Kosipark's own hosted booking address.
  secureBookingUrl: "https://coachmans-eden.bookus.direct/booking-details"
}, window.KOSIPARK_V2 || {});
