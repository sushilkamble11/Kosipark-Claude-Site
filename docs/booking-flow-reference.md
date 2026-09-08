# GuestPoint Booking Engine API — booking flow reference

Base URL: `https://beapi.guestpoint.dev/api/v1` (dev)
Auth: `X-API-KEY` header. Backend only — never in browser code.

## Website booking journey

This is one booking flow, implemented in `public_html/Availability.dc.html`,
`public_html/Calendar.dc.html`, `public_html/Checkout.dc.html` and the shared
`public_html/guestpoint.js` API layer. Do not create a second booking engine or
a separate sold-out page.

When an exact-date search has no sellable accommodation, the availability
results area becomes the recovery experience. It retains the requested stay
length and party, checks nearby arrivals in the order `-1, +1, -2, +2, -3,
+3`, and displays up to three genuinely bookable alternatives. A single
“Check alternate dates” disclosure shows all checked arrivals inline, using
green for normal availability, amber for one or two units left and grey for an
unavailable complete stay. Text labels accompany every colour.

Every alternative is a definitive `/availabilities` quote for the complete
stay. Its total is the sum of returned nightly rates and its scarcity is the
tightest nightly `ForSale` count. Selecting it reruns the normal search with
the same adults, children, infants and number of nights; it does not hold
inventory. Closed nights, arrival/departure restrictions and minimum stays
must never be presented as available.

The same flow continues to provide rate plans and genuine room upgrades on the
availability screen, followed by the existing adjacent-night quote and extras
in checkout. GuestPoint remains the sole source of rates, restrictions and
inventory. Never invent viewer counts, countdown urgency or scarcity.

## 1. Search availability

`GET /properties/{propertyId}/availabilities`

Get Room, Availability and Rate data for the given query parameters.

**Query / path parameters**

| Name | In | Required | Notes |
|---|---|---|---|
| `arrivalDate` | query | yes | Date of arrival (YYYY-MM-DD). |
| `departureDate` | query | yes | Date of departure (YYYY-MM-DD). Must be after arrivalDate. |
| `numAdults` | query | yes | Number of adults. Must be greater than 0. |
| `numChildren` | query | no | Number of children. Defaults to 0. |
| `calendarMode` | query | no | Include dates with no availability or closed rates. |
| `promoCode` | query | no | Promotion code. |
| `roomTypes` | query | no | Comma-separated list of room-type external ids to filter to. |
| `ratePlans` | query | no | Comma-separated list of rate-plan external ids to filter to. |
| `propertyGroupId` | query | no | Property group id. Only required when calling the group-scoped variant of this endpoint wi |

**Response `data`**

| Field | Type | Notes |
|---|---|---|
| `Properties` | array |  |
| `Properties[].Id` | string |  |
| `Properties[].Name` | string |  |
| `Properties[].Description` | string |  |
| `Properties[].CurrencyCode` | string |  |
| `Properties[].StreetAddress` |  |  |
| `Properties[].Contact` |  |  |
| `Properties[].Country` | string |  |
| `Properties[].RoomTypes` | array |  |
| `Properties[].PropertyImages` | array |  |
| `Properties[].Messages` | array |  |
| `Properties[].Facilities` | object | Map of facility category to a list of facility names. |
| `Properties[].Latitude` | string |  |
| `Properties[].Longitude` | string |  |
| `Properties[].ZoomLevel` | string |  |
| `Properties[].RatingType` | string |  |
| `Properties[].Rating` | string |  |

## 2. Calendar rates

`GET /properties/{propertyId}/bestavailablerates`

Returns the best available rate per day across a date range, suitable for driving calendar widgets. Each property entry contains a `Days` map keyed by date.

**Query / path parameters**

| Name | In | Required | Notes |
|---|---|---|---|
| `fromDate` | query | yes | Start of the range (YYYY-MM-DD). |
| `toDate` | query | yes | End of the range (YYYY-MM-DD). Must be after fromDate; maximum range is 365 nights. |
| `roomType` | query | no | Restrict to a single room-type external id. |
| `promoCode` | query | no | Promotion code. |
| `numAdults` | query | no | Number of adults. |
| `numChildren` | query | no | Number of children. |

