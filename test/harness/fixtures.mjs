/** Baseline reservation the proxy harness starts every scenario from. */
export const RESERVATION_ID = "11111111-1111-4111-8111-111111111111";
export const ROOM_ALLOCATION_ID = "22222222-2222-4222-8222-222222222222";

/** Phoenix AddonIDs are GUIDs; the proxy validates that shape. */
export const FIREWOOD = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1";
export const DRYING_ROOM = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2";

export function baseState(overrides = {}) {
  return {
    reservationId: RESERVATION_ID,
    roomAllocationId: ROOM_ALLOCATION_ID,
    reservationNumber: "R1001",
    propertyId: "PROP-HARNESS",
    surname: "Smith",
    email: "guest@example.invalid",
    arrival: "2026-12-01",
    departure: "2026-12-04",
    nights: 3,
    adults: 2,
    children: 1,
    bookingTotal: 300,
    roomTotal: 300,
    payLater: 0,
    departureValue: 0,
    cancelRule: null,
    eta: "03:00 PM",
    profiles: [],
    futureAddons: [],
    tx: [],
    payments: [],
    paymentAttempts: [],
    postbackAfterReads: 99,
    addons: [
      { AddonID: FIREWOOD, Name: "Firewood", TransactionAccountID: "ACCT-1", IsPerNight: false },
      { AddonID: DRYING_ROOM, Name: "Drying room", TransactionAccountID: "ACCT-1", IsPerNight: true },
    ],
    // The Booking Engine catalogue carries the WEB fields, and live they are
    // not the add-on's name: Firewood comes back as "Firewood Desc" (its web
    // description) and Drying Room with Name and Description both empty. The
    // real names live on the Phoenix add-on master above, so the proxy has to
    // overlay them. Fixtures that spelled the names correctly here hid that.
    catalog: [
      {
        Id: FIREWOOD, Name: "Firewood Desc", Description: "A bag of dry hardwood.",
        ExtraType: "checkout", CheckoutType: "quantity", MaxItems: 6,
        PriceType: "perBooking", DisplayOrder: 1, Images: null,
        Prices: [{ Id: "p1", Name: "", Price: 20 }], RatePlans: [],
      },
      {
        Id: DRYING_ROOM, Name: "", Description: "",
        ExtraType: "checkout", CheckoutType: "service", MaxItems: 1,
        PriceType: "perPersonPerNight", DisplayOrder: 2, Images: null,
        Prices: [{ Id: "p1", Name: "Adult", Price: 5 }, { Id: "p2", Name: "Child", Price: 3 }],
        RatePlans: [],
      },
    ],
    ...overrides,
  };
}

/** A posted, unreversed account charge for one addon. */
export function postedExtra(addonId, { amount, quantity = 1, childQuantity = 0, description = "Extra" } = {}) {
  return {
    TransactionItemID: "tx-" + addonId.slice(-4),
    TransactionAccountID: "ACCT-1",
    TransactionType: 2,
    RoomAllocationID: ROOM_ALLOCATION_ID,
    PersonID: "P1",
    AddonID: addonId,
    AmountInc: amount,
    Quantity: quantity,
    QuantityChild: childQuantity,
    Description: description,
  };
}
