<?php
/**
 * Fake GuestPoint upstream for the proxy integration harness.
 *
 * Serves three APIs the real proxy talks to, on one port:
 *   /be/...    Booking Engine v2   (reservations/manage, extras)
 *   /core/...  GuestPoint Core v1  (reservation search)
 *   /pms/...   Phoenix WebAPI      (token, allocations, accounts, card vault)
 *
 * Payloads are spec-shaped, not copied from guestpoint.js's mock: the mock has
 * historically been kinder than the real API and hidden real bugs. In
 * particular PaymentRequired is "0" while PayLater carries the balance, and
 * numerics arrive as strings.
 *
 * All mutable state lives in $HARNESS_DIR/state.json so the driving test can
 * inspect exactly what was written. $HARNESS_DIR/mode.txt selects a failure
 * mode; see MODES below.
 *
 * MODES
 *   (empty)          everything succeeds
 *   declined         ProxyPost answers IsPaymentProcessed:false  (definitive)
 *   rollback-reject  the card declines and the rollback save is refused too
 *   gateway-500      ProxyPost answers HTTP 500                  (indeterminate)
 *   gateway-timeout  ProxyPost never answers                     (indeterminate)
 *   no-postback      charge succeeds, Phoenix posts no payment row
 *   slow-postback    charge succeeds, payment row appears on a later read
 *   ignore-profiles  SaveRoomAllocationEdit… drops the _Profiles side-car
 *   expiry-object    GetHasCcMapExpired answers {"HasExpired":false}
 *   no-card          the reservation has no stored card
 *   cancel-reject    SaveRoomAllocationWithVirtualRooms answers HTTP 500
 *   account-reject   SaveTransactionItemDetails answers HTTP 500
 */

$DIR = getenv('HARNESS_DIR') ?: __DIR__;
$STATE = $DIR . '/state.json';
$MODE_FILE = $DIR . '/mode.txt';
$LOG = $DIR . '/calls.log';

