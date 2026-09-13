window.KOSIPARK_V2 = Object.assign({
  // GuestPoint's hosted page owns guest details, payment and confirmation.
  // Its root route accepts startDate, numAdults, numChildren and numNights.
  secureBookingUrl: "https://10092601.bookus.dev/",

  // Keep the development property unmistakable on the Hostinger preview.
  // Change this to "production" only when the live key, property and hosted
  // booking address have all been installed together.
  bookingEnvironment: "development",

  // GuestPoint's development property contains four hotel-style test rooms.
  // One category is enough to prove all calendar behaviour without presenting
  // those placeholders as Kosipark accommodation. Set to 0 with live details.
  testCategoryLimit: 1
}, window.KOSIPARK_V2 || {});
