const gp = await import("../public_html/guestpoint.js");

// A response shaped exactly as the OpenAPI spec describes.
const spec = { Properties: [{
  Id: "PROP-GUID", Name: "Kosciuszko Tourist Park", CurrencyCode: "AUD",
  Latitude: "-36.3702", Longitude: "148.5401", ZoomLevel: "13",
  Rating: "4.4", RatingType: "Guest",
  PropertyImages: [
    { URL: "https://cdn.gp/park-2.jpg", Sequence: "2", Captions: { en: "Snow gums" } },
    { URL: "https://cdn.gp/park-1.jpg", Sequence: "1", Captions: { en: "Entrance" } }
  ],
  Facilities: { General: ["Camp kitchen", "BBQ"] },
  RoomTypes: [
    { Id: "RT-CEDAR", Name: "Cedar Cabin", Description: "Timber cabin",
      MaxGuests: 6, MaxAdults: 4, BedroomCount: 1, BathroomCount: 1,
      Classification: "Cabin",
      RoomImages: [{ URL: "https://cdn.gp/cedar-a.jpg", Sequence: "1", Captions: { en: "Deck" } }],
      Availabilities: [ { Date: "2026-10-01", Closed: false, ForSale: 3 },
                        { Date: "2026-10-02", Closed: false, ForSale: 1 } ],
      UrgencyMessages: [{ Message: "Popular for your dates" }],
      RatePlans: [
        { Id: "RP-STD", Name: "Standard", WebSortOrder: 1,
          PolicyText: "Deposit applies",
          Policy: { CancelRule: { CancelRuleText: "Free 15+ days out" },
                    DepositRule: { DepositRule: "PercentOfTotal", DepositRulePercentOfTotal: "50" } },
          Rates: [ { Date: "2026-10-01", SellRate: "175.00", StrikeOutRate: "195.00" },
                   { Date: "2026-10-02", SellRate: "185.00", StrikeOutRate: "185.00" } ] },
        { Id: "RP-ADV", Name: "Advance", WebSortOrder: 2,
          Rates: [ { Date: "2026-10-01", SellRate: "150.00" },
                   { Date: "2026-10-02", SellRate: "160.00" } ] }
      ] },
    { Id: "RT-UNP", Name: "Unpowered Site", MaxGuests: 6, Classification: "Site",
      Availabilities: [{ Date: "2026-10-01", Closed: false, ForSale: 8 }],
      RatePlans: [ { Id: "RP-STD", Name: "Standard",
        Rates: [{ Date: "2026-10-01", SellRate: "42.00", ClosedToArrival: true, MinStayArrival: 2 }] } ] }
  ]
}]};

const rows = gp.flattenAvailability(spec);
const cedar = rows[0], unp = rows[1];
const eq = (label, got, want) =>
  console.log((JSON.stringify(got) === JSON.stringify(want) ? "PASS" : "FAIL") +
              "  " + label + "  got=" + JSON.stringify(got) + (JSON.stringify(got)===JSON.stringify(want)?"":" want="+JSON.stringify(want)));

eq("cedar slug",            cedar.slug, "cedar-cabin");
eq("cedar sleeps (MaxGuests)", cedar.sleeps, 6);
eq("cedar bedrooms",        cedar.bedrooms, 1);
eq("roomsLeft = tightest night", cedar.roomsLeft, 1);
eq("cheapest total (sum of SellRate)", cedar.total, 310);
eq("cheapest plan",         cedar.ratePlanName, "Advance");
eq("saving on standard",    cedar.plans.find(p=>p.name==="Standard").saving, 20);
eq("cancellation from Policy", cedar.plans.find(p=>p.name==="Standard").cancellation, "Free 15+ days out");
eq("urgency from API",      cedar.urgency, ["Popular for your dates"]);
eq("images sorted+captioned", cedar.images, [{url:"https://cdn.gp/cedar-a.jpg",caption:"Deck",sequence:1}]);
eq("cedar available",       cedar.available, true);
eq("cedar isSite",          cedar.isSite, false);

eq("unpowered slug",        unp.slug, "unpowered-site");
eq("unpowered isSite",      unp.isSite, true);
eq("CTA blocks sale",       unp.available, false);
eq("restriction reason",    unp.restriction, {code:"MIN_STAY",minNights:2,message:"2 night minimum on these dates"});

eq("slug->id map",          gp.roomTypeMap(), {"cedar-cabin":"RT-CEDAR","unpowered-site":"RT-UNP"});
eq("id->slug",              gp.slugForRoomTypeId("RT-CEDAR"), "cedar-cabin");

// BAR calendar, spec shape
const bar = { Properties: [{ PropertyId:"PROP-GUID", RoomTypeId:"RT-CEDAR", RoomTypeName:"Cedar Cabin",
  Days: { "2026-10-01": { RoomAvailable: true, BestRate: "175.00", StrikeOutRate:"195.00", MinStay: 2, ClosedToArrival: false, DiscountReason:"Midweek" },
          "2026-10-02": { RoomAvailable: false, BestRate: "0" } } }]};
const days = gp.normaliseDays(bar, "cedar-cabin");
eq("BAR rate",     days["2026-10-01"].rate, 175);
eq("BAR minStay",  days["2026-10-01"].minStay, 2);
eq("BAR discount", days["2026-10-01"].discountReason, "Midweek");
eq("BAR sold out", days["2026-10-02"].soldOut, true);

// --- the mock fixtures must still map, so localhost stays clickable ---------
globalThis.window = { KOSIPARK_MOCK: true };
globalThis.location = { protocol: "http:", hostname: "localhost" };
globalThis.localStorage = { getItem: () => null, setItem() {}, removeItem() {}, };
gp.CONFIG.mock = true;
const mockData = await gp.searchAvailability({ arrivalDate: "2026-10-01", departureDate: "2026-10-03", numAdults: 2 });
const mockRows = gp.flattenAvailability(mockData);
console.log((mockRows.length === 8 ? "PASS" : "FAIL") + "  mock returns 8 room types  got=" + mockRows.length);
const mc = mockRows.find(r => r.slug === "cedar-cabin");
console.log((mc && mc.total > 0 ? "PASS" : "FAIL") + "  mock cedar has a total  got=" + (mc && mc.total));
console.log((mc && mc.sleeps === 6 ? "PASS" : "FAIL") + "  mock cedar sleeps  got=" + (mc && mc.sleeps));
console.log((mockRows.every(r => r.slug && !/^\s*$/.test(r.slug)) ? "PASS" : "FAIL") + "  every mock row has a slug");

// roomTypeId must be the slug (the front end keys off it), gpRoomTypeId the real id
console.log((cedar.roomTypeId === "cedar-cabin" ? "PASS" : "FAIL") + "  roomTypeId is the slug  got=" + cedar.roomTypeId);
console.log((cedar.gpRoomTypeId === "RT-CEDAR" ? "PASS" : "FAIL") + "  gpRoomTypeId is the GuestPoint id  got=" + cedar.gpRoomTypeId);