function state(): array { global $STATE; return json_decode((string)@file_get_contents($STATE), true) ?: []; }
function save(array $s): void { global $STATE; file_put_contents($STATE, json_encode($s, JSON_PRETTY_PRINT), LOCK_EX); }
function mode(): string { global $MODE_FILE; return trim((string)@file_get_contents($MODE_FILE)); }
function reply($payload, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

/** PHP mangles dots in $_GET keys; GuestPoint's payment params need them intact. */
function query(): array {
    $out = [];
    foreach (explode('&', (string)($_SERVER['QUERY_STRING'] ?? '')) as $pair) {
        if ($pair === '') continue;
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        $out[urldecode($k)] = urldecode($v);
    }
    return $out;
}

$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$raw = file_get_contents('php://input');
$s = state();
$mode = mode();

// Every upstream call is logged so a test can assert on call counts.
file_put_contents($LOG, $_SERVER['REQUEST_METHOD'] . ' ' . $path . "\n", FILE_APPEND | LOCK_EX);

$RA = (string)($s['roomAllocationId'] ?? '');
$RID = (string)($s['reservationId'] ?? '');

// ---------------------------------------------------------- Phoenix token ---
if ($path === '/pms/token') reply(['access_token' => 'harness-token', 'expires_in' => 3600]);

// ------------------------------------------------------------- Core search ---
if (str_starts_with($path, '/core/v1/reservations')) {
    reply(['Data' => [[
        'ReservationId' => $RID,
        'ReservationNumber' => $s['reservationNumber'] ?? 'R1001',
        'PropertyId' => $s['propertyId'] ?? 'PROP-HARNESS',
        'AmountOutstanding' => '0',
        'Allocations' => [[
            'RoomAllocationId' => $RA, 'IsPrimary' => true,
            'GuestLastName' => $s['surname'] ?? 'Smith',
            'GuestEmail' => $s['email'] ?? 'guest@example.invalid',
            'DepartureBalance' => '0',
        ]],
    ]]]);
}

// ----------------------------------------------------------- Booking Engine ---
if (preg_match('#^/be/properties/[^/]+/reservations/manage$#', $path)) {
    reply(['success' => true, 'data' => [
        'Reservation' => [
            'ID' => $RID,
            'ConfNum' => $s['reservationNumber'] ?? 'R1001',
            'ReservationTotalAfterTax' => (string)($s['bookingTotal'] ?? 300),
            // Spec shape: the balance sits in PayLater, PaymentRequired is "0".
            'PaymentRequired' => '0',
            'PayLater' => (string)($s['payLater'] ?? 0),
            'RoomStays' => [[
                'Arrival' => $s['arrival'], 'Departure' => $s['departure'],
                'RoomTypeId' => 'RT1', 'Adults' => (string)$s['adults'],
                'Children' => (string)$s['children'], 'Infants' => '0', 'IsCancelled' => false,
                'RoomTotal' => (string)($s['roomTotal'] ?? 300),
                'RateDetails' => [[
                    'RatePlanId' => 'RP1', 'RatePlanName' => 'Standard rate',
                    'RoomRate' => (string)($s['roomTotal'] ?? 300),
                    'CancelRule' => $s['cancelRule'] ?? null,
                ]],
            ]],
            'Guests' => [['ID' => 'G1', 'FirstName' => 'Ada', 'LastName' => $s['surname'] ?? 'Smith', 'Email' => $s['email'] ?? 'guest@example.invalid']],
            'BookingContact' => ['ID' => 'G1'],
            'ExtraInfo' => 'Staff note: never show this to a guest.',
        ],
        'Login' => ['ViewReservation' => true],
    ]]);
}
if (preg_match('#^/be/properties/[^/]+/extras$#', $path)) reply($s['catalog'] ?? []);

// Availability and nightly rates for the booked room type. The amend path asks
// for these before it will move a booking's dates, so without them no amendment
// test could run at all. Shapes follow booking-engine-api-v2.yaml: one entry
// per night, numerics as strings.
if (preg_match('#^/be/properties/[^/]+/availabilities$#', $path)) {
    $q = query();
    $from = (string)($q['arrivalDate'] ?? '');
    $to = (string)($q['departureDate'] ?? '');
    $nightlyRate = (string)($s['nightlyRate'] ?? 100);
    $nights = [];
    $rates = [];
    try {
        $cursor = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        while ($cursor < $end) {
            $date = $cursor->format('Y-m-d');
            $nights[] = ['Date' => $date, 'ForSale' => (string)($s['forSale'] ?? 3), 'Closed' => !empty($s['closed'])];
            $rates[] = ['Date' => $date, 'SellRate' => $nightlyRate, 'Closed' => !empty($s['rateClosed'])];
            $cursor = $cursor->modify('+1 day');
        }
    } catch (Throwable $e) { reply(['Message' => 'bad dates'], 400); }
    reply(['data' => ['Properties' => [[
        'Id' => $s['propertyId'] ?? 'PROP-HARNESS',
        'RoomTypes' => [[
            'Id' => 'RT1', 'Name' => 'Cedar Cabin', 'MaxGuests' => (int)($s['maxGuests'] ?? 6),
            'Availabilities' => $nights,
            'RatePlans' => [['Id' => 'RP1', 'Name' => 'Standard rate', 'Rates' => $rates]],
        ]],
    ]]]]);
}

// ---------------------------------------------------------- Phoenix WebAPI ---
if (str_starts_with($path, '/pms/')) {
    $p = substr($path, 5);
    $q = query();
    $now = date('Y-m-d\TH:i:s');

    if (str_starts_with($p, 'Reservation/GetRoomAllocationAddons')) reply($s['futureAddons'] ?? []);

    if (str_starts_with($p, 'Reservation/GetRoomAllocationChargesForReservationByRoomAllocationID')) {
        // One row per night, each dated: the amend path rewrites these in place
        // and appends from the first as a template, so a single undated row
        // could never exercise it.
        $rows = [];
        $perNight = round((float)($s['roomTotal'] ?? 300) / max(1, (int)($s['nights'] ?? 1)), 2);
        try {
            $cursor = new DateTimeImmutable((string)$s['arrival']);
            for ($i = 0; $i < max(1, (int)($s['nights'] ?? 1)); $i++) {
                $rows[] = ['RoomAllocationChargeID' => 'CHG-' . $i, 'RoomAllocationID' => $RA,
                           'Date' => $cursor->format('Y-m-d') . 'T00:00:00', 'Room' => $perNight,
                           'ExtraPersons' => 0, 'Discount' => 0];
                $cursor = $cursor->modify('+1 day');
            }
        } catch (Throwable $e) { $rows = [['RoomAllocationChargeID' => 'CHG-0', 'Room' => $perNight, 'ExtraPersons' => 0, 'Discount' => 0]]; }
        reply($rows);
    }

    if (str_starts_with($p, 'Reservation/SaveRoomAllocationCharges')) {
        $s['savedCharges'] = json_decode((string)$raw, true) ?: [];
        save($s);
        reply($s['savedCharges']);
    }

    if (str_starts_with($p, 'Reservation/SaveRoomAllocationDetail2sWithVirtualRooms')) {
        $sent = json_decode((string)$raw, true);
        if (is_array($sent) && is_array($sent[0] ?? null)) {
            // Phoenix persists the amended stay; later reads must show it, or
            // the proxy's own verification step would pass on stale data.
            if (isset($sent[0]['ArrivalDate'])) $s['arrival'] = substr((string)$sent[0]['ArrivalDate'], 0, 10);
            if (isset($sent[0]['NumberOfNights'])) $s['nights'] = (int)$sent[0]['NumberOfNights'];
            if (isset($sent[0]['NumberAdults'])) $s['adults'] = (int)$sent[0]['NumberAdults'];
            if (isset($sent[0]['NumberChildren'])) $s['children'] = (int)$sent[0]['NumberChildren'];
            try {
                $s['departure'] = (new DateTimeImmutable((string)$s['arrival']))->modify('+' . max(1, (int)$s['nights']) . ' days')->format('Y-m-d');
            } catch (Throwable $e) { /* leave as-is */ }
            save($s);
        }
        reply($sent ?: []);
    }

    if (str_starts_with($p, 'Reservation/GetRoomAllocationDetail2sByReservation'))
        // RoomAllocationID and Status are what the amend path checks before it
        // will touch a booking. Leaving them out meant no amend test could get
        // past the first guard, so that whole flow went untested.
        reply([[ 'RoomAllocationID' => $RA, 'Status' => !empty($s['cancelled']) ? 4 : 1,
                 'ArrivalDate' => $s['arrival'], 'NumberOfNights' => $s['nights'], 'RoomTypeID' => 'RT1',
                 'PackageID' => 'RP1', 'NumberAdults' => $s['adults'], 'NumberChildren' => $s['children'], 'NumberInfants' => 0 ]]);

    if (str_starts_with($p, 'Reservation/GetRoomAllocationDetail') || str_starts_with($p, 'Reservation/GetRoomAllocation'))
        reply([
            // No PersonID here on purpose: the real allocation record has
            // none. Supplying one let the proxy read a field that does not
            // exist upstream and still pass. It comes from the financial
            // detail's _Persons list instead.
            'RoomAllocationID' => $RA,
            'Status' => !empty($s['cancelled']) ? 4 : 1,
            'ArrivalDate' => $s['arrival'], 'DepartureDate' => $s['departure'],
            'NumberOfNights' => $s['nights'], 'NumberAdults' => $s['adults'],
            'NumberChildren' => $s['children'], 'NumberInfants' => 0,
            'DepartureBalance' => 0, 'Eta' => $s['eta'] ?? '03:00 PM',
            '_Profiles' => $s['profiles'] ?? [],
        ]);

    if (str_starts_with($p, 'Reservation/GetReservationDetailByRoomAllocationWithCurrentPackage')) {
        // The person a charge or payment is posted against lives here, nested
        // under _RoomAllocations[]._Persons[] — NOT on the room allocation
        // record, which has no PersonID at all. Modelled from the Phoenix wire
        // capture so the proxy cannot pass a test by reading a flat field the
        // real API never returns.
        $persons = ['_RoomAllocations' => [[
            'RoomAllocationID' => $RA,
            '_Persons' => [['PersonID' => $s['personId'] ?? 'P1']],
        ]]];
        if ($mode === 'no-card') reply(array_merge(['ReservationNumber' => $s['reservationNumber'] ?? 'R1001'], $persons));
        reply(array_merge([
            'ReservationNumber' => $s['reservationNumber'] ?? 'R1001',
            'CCNumberToken' => 'CCMAP-HARNESS', 'CCPartialNumber' => '411111****1111',
            'CCExpiry' => '12/30', 'CCTransactionAccountID' => 'ACCT-CC', 'CCName' => 'A SMITH',
        ], $persons));
    }

    if (str_starts_with($p, 'Reservation/ValidateCancellation')) reply(true);
    if (str_starts_with($p, 'Accounts/CalcBookingValue')) reply($s['bookingTotal'] ?? 300);
    if (str_starts_with($p, 'Accounts/CalcRoomAccountBalance')) reply(0);
    if (str_starts_with($p, 'Accounts/CalculateDepartureValue')) reply($s['departureValue'] ?? 0);

    if (str_starts_with($p, 'Reservation/SaveRoomAllocationWithVirtualRooms')) {
        if ($mode === 'cancel-reject') reply(['Message' => 'rejected'], 500);
        $s['cancelled'] = true; save($s);
        reply(json_decode($raw, true));
    }

    if (str_starts_with($p, 'Reservation/SaveRoomAllocationEditWithVirtualRooms')) {
        $body = json_decode($raw, true);
        $s['eta'] = $body['Eta'] ?? null;
        if ($mode !== 'ignore-profiles' && isset($body['_Profiles'])) $s['profiles'] = $body['_Profiles'];
        $s['allocationSaves'] = ($s['allocationSaves'] ?? 0) + 1;
        save($s);
        reply($body);
    }

    if (str_starts_with($p, 'CreditCardVault/GetHasCcMapExpired'))
        reply($mode === 'expiry-object' ? ['HasExpired' => false] : false);

    if (str_starts_with($p, 'CreditCardVault/ProcessPaymentUsingProxyPost')) {
        $amount = (float)($q['creditCardPaymentInfo.amount'] ?? 0);
        $s['paymentAttempts'][] = ['amount' => $amount, 'mode' => $mode];
        // Long enough to blow the proxy's 20s UPSTREAM_TIMEOUT, short enough
        // that the run is not held hostage if a worker is still in it.
        if ($mode === 'gateway-timeout') { save($s); sleep(30); exit; }
        if ($mode === 'gateway-500') { save($s); reply(['Message' => 'gateway error'], 500); }
        if ($mode === 'declined' || $mode === 'rollback-reject') { save($s); reply(['IsPaymentProcessed' => false, 'Amount' => 0, 'ErrorMessage' => 'The card was declined.']); }
        $reference = 'GP' . str_pad((string)count($s['paymentAttempts']), 6, '0', STR_PAD_LEFT);
        $s['payments'][] = ['amount' => $amount, 'reference' => $reference];
        // The query string authorises the card; the BODY says which room
        // account the payment belongs to. Captured from the Phoenix client:
        // PersonID, RoomAllocationID, AppuserID and SelectedTransactionAccountID.
        // Without them GuestPoint has nothing to post its own payment row
        // against, so the gateway takes the money and the booking account never
        // shows it. The fake used to post the row regardless, which is why a
        // bodyless ProxyPost passed every test while failing in production.
        $payBody = json_decode((string)$raw, true);
        $attributed = is_array($payBody)
            && trim((string)($payBody['PersonID'] ?? '')) !== ''
            && trim((string)($payBody['RoomAllocationID'] ?? '')) !== ''
            && trim((string)($payBody['AppuserID'] ?? '')) !== ''
            && trim((string)($payBody['SelectedTransactionAccountID'] ?? '')) !== '';
        $s['lastPaymentAttributed'] = $attributed;
        if (!$attributed) {
            save($s);
            reply(['IsPaymentProcessed' => true, 'Amount' => $amount, 'TransactionId' => $reference, 'AuthCode' => 'AUTH1']);
        }
        // Phoenix posts its own payment line on the room account. The harness
        // reproduces that, because the proxy must NOT post a duplicate.
        $row = [
            'TransactionItemID' => 'pay-' . $reference, 'TransactionAccountID' => 'ACCT-CC',
            'TransactionType' => 11, 'RoomAllocationID' => $RA, 'PersonID' => 'P1',
            'AmountInc' => -$amount, 'Quantity' => 1, 'QuantityChild' => 0,
            'AccountingDate' => $now, 'PrintDate' => substr($now, 0, 10) . 'T00:00:00',
            'Description' => 'Credit Card ' . $reference,
        ];
        if ($mode === 'no-postback') { /* never appears */ }
        elseif ($mode === 'slow-postback') { $s['pendingRow'] = $row; }
        else { $s['tx'][] = $row; }
        save($s);
        reply(['IsPaymentProcessed' => true, 'Amount' => $amount, 'TransactionId' => $reference, 'AuthCode' => 'AUTH1']);
    }

    if (str_starts_with($p, 'User/GetAllAppusersByProperty')) {
        $s['appuserFetches'] = ($s['appuserFetches'] ?? 0) + 1; save($s);
        reply([['Username' => $s['pmsUsername'] ?? 'harness-user', 'Password' => 'ENCRYPTED-PROXY-PW',
                'AppuserID' => 'appuser-1']]);
    }

    if (str_starts_with($p, 'Accounts/GetTransactionItemDetailsByReservation')) {
        $s['transactionReads'] = ($s['transactionReads'] ?? 0) + 1;
        // slow-postback: the row surfaces only on the second read onwards.
        if (!empty($s['pendingRow']) && ($s['transactionReads'] ?? 0) >= (int)($s['postbackAfterReads'] ?? 99)) {
            $s['tx'][] = $s['pendingRow']; unset($s['pendingRow']);
        }
        save($s);
        reply($s['tx'] ?? []);
    }

    if (str_starts_with($p, 'Accounts/GetTransactionAccounts'))
        reply([
            ['TransactionAccountID' => 'ACCT-1', 'Name' => 'Sundry', 'TransactionType' => 2, 'TaxRate' => 0.1],
            ['TransactionAccountID' => 'ACCT-FEE', 'Name' => 'Cancellation Fees', 'TransactionType' => 2, 'TaxRate' => 0.1],
            ['TransactionAccountID' => 'ACCT-CC', 'Name' => 'Credit Card', 'TransactionType' => 11, 'TaxRate' => 0],
        ]);

    if (str_starts_with($p, 'Accounts/SaveTransactionItemDetails')) {
        if ($mode === 'account-reject') reply(['Message' => 'rejected'], 500);
        // The extras post succeeds, the rollback that follows a decline does not.
        if ($mode === 'rollback-reject' && ($s['accountSaves'] ?? 0) >= 1) reply(['Message' => 'rejected'], 500);
        $body = json_decode($raw, true);
        if (!is_array($body)) reply(['Message' => 'bad payload'], 400);
        $s['tx'] = $body;
        $s['accountSaves'] = ($s['accountSaves'] ?? 0) + 1;
        save($s);
        reply($body);
    }

    if (str_starts_with($p, 'Rates/GetAddons')) {
        $s['addonFetches'] = ($s['addonFetches'] ?? 0) + 1; save($s);
        reply($s['addons'] ?? []);
    }

    if (str_starts_with($p, 'Property/GetProfileFieldDetails'))
        reply([
            ['ProfileFieldID' => 'PF-REGO', 'Name' => 'Car Rego'],
            ['ProfileFieldID' => 'PF-DIM', 'Name' => 'Dimentions'],
            ['ProfileFieldID' => 'PF-NOTE', 'Name' => 'Reception notes'],
        ]);

    if (str_starts_with($p, 'Property/GetProfilesByReservation')) reply($s['profiles'] ?? []);

    reply(['Message' => 'unhandled Phoenix path', 'Path' => $p], 404);
}

reply(['Message' => 'unhandled path', 'Path' => $path], 404);