**Response `data`**

| Field | Type | Notes |
|---|---|---|
| `Properties` | array |  |
| `Properties[].PropertyId` | string |  |
| `Properties[].PropertyName` | string |  |
| `Properties[].RoomTypeId` | string | Only present when the roomType query parameter is supplied. |
| `Properties[].RoomTypeName` | string | Only present when the roomType query parameter is supplied. |
| `Properties[].RatingType` | string |  |
| `Properties[].Rating` | string |  |
| `Properties[].Images` | array |  |
| `Properties[].Days` | object | Map keyed by date (YYYY-MM-DD). |

## 3. Promo code

`GET /properties/{propertyId}/promocodes/{code}`

Check whether a promo code is valid for the property. Returns an array of matching promotions (a single code may resolve to more than one applicable promotion).

**Query / path parameters**

| Name | In | Required | Notes |
|---|---|---|---|
| `code` | path | yes | The promo code to validate. |

## 4. Extras

`POST /properties/{propertyId}/extras`

Returns the extras (add-ons) that are eligible for the supplied room stays. Called for the guest-details step after room stays have been selected.

**Request body**

| Field | Type | Notes |
|---|---|---|
| `RoomStays` | array |  |
| `RoomStays[].Arrival` | string |  |
| `RoomStays[].Departure` | string |  |
| `RoomStays[].RoomTotal` | string |  |
| `RoomStays[].RoomTypeId` | string |  |
| `RoomStays[].RatePlanId` | string |  |
| `RoomStays[].Adults` | integer |  |
| `RoomStays[].Children` | integer |  |
| `RoomStays[].Infants` | integer |  |
| `RoomStays[].GuestName` | string |  |
| `RoomStays[].ExtraInfo` | string |  |
| `RoomStays[].RateDetails` | array |  |
| `RoomStays[].RateDetails[].RatePlanId` | string |  |
| `RoomStays[].RateDetails[].FromDate` | string |  |
| `RoomStays[].RateDetails[].ToDate` | string |  |
| `RoomStays[].RateDetails[].RoomRate` | string |  |
| `RoomStays[].RateDetails[].TaxIncluded` | boolean |  |
| `RoomStays[].RateDetails[].TaxAmount` | string |  |

## 5. Guest profile fields

`GET /properties/{propertyId}/beprofilefields`

Get the profile fields the property collects in addition to the standard guest details. Use these to build your booking form, then send the answers back as `ProfileFields` - on a guest for a `person`, `company` or `both` field, and on the reservation for a `reservation` field.

**Query / path parameters**

| Name | In | Required | Notes |
|---|---|---|---|
| `includeInactive` | query | no | Include fields that are no longer in use. Off by default. |

## 6. Validate reservation

`PATCH /properties/{propertyId}/reservations`

Validate the provided reservation to check that it can be created. If availability and restrictions are met, prices are recalculated and the validated reservation is returned, including a `VerificationCode` and payment details.

**Request body**

| Field | Type | Notes |
|---|---|---|
| `PropertyId` | string | **(required)**  |
| `ConfNum` | string |  |
| `Status` | string | One of Booked, Modified, Cancelled. |
| `ExtraInfo` | string |  |
| `Adults` | integer |  |
| `Children` | integer |  |
| `Infants` | integer |  |
| `PromoCode` | string |  |
| `VerificationCode` | string | Returned by Validate Reservation; required by Create when verification is enabled. |
| `ReservationTotal` | string |  |
| `DepositAmount` | string |  |
| `EstimatedArrival` | string |  |
| `MarketingOptIn` | boolean |  |
| `ConsentGiven` | boolean/null |  |
| `RoomStays` | array | **(required)**  |
| `RoomStays[].Arrival` | string | **(required)**  |
| `RoomStays[].Departure` | string | **(required)**  |
| `RoomStays[].RoomTotal` | string |  |
| `RoomStays[].ArrivalTime` | string |  |
| `RoomStays[].RoomTypeId` | string | **(required)**  |
| `RoomStays[].RoomTypeName` | string |  |
| `RoomStays[].RatePlanId` | string | Required on input; not returned on output. |
| `RoomStays[].PolicyId` | integer |  |
| `RoomStays[].Adults` | integer | **(required)**  |
| `RoomStays[].Children` | integer |  |
| `RoomStays[].Infants` | integer |  |
| `RoomStays[].GuestName` | string | **(required)**  |
| `RoomStays[].GuestId` | string | Matches an id in the reservation's Guests array. If omitted, the guest in the same positio |
| `RoomStays[].ExtraInfo` | string |  |
| `RoomStays[].IsCancelled` | boolean |  |
| `RoomStays[].RoomStayConfNum` | string |  |
| `RoomStays[].RateDetails` | array |  |
| `RoomStays[].Extras` | array |  |
| `RoomStays[].PolicyText` | string |  |
| `RoomStays[].BeddingConfigurationText` | string |  |
| `RoomStays[].Discounts` | array |  |
| `RoomStays[].RoomStayLevelDiscounts` | string |  |
| `RoomStays[].RoomTotalBeforeDiscount` | string |  |
| `Guests` | array | **(required)**  |
| `Guests[].ID` | string | **(required)** A unique id for this guest within the booking, used by `ReservationRoomStay.GuestId`. Shou |
| `Guests[].FirstName` | string |  |
| `Guests[].LastName` | string | **(required)**  |
| `Guests[].Email` | string | **(required)**  |
| `Guests[].Phone` | string |  |
| `Guests[].Mobile` | string |  |
| `Guests[].CompanyName` | string |  |
| `Guests[].Address` | string |  |
| `Guests[].City` | string |  |
| `Guests[].State` | string |  |
| `Guests[].PostalCode` | string |  |
| `Guests[].Country` | string |  |
| `Guests[].ProfileFields` | array |  |
| `BookingContact` | object |  |
| `BookingContact.ID` | string | **(required)** A unique id for this guest within the booking, used by `ReservationRoomStay.GuestId`. Shou |
| `BookingContact.FirstName` | string |  |
| `BookingContact.LastName` | string | **(required)**  |
| `BookingContact.Email` | string | **(required)**  |
| `BookingContact.Phone` | string |  |
| `BookingContact.Mobile` | string |  |
| `BookingContact.CompanyName` | string |  |
| `BookingContact.Address` | string |  |
| `BookingContact.City` | string |  |
| `BookingContact.State` | string |  |
| `BookingContact.PostalCode` | string |  |
| `BookingContact.Country` | string |  |
| `BookingContact.ProfileFields` | array |  |
| `Extras` | array |  |
| `Extras[].Id` | string |  |
| `Extras[].Name` | string |  |
| `Extras[].Nights` | integer |  |
| `Extras[].Counts` | array |  |
| `Extras[].Total` | string |  |
| `Extras[].Tax` | string |  |
| `PaymentCard` | object | Card details. On output only the non-sensitive fields (CardHolderName, CardNumberMask, Car |
| `PaymentCard.CardHolderName` | string |  |
| `PaymentCard.CardNumberToken` | string |  |
| `PaymentCard.CardNumberMask` | string |  |
| `PaymentCard.CvvToken` | string |  |
| `PaymentCard.CardType` | string |  |
| `PaymentCard.CardExpiry` | string | Format mm/yy. |
| `Payment` | array |  |
| `Payment[].Type` | string | Constant "payment". |
| `Payment[].Status` | string | Constant "Verified". |
| `Payment[].Amount` | string |  |
| `Payment[].Surcharge` | string |  |
| `Payment[].SurchargeTaxAmount` | string |  |
| `Payment[].Currency` | string |  |
| `Payment[].Reference` | string |  |
| `Payment[].ProviderResponse` |  |  |
| `Payment[].Id` | string |  |
| `ProfileFields` | array |  |
| `ProfileFields[].Id` | string | The `ExternalId` of the profile field being answered. |
| `ProfileFields[].Name` | string |  |
| `ProfileFields[].Type` | string | The `FieldType` of the profile field being answered. |
| `ProfileFields[].Value` | string | For a `lookup` field this is the `ExternalId` of the chosen `ProfileFieldValue`, otherwise |
| `TraceId` | string |  |

**Response `data`**

| Field | Type | Notes |
|---|---|---|
| `ID` | integer |  |
| `PropertyId` | string |  |
| `ChannelCode` | string |  |
| `SalesChannelCode` | string |  |
| `CurrencyCode` | string |  |
| `Status` | string |  |
| `SaleTime` | string |  |
| `ReceivedTime` | string |  |
| `CancelTime` | string | Omitted if the reservation has not been cancelled. |
| `ModifyTime` | string | Omitted if the reservation has not been modified. |
| `ExtraInfo` | string |  |
| `ConfNum` | string |  |
| `Adults` | integer |  |
| `Children` | integer |  |
| `Infants` | integer |  |
| `ReservationTotal` | string | Total before tax. |
| `ReservationTotalTax` | string |  |
| `ReservationTotalAfterTax` | string |  |
| `Surcharge` | string |  |
| `DepositAmount` | string |  |
| `EstimatedArrival` | string |  |
| `MarketingOptIn` | boolean |  |
| `ConsentGiven` | boolean/null |  |
| `RoomStays` | array |  |
| `RoomStays[].Arrival` | string | **(required)**  |
| `RoomStays[].Departure` | string | **(required)**  |
| `RoomStays[].RoomTotal` | string |  |
| `RoomStays[].ArrivalTime` | string |  |
| `RoomStays[].RoomTypeId` | string | **(required)**  |
| `RoomStays[].RoomTypeName` | string |  |
| `RoomStays[].RatePlanId` | string | Required on input; not returned on output. |
| `RoomStays[].PolicyId` | integer |  |
| `RoomStays[].Adults` | integer | **(required)**  |
| `RoomStays[].Children` | integer |  |
| `RoomStays[].Infants` | integer |  |
| `RoomStays[].GuestName` | string | **(required)**  |
| `RoomStays[].GuestId` | string | Matches an id in the reservation's Guests array. If omitted, the guest in the same positio |
| `RoomStays[].ExtraInfo` | string |  |
| `RoomStays[].IsCancelled` | boolean |  |
| `RoomStays[].RoomStayConfNum` | string |  |
| `RoomStays[].RateDetails` | array |  |
| `RoomStays[].Extras` | array |  |
| `RoomStays[].PolicyText` | string |  |
| `RoomStays[].BeddingConfigurationText` | string |  |
| `RoomStays[].Discounts` | array |  |
| `RoomStays[].RoomStayLevelDiscounts` | string |  |
| `RoomStays[].RoomTotalBeforeDiscount` | string |  |
| `Guests` | array |  |
| `Guests[].ID` | string | **(required)** A unique id for this guest within the booking, used by `ReservationRoomStay.GuestId`. Shou |
| `Guests[].FirstName` | string |  |
| `Guests[].LastName` | string | **(required)**  |
| `Guests[].Email` | string | **(required)**  |
| `Guests[].Phone` | string |  |
| `Guests[].Mobile` | string |  |
| `Guests[].CompanyName` | string |  |
| `Guests[].Address` | string |  |
| `Guests[].City` | string |  |
| `Guests[].State` | string |  |
| `Guests[].PostalCode` | string |  |
| `Guests[].Country` | string |  |

## 7. Create reservation

`POST /properties/{propertyId}/reservations`

Create a reservation for your channel. When verification is enabled, the `VerificationCode` returned by Validate Reservation must be supplied and must match the `ConfNum`, otherwise the reservation is rejected as expired.

**Request body**

| Field | Type | Notes |
|---|---|---|
| `PropertyId` | string | **(required)**  |
| `ConfNum` | string |  |
| `Status` | string | One of Booked, Modified, Cancelled. |
| `ExtraInfo` | string |  |
| `Adults` | integer |  |
| `Children` | integer |  |
| `Infants` | integer |  |
| `PromoCode` | string |  |
| `VerificationCode` | string | Returned by Validate Reservation; required by Create when verification is enabled. |
| `ReservationTotal` | string |  |
| `DepositAmount` | string |  |
| `EstimatedArrival` | string |  |
| `MarketingOptIn` | boolean |  |
| `ConsentGiven` | boolean/null |  |
| `RoomStays` | array | **(required)**  |
| `RoomStays[].Arrival` | string | **(required)**  |
| `RoomStays[].Departure` | string | **(required)**  |
| `RoomStays[].RoomTotal` | string |  |
| `RoomStays[].ArrivalTime` | string |  |
| `RoomStays[].RoomTypeId` | string | **(required)**  |
| `RoomStays[].RoomTypeName` | string |  |
| `RoomStays[].RatePlanId` | string | Required on input; not returned on output. |
| `RoomStays[].PolicyId` | integer |  |
| `RoomStays[].Adults` | integer | **(required)**  |
| `RoomStays[].Children` | integer |  |
| `RoomStays[].Infants` | integer |  |
| `RoomStays[].GuestName` | string | **(required)**  |
| `RoomStays[].GuestId` | string | Matches an id in the reservation's Guests array. If omitted, the guest in the same positio |
| `RoomStays[].ExtraInfo` | string |  |
| `RoomStays[].IsCancelled` | boolean |  |
| `RoomStays[].RoomStayConfNum` | string |  |
| `RoomStays[].RateDetails` | array |  |
| `RoomStays[].Extras` | array |  |
| `RoomStays[].PolicyText` | string |  |
| `RoomStays[].BeddingConfigurationText` | string |  |
| `RoomStays[].Discounts` | array |  |
| `RoomStays[].RoomStayLevelDiscounts` | string |  |
| `RoomStays[].RoomTotalBeforeDiscount` | string |  |
| `Guests` | array | **(required)**  |
| `Guests[].ID` | string | **(required)** A unique id for this guest within the booking, used by `ReservationRoomStay.GuestId`. Shou |
| `Guests[].FirstName` | string |  |
| `Guests[].LastName` | string | **(required)**  |
| `Guests[].Email` | string | **(required)**  |
| `Guests[].Phone` | string |  |
| `Guests[].Mobile` | string |  |
| `Guests[].CompanyName` | string |  |
| `Guests[].Address` | string |  |
| `Guests[].City` | string |  |
| `Guests[].State` | string |  |
| `Guests[].PostalCode` | string |  |
| `Guests[].Country` | string |  |
| `Guests[].ProfileFields` | array |  |
| `BookingContact` | object |  |
| `BookingContact.ID` | string | **(required)** A unique id for this guest within the booking, used by `ReservationRoomStay.GuestId`. Shou |
| `BookingContact.FirstName` | string |  |
| `BookingContact.LastName` | string | **(required)**  |
| `BookingContact.Email` | string | **(required)**  |
| `BookingContact.Phone` | string |  |
| `BookingContact.Mobile` | string |  |
| `BookingContact.CompanyName` | string |  |
| `BookingContact.Address` | string |  |
| `BookingContact.City` | string |  |
| `BookingContact.State` | string |  |
| `BookingContact.PostalCode` | string |  |
| `BookingContact.Country` | string |  |
| `BookingContact.ProfileFields` | array |  |
| `Extras` | array |  |
| `Extras[].Id` | string |  |
| `Extras[].Name` | string |  |
| `Extras[].Nights` | integer |  |
| `Extras[].Counts` | array |  |
| `Extras[].Total` | string |  |
| `Extras[].Tax` | string |  |
| `PaymentCard` | object | Card details. On output only the non-sensitive fields (CardHolderName, CardNumberMask, Car |
| `PaymentCard.CardHolderName` | string |  |
| `PaymentCard.CardNumberToken` | string |  |
| `PaymentCard.CardNumberMask` | string |  |
| `PaymentCard.CvvToken` | string |  |
| `PaymentCard.CardType` | string |  |
| `PaymentCard.CardExpiry` | string | Format mm/yy. |
| `Payment` | array |  |
| `Payment[].Type` | string | Constant "payment". |
| `Payment[].Status` | string | Constant "Verified". |
| `Payment[].Amount` | string |  |
| `Payment[].Surcharge` | string |  |
| `Payment[].SurchargeTaxAmount` | string |  |
| `Payment[].Currency` | string |  |
| `Payment[].Reference` | string |  |
| `Payment[].ProviderResponse` |  |  |
| `Payment[].Id` | string |  |
| `ProfileFields` | array |  |
| `ProfileFields[].Id` | string | The `ExternalId` of the profile field being answered. |
| `ProfileFields[].Name` | string |  |
| `ProfileFields[].Type` | string | The `FieldType` of the profile field being answered. |
| `ProfileFields[].Value` | string | For a `lookup` field this is the `ExternalId` of the chosen `ProfileFieldValue`, otherwise |
| `TraceId` | string |  |

**Response `data`**

| Field | Type | Notes |
|---|---|---|
| `ID` | integer |  |
| `PropertyId` | string |  |
| `ChannelCode` | string |  |
| `SalesChannelCode` | string |  |
| `CurrencyCode` | string |  |
| `Status` | string |  |
| `SaleTime` | string |  |
| `ReceivedTime` | string |  |
| `CancelTime` | string | Omitted if the reservation has not been cancelled. |
| `ModifyTime` | string | Omitted if the reservation has not been modified. |
| `ExtraInfo` | string |  |
| `ConfNum` | string |  |
| `Adults` | integer |  |
| `Children` | integer |  |
| `Infants` | integer |  |
| `ReservationTotal` | string | Total before tax. |
| `ReservationTotalTax` | string |  |
| `ReservationTotalAfterTax` | string |  |
| `Surcharge` | string |  |
| `DepositAmount` | string |  |
| `EstimatedArrival` | string |  |
| `MarketingOptIn` | boolean |  |
| `ConsentGiven` | boolean/null |  |
| `RoomStays` | array |  |
| `RoomStays[].Arrival` | string | **(required)**  |
| `RoomStays[].Departure` | string | **(required)**  |
| `RoomStays[].RoomTotal` | string |  |
| `RoomStays[].ArrivalTime` | string |  |
| `RoomStays[].RoomTypeId` | string | **(required)**  |
| `RoomStays[].RoomTypeName` | string |  |
| `RoomStays[].RatePlanId` | string | Required on input; not returned on output. |
| `RoomStays[].PolicyId` | integer |  |
| `RoomStays[].Adults` | integer | **(required)**  |
| `RoomStays[].Children` | integer |  |
| `RoomStays[].Infants` | integer |  |
| `RoomStays[].GuestName` | string | **(required)**  |
| `RoomStays[].GuestId` | string | Matches an id in the reservation's Guests array. If omitted, the guest in the same positio |
| `RoomStays[].ExtraInfo` | string |  |
| `RoomStays[].IsCancelled` | boolean |  |
| `RoomStays[].RoomStayConfNum` | string |  |
| `RoomStays[].RateDetails` | array |  |
| `RoomStays[].Extras` | array |  |
| `RoomStays[].PolicyText` | string |  |
| `RoomStays[].BeddingConfigurationText` | string |  |
| `RoomStays[].Discounts` | array |  |
| `RoomStays[].RoomStayLevelDiscounts` | string |  |
| `RoomStays[].RoomTotalBeforeDiscount` | string |  |
| `Guests` | array |  |
| `Guests[].ID` | string | **(required)** A unique id for this guest within the booking, used by `ReservationRoomStay.GuestId`. Shou |
| `Guests[].FirstName` | string |  |
| `Guests[].LastName` | string | **(required)**  |
| `Guests[].Email` | string | **(required)**  |
| `Guests[].Phone` | string |  |
| `Guests[].Mobile` | string |  |
| `Guests[].CompanyName` | string |  |
| `Guests[].Address` | string |  |
| `Guests[].City` | string |  |
| `Guests[].State` | string |  |
| `Guests[].PostalCode` | string |  |
| `Guests[].Country` | string |  |
