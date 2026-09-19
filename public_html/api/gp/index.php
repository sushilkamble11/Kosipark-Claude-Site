<?php
declare(strict_types=1);

/**
 * GuestPoint Booking Engine proxy for kosipark.com.au
 * ---------------------------------------------------
 * The browser calls /api/gp/properties/{propertyId}/... — this file adds the
 * X-API-KEY header and forwards to GuestPoint. The key never leaves the server.
 *
 * Three jobs beyond forwarding:
 *   1. Allowlist. Only the endpoints the site actually uses get through,
 *      with only the methods they support. A leaked front-end cannot be turned
 *      into a general-purpose GuestPoint client.
 *   2. Cache. GETs are cached on disk with the same TTLs the browser uses, so
 *      a hundred visitors hitting the same month cost GuestPoint one call.
 *   3. Property pinning. The propertyId in the path must match the configured
 *      one, so the key cannot be pointed at someone else's property.
 *
 * Reservation calls (PATCH/POST reservations) are never cached and are rate
 * limited per IP.
 */

// ---------------------------------------------------------------- config ----

$configFile = __DIR__ . '/config.php';
$config = is_file($configFile) ? require $configFile : require __DIR__ . '/config.sample.php';

$API_KEY     = (string)($config['api_key'] ?? '');
$PROPERTY_ID = (string)($config['property_id'] ?? '');
$UPSTREAM    = rtrim((string)($config['upstream'] ?? 'https://beapi.guestpoint.dev/api/v1'), '/');
$CORE_KEY    = (string)($config['core_api_key'] ?? $API_KEY);
$CORE_UPSTREAM = rtrim((string)($config['core_upstream'] ?? ''), '/');
$PMS_PRIVATE_WRITES = (bool)($config['pms_private_writes'] ?? false);
$PMS_UPSTREAM = rtrim((string)($config['pms_upstream'] ?? 'https://dev.guestpoint.com/WebAPI'), '/');
$PMS_SERIAL = trim((string)($config['pms_serial'] ?? ''));
$PMS_USERNAME = trim((string)($config['pms_username'] ?? ''));
$PMS_PASSWORD = (string)($config['pms_password'] ?? '');
$NOTIFICATION_EMAIL = trim((string)($config['notification_email'] ?? ''));
$SMTP_HOST = trim((string)($config['smtp_host'] ?? 'smtp.hostinger.com'));
$SMTP_PORT = (int)($config['smtp_port'] ?? 465);
$SMTP_USERNAME = trim((string)($config['smtp_username'] ?? ''));
$SMTP_PASSWORD = (string)($config['smtp_password'] ?? '');
$OTP_FROM_EMAIL = trim((string)($config['otp_from_email'] ?? $SMTP_USERNAME));
$OTP_FROM_NAME = trim((string)($config['otp_from_name'] ?? 'Kosciuszko Tourist Park'));
$DEBUG       = (bool)($config['debug'] ?? false);

$CACHE_DIR = (string)($config['cache_dir'] ?? '');
if ($CACHE_DIR === '' || (!is_dir($CACHE_DIR) && !@mkdir($CACHE_DIR, 0770, true) && !is_dir($CACHE_DIR))) {
    $CACHE_DIR = sys_get_temp_dir() . '/gp-cache';
    @mkdir($CACHE_DIR, 0770, true);
}

/**
 * endpoint => [allowed methods, cache TTL in seconds (0 = never cache)]
 * Keys are matched against the path *after* /properties/{propertyId}/.
 * A trailing * matches one further path segment (promocodes/{code}).
 */
const ROUTES = [
    // Browsing — cached, since many visitors ask the same questions.
    'availabilities'        => ['GET',            5 * 60],
    'bestavailablerates'    => ['GET',           15 * 60],
    'promocodes/*'          => ['GET',           10 * 60],
    'extras'                => ['POST',          15 * 60],
    'beprofilefields'       => ['GET',      24 * 60 * 60],
    // Booking — validate (PATCH) and create (POST). Never cached.
    'reservations'          => ['PATCH,POST',         0],
    // Portal writes go through a signed, short-lived booking session. Never
    // expose reservations/manage directly: confirmation details are resolved
    // and checked by the server-side portal lookup.
    'portal/update'         => ['POST',               0],
    'portal/amend'          => ['POST',               0],
    'portal/extras/quote'   => ['POST',               0],
    'portal/extras'         => ['POST',               0],
    'portal/cancel'         => ['POST',               0],
    // Guest access uses the reference and surname held by GuestPoint.
    'portal/lookup'         => ['POST',               0],
];

const MAX_BODY_BYTES      = 256 * 1024;   // a reservation is a few KB
const UPSTREAM_TIMEOUT    = 20;           // seconds
// A lookup, quote and final mutation are separate requests. Twelve requests
// made normal use look like abuse (and is especially harsh behind hotel/NAT
// networks), so retain the five-minute window but allow several complete
// booking-management flows.
const RESERVATION_LIMIT   = 60;           // per IP
const RESERVATION_WINDOW  = 300;          // seconds
// How long to wait for GuestPoint to post the room-account row for a charge it
// has already confirmed. Short on purpose: exceeding it reports the payment as
// pending verification, never as a failure.
const PAYMENT_VERIFY_BUDGET = 3.0;        // seconds
// Abuse guard only. The meaningful limit is the size of the eligible extras
// catalogue, which portalExtrasPlan checks once it has fetched it.
const MAX_EXTRAS_ITEMS    = 200;
// Wall-clock budget for one request. Hostinger's shared PHP and LiteSpeed both
// stop a long request without warning, and the dangerous place to be stopped is
// between charging a card and recording it. Work that moves money checks there
// is time left to finish before it starts.
const REQUEST_BUDGET      = 45.0;         // seconds
$REQUEST_STARTED = microtime(true);

// ----------------------------------------------------------- small utils ----

function send(int $status, $payload, array $headers = []) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $k => $v) header("$k: $v");
    echo is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $status, string $message, ?string $detail = null) {
    global $DEBUG;
    $body = ['Error' => ['Code' => $status, 'Message' => $message]];
    if ($DEBUG && $detail !== null) $body['Error']['Detail'] = $detail;
    send($status, $body);
}

function clientIp(): string {
    // Forwarded headers are client-controlled unless the origin verifies its
    // proxy. Hostinger serves this endpoint directly, so use the socket peer
    // and do not let a caller bypass limits by inventing X-Forwarded-For.
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    return '0.0.0.0';
}

function normaliseMobile(string $value): string {
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (str_starts_with($digits, '0011')) $digits = substr($digits, 4);
    if (str_starts_with($digits, '04')) $digits = '61' . substr($digits, 1);
    if (strlen($digits) === 9 && str_starts_with($digits, '4')) $digits = '61' . $digits;
    return $digits;
}

function b64urlEncode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function b64urlDecode(string $value): string|false {
    $pad = strlen($value) % 4;
    if ($pad) $value .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function portalSecret(): string {
    global $API_KEY, $CORE_KEY, $PROPERTY_ID;
    return hash('sha256', $API_KEY . '|' . $CORE_KEY . '|' . $PROPERTY_ID, true);
}

function issuePortalToken(string $confNum, string $reservationId, string $surname, string $email, array $quote): string {
    $payload = json_encode([
        'ref' => $confNum,
        'rid' => $reservationId,
        'sn' => $surname,
        'em' => $email,
        'cq' => $quote,
        'exp' => time() + 30 * 60,
    ], JSON_UNESCAPED_SLASHES);
    $encoded = b64urlEncode((string)$payload);
    return $encoded . '.' . b64urlEncode(hash_hmac('sha256', $encoded, portalSecret(), true));
}

function portalTokenPayload(string $token, string $confNum): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 2) return null;
    [$encoded, $signature] = $parts;
    $expected = b64urlEncode(hash_hmac('sha256', $encoded, portalSecret(), true));
    if (!hash_equals($expected, $signature)) return null;
    $json = b64urlDecode($encoded);
    $payload = $json === false ? null : json_decode($json, true);
    if (!is_array($payload)
        || !hash_equals($confNum, strtoupper(trim((string)($payload['ref'] ?? ''))))
        || (int)($payload['exp'] ?? 0) < time()
        || trim((string)($payload['rid'] ?? '')) === '') return null;
    return $payload;
}

function verifyPortalToken(string $token, string $confNum): bool {
    return portalTokenPayload($token, $confNum) !== null;
}

function gpNumber($value): ?float {
    if ($value === null || $value === '' || !is_numeric($value)) return null;
    return (float)$value;
}

/** Calculate the figures the guest will accept, server-side in Sydney time. */
function cancellationQuote(array $reservation): array {
    $total = gpNumber($reservation['ReservationTotalAfterTax'] ?? null)
        ?? gpNumber($reservation['ReservationTotal'] ?? null) ?? 0.0;
    $required = max(0.0, gpNumber($reservation['PaymentRequired'] ?? null) ?? 0.0);
    $later = max(0.0, gpNumber($reservation['PayLater'] ?? null) ?? 0.0);
    // GuestPoint examples use PaymentRequired=0 with the whole outstanding
    // amount in PayLater. Taking the larger value avoids calling that paid.
    $balance = min($total, max($required, $later));
    $paid = max(0.0, $total - $balance);
    $stays = array_values(array_filter(
        is_array($reservation['RoomStays'] ?? null) ? $reservation['RoomStays'] : [],
        fn($stay) => is_array($stay) && empty($stay['IsCancelled'])
    ));
    if (!$stays) $stays = is_array($reservation['RoomStays'] ?? null) ? $reservation['RoomStays'] : [];

    $arrival = '';
    $oneNight = 0.0;
    $tariffTotal = 0.0;
    $rules = [];
    foreach ($stays as $stay) {
        if (!is_array($stay)) continue;
        $stayArrival = trim((string)($stay['Arrival'] ?? ''));
        if ($stayArrival !== '' && ($arrival === '' || $stayArrival < $arrival)) $arrival = $stayArrival;
        try {
            $a = new DateTimeImmutable($stayArrival, new DateTimeZone('Australia/Sydney'));
            $d = new DateTimeImmutable((string)($stay['Departure'] ?? ''), new DateTimeZone('Australia/Sydney'));
            $nights = max(1, (int)$a->diff($d)->days);
        } catch (Throwable $e) { $nights = 1; }
        $stayTariff = max(0.0, gpNumber($stay['RoomTotal'] ?? null) ?? 0.0);
        $tariffTotal += $stayTariff;
        $oneNight += $stayTariff / $nights;
        foreach (is_array($stay['RateDetails'] ?? null) ? $stay['RateDetails'] : [] as $rate) {
            if (is_array($rate) && is_array($rate['CancelRule'] ?? null)) $rules[] = $rate['CancelRule'];
        }
    }

    try {
        $today = new DateTimeImmutable('today', new DateTimeZone('Australia/Sydney'));
        $arrivalDate = new DateTimeImmutable($arrival, new DateTimeZone('Australia/Sydney'));
        $days = (int)$today->diff($arrivalDate)->format('%r%a');
    } catch (Throwable $e) { $days = -1; }

    // Optional extras are refundable until supplied; cancellation percentages
    // apply to the accommodation tariff. Fall back to the booking total when
    // GuestPoint does not return a separate RoomTotal.
    $policyTotal = $tariffTotal > 0 ? $tariffTotal : $total;
    $fee = 0.0;
    $detail = '';
    $nonRefundable = null;
    foreach ($rules as $rule) {
        if (strtolower(trim((string)($rule['CancelRule'] ?? ''))) === 'none') { $nonRefundable = $rule; break; }
    }
    if ($nonRefundable !== null) {
        $fee = $policyTotal;
        $detail = trim((string)($nonRefundable['CancelRuleText'] ?? '')) ?: 'This rate is non-refundable.';
    } elseif ($rules) {
        $free = $rules[0];
        $periods = max(0, (int)($free['CancelRuleNumPeriods'] ?? 0));
        $period = strtolower(trim((string)($free['CancelRulePeriod'] ?? 'day')));
        $cutoffDays = $period === 'week' ? $periods * 7 : ($period === 'hour' ? (int)ceil($periods / 24) : $periods);
        $fee = $days >= $cutoffDays ? 0.0 : $policyTotal;
        $detail = trim((string)($free['CancelRuleText'] ?? '')) ?: 'The cancellation conditions attached to your booked rate apply.';
    } else {
        $fee = $days >= 15 ? max($policyTotal * 0.1, $oneNight)
            : ($days >= 8 ? max($policyTotal * 0.5, $oneNight) : $policyTotal);
        $detail = $days >= 15
            ? "15 or more days before arrival, the greater of 10% of the tariff or one night's tariff is retained."
            : ($days >= 8
                ? "8 to 14 days before arrival, the greater of 50% of the tariff or one night's tariff is retained."
                : 'Within 7 days of arrival, the booking is non-refundable.');
    }
    $fee = min($policyTotal, max(0.0, $fee));

    // Amendment (transfer) fee, from the same figures as the cancellation fee
    // so the two can never drift apart. Mirrors the published conditions:
    //   non-refundable rate      no amendment, no fee
    //   inside 8 days            no amendment, no fee
    //   snow season (Jun-Sep)    transfer fee, and no amendment inside 14 days
    //   8-14 days out            transfer fee
    //   15+ days out, off-peak   like-for-like change is free
    // where the transfer fee is the greater of $50, 10% of the tariff, or one
    // night. Computed here rather than trusted from the browser: it is money.
    try {
        $arrivalMonth = (int)(new DateTimeImmutable($arrival, new DateTimeZone('Australia/Sydney')))->format('n');
    } catch (Throwable $e) { $arrivalMonth = 0; }
    $snowSeason = $arrivalMonth >= 6 && $arrivalMonth <= 9;
    $transferFee = round(max(50.0, $policyTotal * 0.1, $oneNight), 2);
    $amendAllowed = $nonRefundable === null && $days >= 8 && !($snowSeason && $days <= 14);
    $amendFee = $amendAllowed && ($snowSeason || $days < 15) ? $transferFee : 0.0;

    return [
        'amendAllowed' => $amendAllowed,
        'amendFee' => round($amendFee, 2),
        'snowSeason' => $snowSeason,
        'fee' => round($fee, 2),
        'refund' => round(max(0.0, $paid - $fee), 2),
        'amountDue' => round(max(0.0, $fee - $paid), 2),
        'paid' => round($paid, 2),
        'balance' => round($balance, 2),
        'days' => $days,
        'detail' => $detail,
        'quotedAt' => gmdate('c'),
    ];
}

function managedReservationFromPayload(array $payload): array {
    $root = isset($payload['data']) && is_array($payload['data']) ? $payload['data']
        : (isset($payload['Data']) && is_array($payload['Data']) ? $payload['Data'] : $payload);
    return is_array($root['Reservation'] ?? null) ? $root['Reservation'] : [];
}

function guestPointConfirmed(string $response): bool {
    $decoded = json_decode($response, true);
    return is_array($decoded) && (($decoded['success'] ?? null) === true);
}

/** @return array{0:int,1:string,2:string} */
function upstreamCall(string $method, string $url, string $key, ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => UPSTREAM_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => array_filter([
            'X-API-KEY: ' . $key,
            'Accept: application/json',
            $body !== null ? 'Content-Type: application/json' : null,
        ]),
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [$status, $response === false ? '' : (string)$response, $error];
}

/** Call Phoenix's authenticated WebAPI. This is enabled only on the test site. */
function pmsToken(): string {
    global $PMS_PRIVATE_WRITES, $PMS_UPSTREAM, $PMS_SERIAL, $PMS_USERNAME, $PMS_PASSWORD, $CACHE_DIR;
    if (!$PMS_PRIVATE_WRITES || $PMS_SERIAL === '' || $PMS_USERNAME === '' || $PMS_PASSWORD === '') return '';
    $cache = $CACHE_DIR . '/pms-token.json';
    if (is_file($cache)) {
        $saved = json_decode((string)@file_get_contents($cache), true);
        if (is_array($saved) && (int)($saved['expires'] ?? 0) > time() + 60 && is_string($saved['token'] ?? null)) return $saved['token'];
    }
    $ch = curl_init($PMS_UPSTREAM . '/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => UPSTREAM_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => http_build_query(['username'=>$PMS_USERNAME,'password'=>$PMS_PASSWORD,'grant_type'=>'password','serialNumber'=>$PMS_SERIAL], '', '&', PHP_QUERY_RFC3986),
    ]);
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $token = is_array($decoded) ? (string)($decoded['access_token'] ?? '') : '';
    if ($status !== 200 || $token === '') return '';
    @file_put_contents($cache, json_encode(['token'=>$token,'expires'=>time()+max(300,(int)($decoded['expires_in'] ?? 3600)-120)]), LOCK_EX);
    return $token;
}

/** @return array{0:int,1:string,2:string} */
function pmsCall(string $method, string $path, array|object|null $payload = null): array {
    global $PMS_UPSTREAM;
    $token = pmsToken();
    if ($token === '') return [503, '', 'Phoenix PMS bridge is not configured.'];
    $ch = curl_init($PMS_UPSTREAM . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => UPSTREAM_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array_filter(['Authorization: Bearer ' . $token, 'Accept: application/json', $payload !== null ? 'Content-Type: application/json' : null]),
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $response = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
    return [$status, $response === false ? '' : (string)$response, $error];
}

/** Seconds left in this request's self-imposed budget. */
function portalTimeLeft(): float {
    global $REQUEST_STARTED;
    return REQUEST_BUDGET - (microtime(true) - (float)$REQUEST_STARTED);
}

/**
 * Per-request memo for GuestPoint reads.
 *
 * One extras save used to make twenty upstream calls — four of them the same
 * transaction list, three the same add-on catalogue — each with a 20 second
 * timeout, inside a single PHP request on shared hosting. Reads that cannot
 * change mid-request are answered once. Anything a write invalidates is
 * dropped explicitly by that writer; nothing is cached across a write blindly.
 */
function &pmsMemoStore(): array {
    static $store = [];
    return $store;
}

function pmsMemo(string $key, callable $load) {
    $store =& pmsMemoStore();
    if (!array_key_exists($key, $store)) $store[$key] = $load();
    return $store[$key];
}

/** Drop every memo under a prefix. Blunt on purpose: re-reading is the safe side. */
function pmsMemoForget(string $prefix): void {
    $store =& pmsMemoStore();
    foreach (array_keys($store) as $key) {
        if (str_starts_with($key, $prefix)) unset($store[$key]);
    }
}

function uuidV4(): string {
    $data = random_bytes(16); $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/** Read one complete SMTP response, including multiline replies. */
function smtpRead($socket): array {
    $reply = '';
    $code = 0;
    while (($line = fgets($socket, 1024)) !== false) {
        $reply .= $line;
        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code = (int)$m[1];
            if ($m[2] === ' ') break;
        }
    }
    return [$code, trim($reply)];
}

function smtpCommand($socket, string $command, array $okCodes): array {
    if (fwrite($socket, $command . "\r\n") === false) return [false, 'SMTP write failed'];
    [$code, $reply] = smtpRead($socket);
    return [in_array($code, $okCodes, true), $reply];
}

/** Send a plain-text email through the configured Hostinger mailbox. */
function smtpSendText(string $to, string $subject, string $message): bool {
    global $SMTP_HOST, $SMTP_PORT, $SMTP_USERNAME, $SMTP_PASSWORD, $OTP_FROM_EMAIL, $OTP_FROM_NAME;
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)
        || !filter_var($OTP_FROM_EMAIL, FILTER_VALIDATE_EMAIL)
        || $SMTP_USERNAME === '' || $SMTP_PASSWORD === ''
        || !preg_match('/^[A-Za-z0-9.-]+$/', $SMTP_HOST)) return false;

    $socket = @stream_socket_client(
        'ssl://' . $SMTP_HOST . ':' . $SMTP_PORT,
        $errno,
        $error,
        12,
        STREAM_CLIENT_CONNECT
    );
    if (!$socket) return false;
    stream_set_timeout($socket, 12);

    [$code] = smtpRead($socket);
    if ($code !== 220) { fclose($socket); return false; }
    $host = preg_replace('/[^A-Za-z0-9.-]/', '', (string)($_SERVER['SERVER_NAME'] ?? 'kosipark.com.au')) ?: 'kosipark.com.au';
    [$ok] = smtpCommand($socket, 'EHLO ' . $host, [250]);
    if (!$ok) { fclose($socket); return false; }
    [$ok] = smtpCommand($socket, 'AUTH LOGIN', [334]);
    if (!$ok) { fclose($socket); return false; }
    [$ok] = smtpCommand($socket, base64_encode($SMTP_USERNAME), [334]);
    if (!$ok) { fclose($socket); return false; }
    [$ok] = smtpCommand($socket, base64_encode($SMTP_PASSWORD), [235]);
    if (!$ok) { fclose($socket); return false; }
    [$ok] = smtpCommand($socket, 'MAIL FROM:<' . $OTP_FROM_EMAIL . '>', [250]);
    if (!$ok) { fclose($socket); return false; }
    [$ok] = smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
    if (!$ok) { fclose($socket); return false; }
    [$ok] = smtpCommand($socket, 'DATA', [354]);
    if (!$ok) { fclose($socket); return false; }

    $cleanSubject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? '');
    $cleanName = trim(preg_replace('/[\r\n]+/', ' ', $OTP_FROM_NAME) ?? '');
    $body = preg_replace('/(?m)^\./', '..', str_replace(["\r\n", "\r"], "\n", $message)) ?? '';
    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . $cleanName . ' <' . $OTP_FROM_EMAIL . '>',
        'To: <' . $to . '>',
        'Subject: ' . $cleanSubject,
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    fwrite($socket, implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $body) . "\r\n.\r\n");
    [$dataCode] = smtpRead($socket);
    @smtpCommand($socket, 'QUIT', [221]);
    fclose($socket);
    return $dataCode === 250;
}

/** Return Core reservation rows for a documented reservation search. */
function searchCoreReservations(array $parameters): array {
    global $CORE_UPSTREAM, $CORE_KEY;
    if ($CORE_UPSTREAM === '' || $CORE_KEY === '') return [];
    $search = $CORE_UPSTREAM . '/v1/reservations?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    [$status, $response] = upstreamCall('GET', $search, $CORE_KEY);
    if ($status !== 200) return [];
    $payload = json_decode($response, true);
    $rows = [];
    if (is_array($payload)) {
        if (isset($payload['ReservationNumber'])) $rows = [$payload];
        else $rows = $payload['Data'] ?? $payload['data'] ?? [];
    }
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

/** Read the two human-facing booking numbers from Phoenix for one Core row. */
function pmsBookingNumbers(array $reservation): array {
    $numbers = [
        'reservationNumber' => strtoupper(trim((string)($reservation['ReservationNumber'] ?? ''))),
        'bookingReference' => '',
    ];
    $allocations = is_array($reservation['Allocations'] ?? null) ? $reservation['Allocations'] : [];
    $primary = null;
    foreach ($allocations as $allocation) {
        if (is_array($allocation) && !empty($allocation['IsPrimary']) && !empty($allocation['RoomAllocationId'])) {
            $primary = $allocation;
            break;
        }
    }
    if ($primary === null) {
        foreach ($allocations as $allocation) {
            if (is_array($allocation) && !empty($allocation['RoomAllocationId'])) { $primary = $allocation; break; }
        }
    }
    if ($primary === null) return $numbers;

    // Same read pmsSavedCard needs, so share the memo rather than fetching the
    // reservation detail twice in one request.
    $detail = pmsReservationFinancialDetail((string)$primary['RoomAllocationId']);
    if (!is_array($detail)) return $numbers;
    $sources = [$detail];
    if (is_array($detail['_Reservation'] ?? null)) $sources[] = $detail['_Reservation'];
    if (is_array($detail['_RoomAllocations'][0]['_Reservation'] ?? null)) $sources[] = $detail['_RoomAllocations'][0]['_Reservation'];
    foreach ($sources as $source) {
        $reservationNumber = strtoupper(trim((string)($source['ReservationNumber'] ?? '')));
        $bookingReference = strtoupper(trim((string)($source['BookingReference'] ?? '')));
        if ($reservationNumber !== '') $numbers['reservationNumber'] = $reservationNumber;
        if ($bookingReference !== '') $numbers['bookingReference'] = $bookingReference;
    }
    return $numbers;
}

/**
 * Resolve either the Phoenix Reservation Number or the Channel Booking Ref.
 * GuestPoint's booking-engine manage endpoint needs the channel reference, so
 * the returned reference is canonical even when the guest entered the PMS one.
 */
function resolvePortalBookingIdentity(string $enteredReference, string $surname): ?array {
    global $PROPERTY_ID;
    $enteredReference = strtoupper(trim($enteredReference));
    $surnameKey = strtolower(trim($surname));
    $searches = [
        ['bookingReference' => $enteredReference, 'lastName' => $surname],
        // Some Core installations search only the channel reference in the
        // bookingReference filter. The surname fallback lets us compare the
        // returned Phoenix ReservationNumber ourselves.
        ['lastName' => $surname],
    ];
    $seen = [];
    foreach ($searches as $searchIndex => $parameters) {
        foreach (searchCoreReservations($parameters) as $reservation) {
            $rowProperty = strtolower(trim((string)($reservation['PropertyId'] ?? '')));
            if (!hash_equals(strtolower($PROPERTY_ID), $rowProperty)) continue;
            $primaryMatches = false;
            foreach (($reservation['Allocations'] ?? []) as $allocation) {
                if (!is_array($allocation) || empty($allocation['IsPrimary'])) continue;
                $rowSurname = strtolower(trim((string)($allocation['GuestLastName'] ?? '')));
                if (hash_equals($surnameKey, $rowSurname)) $primaryMatches = true;
            }
            if (!$primaryMatches) continue;
            $rowId = (string)($reservation['ReservationId'] ?? $reservation['ReservationNumber'] ?? '');
            if ($rowId !== '' && isset($seen[$rowId])) continue;
            if ($rowId !== '') $seen[$rowId] = true;

            $numbers = pmsBookingNumbers($reservation);
            $matchesReservation = $numbers['reservationNumber'] !== '' && hash_equals($numbers['reservationNumber'], $enteredReference);
            $matchesChannel = $numbers['bookingReference'] !== '' && hash_equals($numbers['bookingReference'], $enteredReference);
            // If the Phoenix bridge is unavailable, preserve the documented
            // exact Core lookup behaviour for channel references.
            $trustedExactCoreResult = $searchIndex === 0 && $numbers['bookingReference'] === '' && !$matchesReservation;
            if (!$matchesReservation && !$matchesChannel && !$trustedExactCoreResult) continue;

            return [
                'reservation' => $reservation,
                'manageReference' => $numbers['bookingReference'] !== '' ? $numbers['bookingReference'] : $enteredReference,
                'reservationNumber' => $numbers['reservationNumber'],
            ];
        }
    }
    return null;
}

/** Return the Core reservation only when either booking number and surname match. */
function resolvePortalCoreReservation(string $confNum, string $surname): ?array {
    $identity = resolvePortalBookingIdentity($confNum, $surname);
    return is_array($identity) && is_array($identity['reservation'] ?? null) ? $identity['reservation'] : null;
}

/** Return the stored primary email from an already verified Core reservation. */
function portalReservationEmail(array $reservation): string {
    foreach (($reservation['Allocations'] ?? []) as $allocation) {
        if (!is_array($allocation) || empty($allocation['IsPrimary'])) continue;
        $email = trim((string)($allocation['GuestEmail'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
    }
    return '';
}

/** Return the stored primary email only when the reference and surname match. */
function resolvePortalEmail(string $confNum, string $surname): string {
    $reservation = resolvePortalCoreReservation($confNum, $surname);
    return is_array($reservation) ? portalReservationEmail($reservation) : '';
}

/** Read the complete Phoenix reservation/card record for one room allocation. */
function pmsReservationFinancialDetail(string $roomAllocationId): ?array {
    return pmsMemo('fin:' . $roomAllocationId, function () use ($roomAllocationId) {
        [$status, $raw] = pmsCall('GET', 'Reservation/GetReservationDetailByRoomAllocationWithCurrentPackage?roomAllocationID=' . rawurlencode($roomAllocationId) . '&allCharges=false');
        $detail = json_decode($raw, true);
        return $status === 200 && is_array($detail) ? $detail : null;
    });
}

/** Find a named scalar anywhere in GuestPoint's nested reservation response. */
function nestedScalar(array $value, string $key): ?string {
    if (array_key_exists($key, $value) && !is_array($value[$key]) && $value[$key] !== null && $value[$key] !== '') return (string)$value[$key];
    foreach ($value as $child) {
        if (!is_array($child)) continue;
        $found = nestedScalar($child, $key);
        if ($found !== null) return $found;
    }
    return null;
}

/**
 * The person a charge or payment is posted against.
 *
 * Not on the room allocation: that record carries RoomAllocationID, RoomID,
 * ReservationID and the occupancy counts, but no PersonID. Phoenix takes it
 * from the financial detail's _Persons list, so this walks the same path
 * explicitly rather than letting nestedScalar() return the first PersonID it
 * meets — the _TransactionItems in the same response each carry one, and an
 * older row's person is not necessarily the account holder.
 */
function pmsAccountPersonId(string $roomAllocationId): string {
    return pmsMemo('person:' . $roomAllocationId, function () use ($roomAllocationId) {
        $detail = pmsReservationFinancialDetail($roomAllocationId);
        if (!is_array($detail)) return '';
        foreach ($detail['_RoomAllocations'] ?? [] as $allocation) {
            if (!is_array($allocation)) continue;
            if ((string)($allocation['RoomAllocationID'] ?? '') !== '' && !hash_equals(strtolower($roomAllocationId), strtolower((string)$allocation['RoomAllocationID']))) continue;
            foreach ($allocation['_Persons'] ?? [] as $person) {
                if (!is_array($person)) continue;
                $id = trim((string)($person['PersonID'] ?? ($person['_Person']['PersonID'] ?? '')));
                if ($id !== '') return $id;
            }
        }
        return '';
    });
}

/** Server-only saved-card information. The token is never returned to a browser. */
function pmsSavedCard(string $roomAllocationId): array {
    return pmsMemo('card:' . $roomAllocationId, fn() => pmsSavedCardUncached($roomAllocationId));
}

function pmsSavedCardUncached(string $roomAllocationId): array {
    global $PROPERTY_ID;
    $detail = pmsReservationFinancialDetail($roomAllocationId);
    if (!is_array($detail)) return ['available'=>false];
    $token = nestedScalar($detail, 'CCNumberToken') ?? '';
    $mask = nestedScalar($detail, 'CCPartialNumber') ?? '';
    $expiry = nestedScalar($detail, 'CCExpiry') ?? '';
    $accountId = nestedScalar($detail, 'CCTransactionAccountID') ?? '';
    $reservationNumber = nestedScalar($detail, 'ReservationNumber') ?? '';
    $name = nestedScalar($detail, 'CCName') ?? '';
    if ($token === '' || $accountId === '' || $reservationNumber === '') return ['available'=>false];
    [$expiredStatus, $expiredRaw] = pmsCall('GET', 'CreditCardVault/GetHasCcMapExpired?propertyID=' . rawurlencode($PROPERTY_ID) . '&ccMapID=' . rawurlencode($token));
    $expired = $expiredStatus !== 200 || json_decode($expiredRaw, true) !== false;
    return ['available'=>!$expired,'token'=>$token,'mask'=>$mask,'expiry'=>$expiry,'accountId'=>$accountId,'reservationNumber'=>$reservationNumber,'holder'=>$name];
}

/**
 * The signed-in app-user record. Phoenix hangs two things off it that the
 * payment path needs: the encrypted proxy-post credential, and the AppuserID
 * that ProcessPaymentUsingProxyPost wants in its body so the payment is
 * attributed to a user rather than to nobody.
 */
function pmsProxyAppuser(): array {
    // Memoised: this pulls every app user's stored credential, so doing it once
    // per request rather than once per charge keeps that exposure to a minimum.
    return pmsMemo('proxyuser', fn() => pmsProxyAppuserUncached());
}

function pmsProxyAppuserUncached(): array {
    global $PROPERTY_ID, $PMS_USERNAME;
    [$status, $raw] = pmsCall('GET', 'User/GetAllAppusersByProperty?propertyID=' . rawurlencode($PROPERTY_ID));
    $users = json_decode($raw, true);
    if ($status !== 200 || !is_array($users)) return [];
    foreach ($users as $user) {
        if (!is_array($user) || !hash_equals(strtolower($PMS_USERNAME), strtolower(trim((string)($user['Username'] ?? ''))))) continue;
        return $user;
    }
    return [];
}

function pmsProxyPassword(): string {
    return trim((string)(pmsProxyAppuser()['Password'] ?? ''));
}

/** The id Phoenix stamps on the payment it posts for a successful charge. */
function pmsProxyAppuserId(): string {
    return trim((string)(pmsProxyAppuser()['AppuserID'] ?? ''));
}

/** Transaction accounts by lowercased id. The card's account carries the name
 *  Phoenix uses as the payment description ("Visa"). */
function pmsTransactionAccounts(): array {
    global $PROPERTY_ID;
    return pmsMemo('accounts', function () {
        global $PROPERTY_ID;
        [$status, $raw] = pmsCall('GET', 'Accounts/GetTransactionAccounts?propertyID=' . rawurlencode($PROPERTY_ID));
        $rows = json_decode($raw, true);
        $byId = [];
        if ($status === 200 && is_array($rows)) foreach ($rows as $row) {
            if (is_array($row) && !empty($row['TransactionAccountID'])) $byId[strtolower((string)$row['TransactionAccountID'])] = $row;
        }
        return $byId;
    });
}

function pmsTransactionItems(string $reservationId): array {
    // Invalidated by pmsForgetTransactions after every account write, so a
    // verification read never sees a stale copy of what we just posted.
    return pmsMemo('tx:' . $reservationId, function () use ($reservationId) {
        [$status, $raw] = pmsCall('GET', 'Accounts/GetTransactionItemDetailsByReservation?reservationID=' . rawurlencode($reservationId));
        $items = json_decode($raw, true);
        return $status === 200 && is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    });
}

/** Call after any Accounts/SaveTransactionItemDetails for this reservation. */
function pmsForgetTransactions(string $reservationId): void {
    pmsMemoForget('tx:' . $reservationId);
    pmsMemoForget('extras:');
    pmsMemoForget('addonrows:');
}

function pmsAddonDefinitions(): array {
    global $PROPERTY_ID;
    // The add-on master is property configuration; it cannot change under us
    // inside one request.
    return pmsMemo('addons', function () use ($PROPERTY_ID) {
        [$status, $raw] = pmsCall('GET', 'Rates/GetAddons?propertyID=' . rawurlencode($PROPERTY_ID));
        $rows = json_decode($raw, true);
        $out = [];
        if ($status === 200 && is_array($rows)) foreach ($rows as $row) {
            if (!is_array($row) || empty($row['AddonID'])) continue;
            $out[strtolower((string)$row['AddonID'])] = $row;
        }
        return $out;
    });
}

/**
 * Put the name reception configured onto the Booking Engine's extras catalogue.
 *
 * The catalogue carries the *web* fields: `Name` is the add-on's web name and
 * `Description` its web description, and both are optional. Live, Drying Room
 * comes back with `Name: ""` and Firewood with `Name: "Firewood Desc"` — so the
 * site showed "Optional extra" for one and a description for the other, while
 * reception, the room account and the guest's invoice all say "Drying Room" and
 * "Firewood".
 *
 * Phoenix's add-on master is the name everyone else sees, so it wins whenever
 * the PMS bridge is available. Without it (no credentials, public booking flow
 * on a property that has not enabled private writes) the catalogue is returned
 * untouched, which is the old behaviour.
 */
function overlayAddonNames(string $raw): string {
    $payload = json_decode($raw, true);
    if (!is_array($payload)) return $raw;
    $masters = pmsAddonDefinitions();
    if (!$masters) return $raw;

    $rows = array_is_list($payload) ? $payload : ($payload['data'] ?? $payload['Extras'] ?? null);
    if (!is_array($rows) || !array_is_list($rows)) return $raw;

    $changed = false;
    foreach ($rows as $index => $row) {
        if (!is_array($row) || empty($row['Id'])) continue;
        $master = $masters[strtolower((string)$row['Id'])] ?? null;
        $name = is_array($master) ? trim((string)($master['Name'] ?? '')) : '';
        if ($name === '' || $name === (string)($row['Name'] ?? '')) continue;
        $rows[$index]['Name'] = $name;
        $changed = true;
    }
    if (!$changed) return $raw;

    if (array_is_list($payload)) $payload = $rows;
    elseif (isset($payload['data'])) $payload['data'] = $rows;
    else $payload['Extras'] = $rows;
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $encoded === false ? $raw : $encoded;
}

/** Return reservation-scoped Phoenix profile definitions and their current values. */
function pmsReservationProfiles(string $reservationId): array {
    return pmsMemo('profiles:' . $reservationId, fn() => pmsReservationProfilesUncached($reservationId));
}

/** Call after any allocation save that carries profile values. */
function pmsForgetProfiles(string $reservationId): void {
    pmsMemoForget('profiles:' . $reservationId);
}

function pmsReservationProfilesUncached(string $reservationId): array {
    global $PROPERTY_ID;
    [$definitionStatus, $definitionRaw] = pmsCall('GET', 'Property/GetProfileFieldDetails?propertyID=' . rawurlencode($PROPERTY_ID));
    [$valueStatus, $valueRaw] = pmsCall('GET', 'Property/GetProfilesByReservation?reservationID=' . rawurlencode($reservationId));
    $definitions = json_decode($definitionRaw, true);
    $values = json_decode($valueRaw, true);
    if ($definitionStatus !== 200 || !is_array($definitions)) $definitions = [];
    if ($valueStatus !== 200 || !is_array($values)) $values = [];
    $valueById = [];
    foreach ($values as $value) if (is_array($value) && !empty($value['ProfileFieldID'])) $valueById[strtolower((string)$value['ProfileFieldID'])] = $value;
    $fields = [];
    foreach ($definitions as $definition) {
        if (!is_array($definition) || empty($definition['ProfileFieldID'])) continue;
        $name = trim((string)($definition['Name'] ?? ''));
        if (!preg_match('/(?:car|vehicle).*(?:rego|registration)|rego|dimentions|dimensions?/i', $name)) continue;
        $id = (string)$definition['ProfileFieldID'];
        $current = $valueById[strtolower($id)] ?? [];
        $fields[] = ['Id'=>$id,'ExternalId'=>$id,'Name'=>$name,'FieldType'=>'text','Type'=>'text','Value'=>(string)($current['Value'] ?? '')];
    }
    return ['definitions'=>$fields,'values'=>$values];
}

/** Collapse future add-ons and posted account charges into the guest-facing state. */
function portalCurrentExtras(string $roomAllocationId, string $reservationId = ''): array {
    return pmsMemo('extras:' . $roomAllocationId . '|' . $reservationId,
        fn() => portalCurrentExtrasUncached($roomAllocationId, $reservationId));
}

function portalCurrentExtrasUncached(string $roomAllocationId, string $reservationId = ''): array {
    global $PROPERTY_ID;
    $rows = pmsMemo('addonrows:' . $roomAllocationId, function () use ($PROPERTY_ID, $roomAllocationId) {
        [$status, $raw] = pmsCall('GET', 'Reservation/GetRoomAllocationAddons?propertyID=' . rawurlencode($PROPERTY_ID) . '&roomAllocationID=' . rawurlencode($roomAllocationId));
        $decoded = json_decode($raw, true);
        return $status === 200 && is_array($decoded) ? $decoded : null;
    });
    if (!is_array($rows)) return [];
    // Allocation rows contain only the AddonID. Resolve the guest-facing PMS
    // name from the add-on master so the portal does not have to display the
    // Booking Engine's generic fallback such as "Optional extra".
    $addonNames = [];
    $addonDefinitions = pmsAddonDefinitions();
    foreach ($addonDefinitions as $addonId => $addon) {
        $addonName = trim((string)($addon['Name'] ?? ''));
        if ($addonName !== '') $addonNames[$addonId] = $addonName;
    }
    $grouped = [];
    foreach ($rows as $row) {
        if (!is_array($row) || empty($row['AddonID'])) continue;
        $id = strtolower((string)$row['AddonID']);
        if (!isset($grouped[$id])) $grouped[$id] = ['Id'=>(string)$row['AddonID'],'Name'=>$addonNames[$id] ?? '','Quantity'=>0,'ChildQuantity'=>0,'Total'=>0.0,'Dates'=>[]];
        // Repeating extras have one row per applicable date. The editable
        // quantity is the largest per-date count, not the sum across nights.
        $grouped[$id]['Quantity'] = max($grouped[$id]['Quantity'], (int)($row['Quantity'] ?? 0));
        $grouped[$id]['ChildQuantity'] = max($grouped[$id]['ChildQuantity'], (int)($row['QuantityChild'] ?? 0));
        $grouped[$id]['Total'] = round($grouped[$id]['Total'] + max(0.0, (float)($row['Total'] ?? 0)), 2);
        $date = substr((string)($row['Date'] ?? ''), 0, 10);
        if ($date !== '') $grouped[$id]['Dates'][] = $date;
    }
    // Account transactions are authoritative once an extra has been posted.
    // A linked negative row is a reversal, never another selected extra.
    if ($reservationId !== '') {
        $posted = [];
        foreach (pmsTransactionItems($reservationId) as $row) {
            $addonId = strtolower(trim((string)($row['AddonID'] ?? '')));
            if ($addonId === '' || (float)($row['AmountInc'] ?? 0) <= 0 || !empty($row['IsReversed']) || !empty($row['ReversedTransactionItemID'])) continue;
            if ((string)($row['RoomAllocationID'] ?? '') !== $roomAllocationId) continue;
            if (!isset($posted[$addonId])) $posted[$addonId] = ['Id'=>(string)$row['AddonID'],'Name'=>$addonNames[$addonId] ?? (string)($row['Description'] ?? ''),'Quantity'=>0,'ChildQuantity'=>0,'Total'=>0.0,'Dates'=>[],'Status'=>'Posted'];
            $perNight = !empty($addonDefinitions[$addonId]['IsPerNight']);
            if ($perNight) {
                $posted[$addonId]['Quantity'] = max($posted[$addonId]['Quantity'], max(0, (int)round((float)($row['Quantity'] ?? 0))));
                $posted[$addonId]['ChildQuantity'] = max($posted[$addonId]['ChildQuantity'], max(0, (int)round((float)($row['QuantityChild'] ?? 0))));
            } else {
                $posted[$addonId]['Quantity'] += max(0, (int)round((float)($row['Quantity'] ?? 0)));
                $posted[$addonId]['ChildQuantity'] += max(0, (int)round((float)($row['QuantityChild'] ?? 0)));
            }
            $posted[$addonId]['Total'] = round($posted[$addonId]['Total'] + max(0.0, (float)$row['AmountInc']), 2);
            $date = substr((string)($row['PrintDate'] ?? $row['AccountingDate'] ?? ''), 0, 10);
            if ($date !== '') $posted[$addonId]['Dates'][] = $date;
        }
        foreach ($posted as $id => $row) $grouped[$id] = $row;
    }
    return array_values($grouped);
}

/** Return accommodation charges without optional add-ons. */
function portalAccommodationTotal(string $roomAllocationId): ?float {
    global $PROPERTY_ID;
    [$status, $raw] = pmsCall('GET', 'Reservation/GetRoomAllocationChargesForReservationByRoomAllocationID?propertyID=' . rawurlencode($PROPERTY_ID) . '&roomAllocationID=' . rawurlencode($roomAllocationId));
    $charges = json_decode($raw, true);
    if ($status !== 200 || !is_array($charges) || !$charges) return null;
    $total = 0.0;
    foreach ($charges as $charge) {
        if (!is_array($charge)) continue;
        $total += max(0.0, (float)($charge['Room'] ?? 0) + (float)($charge['ExtraPersons'] ?? 0) - (float)($charge['Discount'] ?? 0));
    }
    return round($total, 2);
}

/** Build a Booking Engine-shaped management response for a Phoenix-only stay. */
function pmsManagedReservationPayload(array $identity, string $surname, string $email): ?array {
    global $PROPERTY_ID;
    $core = is_array($identity['reservation'] ?? null) ? $identity['reservation'] : [];
    $allocations = array_values(array_filter($core['Allocations'] ?? [], fn($a) => is_array($a) && !empty($a['RoomAllocationId'])));
    if (count($allocations) !== 1) return null;
    $coreAllocation = $allocations[0];
    $reservationId = trim((string)($core['ReservationId'] ?? ''));
    $roomAllocationId = (string)$coreAllocation['RoomAllocationId'];
    if ($reservationId === '') return null;

    [$detailsStatus, $detailsRaw] = pmsCall('GET', 'Reservation/GetRoomAllocationDetail2sByReservation?reservationID=' . rawurlencode($reservationId));
    $details = json_decode($detailsRaw, true);
    $allocation = ($detailsStatus === 200 && is_array($details) && count($details) === 1 && is_array($details[0])) ? $details[0] : null;
    // Phoenix excludes cancelled allocations from its editable-detail endpoint.
    // Fall back to the read-only allocation so cancelled bookings remain
    // available for status checks and future receipt downloads.
    if (!is_array($allocation)) {
        [$allocationStatus, $allocationRaw] = pmsCall('GET', 'Reservation/GetRoomAllocation?roomAllocationID=' . rawurlencode($roomAllocationId));
        $allocation = json_decode($allocationRaw, true);
        if ($allocationStatus !== 200 || !is_array($allocation)) return null;
    }
    if ((string)($allocation['RoomAllocationID'] ?? '') !== $roomAllocationId) return null;

    $arrival = substr((string)($allocation['ArrivalDate'] ?? ''), 0, 10);
    $nights = max(1, (int)($allocation['NumberOfNights'] ?? 1));
    try { $departure = (new DateTimeImmutable($arrival))->modify('+' . $nights . ' days')->format('Y-m-d'); }
    catch (Throwable $e) { return null; }
    $statusCode = (int)($allocation['Status'] ?? 0);
    $statusMap = [1=>'Modified', 2=>'Checked in', 3=>'Checked out', 4=>'Cancelled', 5=>'No show'];
    $status = $statusMap[$statusCode] ?? 'Booked';
    $outstanding = max(0.0, gpNumber($core['AmountOutstanding'] ?? null) ?? gpNumber($allocation['DepartureBalance'] ?? null) ?? 0.0);
    $departureBalance = max(0.0, gpNumber($allocation['DepartureBalance'] ?? null) ?? 0.0);
    $accommodationTotal = portalAccommodationTotal($roomAllocationId);
    $currentExtras = portalCurrentExtras($roomAllocationId, $reservationId);
    $profiles = pmsReservationProfiles($reservationId);
    $extrasTotal = array_sum(array_map(fn($extra) => max(0.0, (float)($extra['Total'] ?? 0)), $currentExtras));
    $bookingTotal = max($outstanding, $departureBalance, $accommodationTotal !== null ? $accommodationTotal + $extrasTotal : 0.0);
    $roomTotal = $accommodationTotal ?? $bookingTotal;
    $roomType = is_array($allocation['_RoomType'] ?? null) ? $allocation['_RoomType'] : [];
    $roomTypeName = trim((string)($roomType['Name'] ?? $roomType['RoomType'] ?? ''));
    if ($roomTypeName === '') {
        [$roomTypesStatus, $roomTypesRaw] = pmsCall('GET', 'Property/GetRoomTypes?propertyID=' . rawurlencode($PROPERTY_ID));
        $roomTypes = json_decode($roomTypesRaw, true);
        if ($roomTypesStatus === 200 && is_array($roomTypes)) {
            foreach ($roomTypes as $candidate) {
                if (is_array($candidate) && (string)($candidate['RoomTypeID'] ?? '') === (string)($allocation['RoomTypeID'] ?? '')) {
                    $roomTypeName = trim((string)($candidate['Name'] ?? ''));
                    break;
                }
            }
        }
    }
    if ($roomTypeName === '') $roomTypeName = 'Accommodation booking';
    $ratePlanId = (string)($allocation['PackageID'] ?? '');
    $bookingReference = strtoupper(trim((string)($identity['manageReference'] ?? '')));
    $reservationNumber = strtoupper(trim((string)($identity['reservationNumber'] ?? $core['ReservationNumber'] ?? '')));
    $confNum = $bookingReference !== '' ? $bookingReference : $reservationNumber;
    $guestId = (string)($coreAllocation['GuestId'] ?? '');
    $guest = [
        'ID'=>$guestId,
        'FirstName'=>(string)($coreAllocation['GuestFirstName'] ?? ''),
        'LastName'=>(string)($coreAllocation['GuestLastName'] ?? $surname),
        'Email'=>$email,
        'Mobile'=>(string)($coreAllocation['GuestPhone'] ?? ''),
        'Phone'=>(string)($coreAllocation['GuestPhone'] ?? ''),
    ];
    $reservation = [
        'ID'=>$reservationId,
        'ConfNum'=>$confNum,
        'Status'=>$status,
        'ReservationTotalAfterTax'=>$bookingTotal,
        'PaymentRequired'=>$outstanding,
        'PayLater'=>$outstanding,
        'Adults'=>(int)($allocation['NumberAdults'] ?? 0),
        'Children'=>(int)($allocation['NumberChildren'] ?? 0),
        'Infants'=>(int)($allocation['NumberInfants'] ?? 0),
        'EstimatedArrival'=>(string)($allocation['Eta'] ?? ''),
        'ChannelCode'=>'PMS',
        'Guests'=>$guestId !== '' ? [$guest] : [],
        'BookingContact'=>$guest,
        'RoomStays'=>[[
            'Arrival'=>$arrival,
            'Departure'=>$departure,
            'RoomTypeId'=>(string)($allocation['RoomTypeID'] ?? ''),
            'RoomTypeName'=>$roomTypeName,
            'RoomTotal'=>$roomTotal,
            'Adults'=>(int)($allocation['NumberAdults'] ?? 0),
            'Children'=>(int)($allocation['NumberChildren'] ?? 0),
            'Infants'=>(int)($allocation['NumberInfants'] ?? 0),
            'GuestId'=>$guestId,
            'GuestName'=>trim((string)($coreAllocation['GuestName'] ?? '')),
            'IsCancelled'=>$statusCode === 4,
            'PolicyText'=>'The original booking terms apply.',
            'RateDetails'=>[['RatePlanId'=>$ratePlanId,'RatePlanName'=>'Booked rate','RoomRate'=>$roomTotal]],
        ]],
        'ProfileFields'=>$profiles['definitions'],
    ];
    // Quote from Phoenix so the figure the guest accepts is the one the cancel
    // path will post; fall back to the reservation view only if Phoenix cannot
    // answer, in which case portal/cancel refuses rather than guessing.
    $phoenix = phoenixCancellationQuote($reservationId, $roomAllocationId, $allocation);
    $quote = $phoenix['quote'] ?? cancellationQuote($reservation);
    return [
        'Reservation'=>$reservation,
        'Login'=>['ViewReservation'=>true,'UpdateGuestDetails'=>false,'SpecialRequests'=>false,'PayNow'=>false,'Cancel'=>false,'UpdateStayDates'=>false],
        'CurrentExtras'=>$currentExtras,
        'ProfileFieldDefinitions'=>$profiles['definitions'],
        'StoredCard'=>array_diff_key(pmsSavedCard($roomAllocationId), ['token'=>true,'accountId'=>true,'reservationNumber'=>true]),
        'PortalCapabilities'=>['Amend'=>true,'Extras'=>true,'Cancel'=>true,'ChargeCard'=>true],
        'PortalToken'=>issuePortalToken($confNum, $reservationId, $surname, $email, $quote),
        'CancellationQuote'=>$quote,
    ];
}

/** Use the verified identity to fetch GuestPoint's complete management view. */
/**
 * The cancellation quote as Phoenix's own numbers produce it.
 *
 * The portal used to quote from the Booking Engine's manage view at lookup time
 * and from these three Phoenix calculations at cancel time. The two disagree —
 * a guest could accept a $30 fee and have $100 posted to their room account.
 * Both ends now come through here, so the figure shown is the figure charged.
 *
 * Returns null when Phoenix cannot answer; the caller then falls back to the
 * Booking Engine view, and the guard in portal/cancel refuses the cancellation
 * rather than charging a number nobody agreed to.
 */
function phoenixCancellationQuote(string $reservationId, string $roomAllocationId, array $allocation): ?array {
    global $PROPERTY_ID;
    $emptyObject = (object)[];
    [$bookingValueStatus, $bookingValueRaw] = pmsCall('POST', 'Accounts/CalcBookingValue?propertyID=' . rawurlencode($PROPERTY_ID) . '&roomAllocationID=' . rawurlencode($roomAllocationId), $emptyObject);
    [$accountBalanceStatus, $accountBalanceRaw] = pmsCall('POST', 'Accounts/CalcRoomAccountBalance?propertyID=' . rawurlencode($PROPERTY_ID) . '&roomAllocationID=' . rawurlencode($roomAllocationId), $emptyObject);
    [$departureValueStatus, $departureValueRaw] = pmsCall('POST', 'Accounts/CalculateDepartureValue?propertyID=' . rawurlencode($PROPERTY_ID) . '&roomAllocationID=' . rawurlencode($roomAllocationId) . '&reservationnID=' . rawurlencode($reservationId), $emptyObject);
    $bookingValue = gpNumber(json_decode($bookingValueRaw, true));
    $accountBalance = gpNumber(json_decode($accountBalanceRaw, true));
    $departureValue = gpNumber(json_decode($departureValueRaw, true));
    if ($bookingValueStatus !== 200 || $accountBalanceStatus !== 200 || $departureValueStatus !== 200
        || $bookingValue === null || $accountBalance === null || $departureValue === null) return null;

    $arrival = substr((string)($allocation['ArrivalDate'] ?? ''), 0, 10);
    $nights = max(1, (int)($allocation['NumberOfNights'] ?? 1));
    try { $departure = (new DateTimeImmutable($arrival))->modify('+' . $nights . ' days')->format('Y-m-d'); }
    catch (Throwable $e) { return null; }
    $accommodationTotal = portalAccommodationTotal($roomAllocationId) ?? max(0.0, $bookingValue);
    return [
        'quote'=>cancellationQuote([
            'ReservationTotalAfterTax'=>max(0.0, $bookingValue),
            'PaymentRequired'=>max(0.0, $departureValue),
            'PayLater'=>max(0.0, $departureValue),
            'RoomStays'=>[['Arrival'=>$arrival,'Departure'=>$departure,'RoomTotal'=>$accommodationTotal,'IsCancelled'=>false]],
        ]),
        'bookingValue'=>$bookingValue,
        'accountBalance'=>$accountBalance,
        'departureValue'=>$departureValue,
        'accommodationTotal'=>$accommodationTotal,
    ];
}

/** Do two quotes agree on the money the guest is being asked to accept? */
function portalQuotesAgree(?array $accepted, array $fresh): bool {
    if (!is_array($accepted)) return false;
    foreach (['fee', 'refund'] as $field) {
        if (abs(round((float)($accepted[$field] ?? 0), 2) - round((float)($fresh[$field] ?? 0), 2)) > 0.005) return false;
    }
    return true;
}

function loadManagedReservation(string $confNum, string $surname, string $email, bool $decorate = true): array {
    global $UPSTREAM, $PROPERTY_ID, $API_KEY;
    $manageBody = json_encode([
        'ConfNum' => $confNum,
        'EmailAddress' => $email,
        'Surname' => $surname,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $url = $UPSTREAM . '/properties/' . rawurlencode($PROPERTY_ID) . '/reservations/manage';
    [$status, $response, $error] = upstreamCall('POST', $url, $API_KEY, $manageBody);
    if ($status === 0) return [502, null, $error];
    $payload = json_decode($response, true);
    if ($status < 200 || $status >= 300 || !is_array($payload) || (($payload['success'] ?? true) === false)) {
        return [$status >= 200 && $status < 300 ? 502 : ($status ?: 502), null, $response];
    }
    if (!$decorate) return [$status, $payload, null];

    if (isset($payload['data']) && is_array($payload['data'])) $root =& $payload['data'];
    elseif (isset($payload['Data']) && is_array($payload['Data'])) $root =& $payload['Data'];
    else $root =& $payload;
    $reservation = is_array($root['Reservation'] ?? null) ? $root['Reservation'] : [];
    $reservationId = trim((string)($reservation['ID'] ?? ''));
    if ($reservationId === '') return [502, null, 'GuestPoint returned a reservation without an id.'];
    // The Booking Engine management view can lag behind a Phoenix PMS edit.
    // On the test portal, overlay authoritative dates, occupancy and current
    // room balance from Core/Phoenix so a refresh never appears to undo a
    // change that the PMS has already accepted.
    global $PMS_PRIVATE_WRITES;
    $phoenixQuote = null;
    if ($PMS_PRIVATE_WRITES) {
        $core = resolvePortalCoreReservation($confNum, $surname);
        $coreAllocations = is_array($core['Allocations'] ?? null) ? array_values($core['Allocations']) : [];
        if (count($coreAllocations) === 1 && !empty($coreAllocations[0]['RoomAllocationId'])) {
            $roomAllocationId = (string)$coreAllocations[0]['RoomAllocationId'];
            [$pmsStatus, $pmsRaw] = pmsCall('GET', 'Reservation/GetRoomAllocation?roomAllocationID=' . rawurlencode($roomAllocationId));
            $pms = json_decode($pmsRaw, true);
            if ($pmsStatus === 200 && is_array($pms)) {
                $arrival = substr((string)($pms['ArrivalDate'] ?? ''), 0, 10);
                $nights = max(1, (int)($pms['NumberOfNights'] ?? 1));
                try { $departure = (new DateTimeImmutable($arrival))->modify('+' . $nights . ' days')->format('Y-m-d'); }
                catch (Throwable $e) { $departure = ''; }
                $root['Reservation']['Adults'] = (int)($pms['NumberAdults'] ?? $reservation['Adults'] ?? 0);
                $root['Reservation']['Children'] = (int)($pms['NumberChildren'] ?? $reservation['Children'] ?? 0);
                $root['Reservation']['Infants'] = (int)($pms['NumberInfants'] ?? $reservation['Infants'] ?? 0);
                $statusMap = [1=>'Modified', 2=>'Checked in', 3=>'Checked out', 4=>'Cancelled', 5=>'No show'];
                if (isset($statusMap[(int)($pms['Status'] ?? 0)])) $root['Reservation']['Status'] = $statusMap[(int)$pms['Status']];
                if ($arrival !== '' && $departure !== '' && is_array($root['Reservation']['RoomStays'] ?? null) && count($root['Reservation']['RoomStays']) === 1) {
                    $root['Reservation']['RoomStays'][0]['Arrival'] = $arrival;
                    $root['Reservation']['RoomStays'][0]['Departure'] = $departure;
                    $root['Reservation']['RoomStays'][0]['Adults'] = $root['Reservation']['Adults'];
                    $root['Reservation']['RoomStays'][0]['Children'] = $root['Reservation']['Children'];
                    $root['Reservation']['RoomStays'][0]['Infants'] = $root['Reservation']['Infants'];
                    $root['Reservation']['RoomStays'][0]['IsCancelled'] = (int)($pms['Status'] ?? 0) === 4;
                }
                $outstanding = gpNumber($core['AmountOutstanding'] ?? null) ?? gpNumber($pms['DepartureBalance'] ?? null);
                if ($outstanding !== null) {
                    $oldTotal = gpNumber($root['Reservation']['ReservationTotalAfterTax'] ?? null) ?? gpNumber($root['Reservation']['ReservationTotal'] ?? null) ?? 0.0;
                    $root['Reservation']['ReservationTotalAfterTax'] = max($oldTotal, $outstanding);
                    $root['Reservation']['PaymentRequired'] = max(0.0, $outstanding);
                    $root['Reservation']['PayLater'] = max(0.0, $outstanding);
                }
                $reservation = $root['Reservation'];
            }
            $coreReservationId = (string)($core['ReservationId'] ?? '');
            $root['CurrentExtras'] = portalCurrentExtras($roomAllocationId, $coreReservationId);
            $profiles = pmsReservationProfiles($coreReservationId);
            $root['ProfileFieldDefinitions'] = $profiles['definitions'];
            $root['Reservation']['ProfileFields'] = $profiles['definitions'];
            $root['StoredCard'] = array_diff_key(pmsSavedCard($roomAllocationId), ['token'=>true,'accountId'=>true,'reservationNumber'=>true]);
            if (is_array($pms ?? null)) {
                $phoenix = phoenixCancellationQuote($coreReservationId, $roomAllocationId, $pms);
                if (is_array($phoenix)) $phoenixQuote = $phoenix['quote'];
            }
        }
    }
    // See phoenixCancellationQuote: both ends of the cancellation must quote
    // from the same numbers or the guest accepts one fee and is charged another.
    $quote = $phoenixQuote ?? cancellationQuote($reservation);
    $root['PortalToken'] = issuePortalToken($confNum, $reservationId, $surname, $email, $quote);
    $root['CancellationQuote'] = $quote;
    if ($PMS_PRIVATE_WRITES) {
        $root['PortalCapabilities'] = ['Amend'=>true, 'Extras'=>true, 'Cancel'=>true, 'ChargeCard'=>!empty($root['StoredCard']['available'])];
    }
    // ExtraInfo is an internal reception note. Guests may append a new request,
    // but must never read or replace staff notes already on the reservation.
    if (isset($root['Reservation']) && is_array($root['Reservation'])) unset($root['Reservation']['ExtraInfo']);
    return [$status, $payload, null];
}

// -------------------------------------------------------- request parsing ----

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'OPTIONS') {
    $allowed = $config['allow_origins'] ?? [];
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
    }
    http_response_code(204);
    exit;
}

// Cross-origin POSTs are rejected outright — the site is same-origin.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $allowed = $config['allow_origins'] ?? [];
    $requestHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    $sameHost = parse_url($origin, PHP_URL_HOST) === $requestHost;
    if (!$sameHost && !in_array($origin, $allowed, true)) {
        fail(403, 'Origin not allowed.');
    }
    if (in_array($origin, $allowed, true)) header("Access-Control-Allow-Origin: $origin");
}

// "No credentials yet" is a deliberate state, not a failure, so it answers 200
// with a marker the client understands. A 503 here was honest but noisy: the
// browser logs every 4xx/5xx to the console whatever the page does about it,
// so a site waiting on credentials looked broken to anyone who opened devtools.
// Real faults still use real status codes.
if ($API_KEY === '' || str_starts_with($API_KEY, 'REPLACE_WITH')
    || $PROPERTY_ID === '' || str_starts_with($PROPERTY_ID, 'REPLACE_WITH')) {
    send(200, ['Unconfigured' => true,
               'Message' => 'Booking service is not configured yet.'],
              ['Cache-Control' => 'no-store']);
}

// PATH_INFO is what follows /api/gp — fall back to parsing REQUEST_URI when
// the server does not populate it (LiteSpeed with certain rewrite styles).
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($pathInfo === '') {
    $uriPath  = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $pathInfo = preg_replace('#^/api/gp#', '', $uriPath) ?? '';
}
$pathInfo = '/' . ltrim($pathInfo, '/');

if (!preg_match('#^/properties/([^/]+)/(.+)$#', $pathInfo, $m)) {
    fail(404, 'Unknown endpoint.');
}
[$_, $reqPropertyId, $endpoint] = $m;
$reqPropertyId = rawurldecode($reqPropertyId);

if ($reqPropertyId !== 'self' && !hash_equals($PROPERTY_ID, $reqPropertyId)) {
    fail(403, 'Unknown property.');
}

$endpoint = trim($endpoint, '/');
if (str_contains($endpoint, '..') || str_contains($endpoint, '//')) {
    fail(400, 'Bad request.');
}

// --------------------------------------------------------------- routing ----

$matchedRoute = null;
$segments = explode('/', $endpoint);
foreach (ROUTES as $pattern => $spec) {
    $parts = explode('/', $pattern);
    if (count($parts) !== count($segments)) continue;
    $ok = true;
    foreach ($parts as $i => $part) {
        if ($part === '*') {
            // one segment, and it must look like a code — not a traversal
            if (!preg_match('/^[A-Za-z0-9._%-]{1,64}$/', $segments[$i])) { $ok = false; break; }
        } elseif ($part !== $segments[$i]) { $ok = false; break; }
    }
    if ($ok) { $matchedRoute = $spec; break; }
}

if ($matchedRoute === null) fail(404, 'Unknown endpoint.');
[$allowedMethods, $ttl] = $matchedRoute;
if (!in_array($method, explode(',', $allowedMethods), true)) {
    fail(405, 'Method not allowed.', "$method on $endpoint");
}

// --------------------------------------------------- rate limit (writes) ----

if ($ttl === 0) {
    $bucket = $CACHE_DIR . '/rl_' . hash('sha256', clientIp());
    $now = time();
    $hits = [];
    if (is_file($bucket)) {
        $raw  = @file_get_contents($bucket);
        $hits = $raw ? (json_decode($raw, true) ?: []) : [];
    }
    $hits = array_values(array_filter($hits, fn($t) => $now - (int)$t < RESERVATION_WINDOW));
    if (count($hits) >= RESERVATION_LIMIT) {
        fail(429, 'Too many booking attempts. Please try again in a few minutes, or call 02 6456 2224.');
    }
    $hits[] = $now;
    @file_put_contents($bucket, json_encode($hits), LOCK_EX);
}

// ------------------------------------------------------------------ body ----

$body = null;
$decodedBody = null;
if ($method !== 'GET') {
    $body = file_get_contents('php://input') ?: '';
    if (strlen($body) > MAX_BODY_BYTES) fail(413, 'Request too large.');

    // The browser sends PropertyId: "self" for the same reason the path does —
    // the real id is server-side only. Swap it in here.
    if ($body !== '') {
        $decodedBody = json_decode($body, true);
        if (is_array($decodedBody) && isset($decodedBody['PropertyId']) && $decodedBody['PropertyId'] === 'self') {
            $decodedBody['PropertyId'] = $PROPERTY_ID;
            $body = json_encode($decodedBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
    if ($body !== '' && json_decode($body) === null && json_last_error() !== JSON_ERROR_NONE) {
        fail(400, 'Body must be JSON.');
    }
}

// Verify reference and surname against GuestPoint Core, then use the stored
// email server-side to request GuestPoint's complete management view.
if ($endpoint === 'portal/lookup') {
    $input = is_array($decodedBody) ? $decodedBody : [];
    $confNum = strtoupper(trim((string)($input['ConfNum'] ?? '')));
    $surname = trim((string)($input['Surname'] ?? ''));
    $validInput = preg_match('/^[A-Z0-9._-]{1,64}$/', $confNum)
        && $surname !== '' && strlen($surname) <= 80;
    $identity = $validInput ? resolvePortalBookingIdentity($confNum, $surname) : null;
    $coreReservation = is_array($identity) && is_array($identity['reservation'] ?? null) ? $identity['reservation'] : null;
    if (!is_array($coreReservation)) fail(403, 'We could not verify those booking details. Check them and try again.');
    $email = portalReservationEmail($coreReservation);
    $manageReference = (string)($identity['manageReference'] ?? $confNum);
    $status = 502; $payload = null; $detail = '';
    if ($email !== '') [$status, $payload, $detail] = loadManagedReservation($manageReference, $surname, $email);
    // Reservations created directly in Phoenix have no Booking Engine manage
    // record and may legitimately have no stored email. The already verified
    // Reservation Number + surname pair can still open a PMS-backed session.
    if ((!is_array($payload) || $status < 200 || $status >= 300) && $PMS_PRIVATE_WRITES) {
        $payload = pmsManagedReservationPayload($identity, $surname, $email);
        $status = is_array($payload) ? 200 : 502;
    }
    if (!is_array($payload)) fail(502, 'The booking system is not responding. Please try again shortly.', (string)$detail);
    send($status, $payload, ['Cache-Control' => 'no-store']);
}

/** Resolve the Phoenix IDs for the signed booking session. */
function portalPmsIdentity(string $confNum, array $tokenPayload): array {
    $core = resolvePortalCoreReservation($confNum, (string)($tokenPayload['sn'] ?? ''));
    if (!is_array($core)) return [];
    $allocations = array_values(array_filter($core['Allocations'] ?? [], fn($a) => is_array($a) && !empty($a['RoomAllocationId'])));
    return [
        'reservationId' => (string)($core['ReservationId'] ?? ''),
        'allocations' => $allocations,
    ];
}

function requirePortalSession(array $input): array {
    $confNum = strtoupper(trim((string)($input['ConfNum'] ?? '')));
    $tokenPayload = portalTokenPayload((string)($input['PortalToken'] ?? ''), $confNum);
    if (!preg_match('/^[A-Z0-9._-]{3,64}$/', $confNum) || $tokenPayload === null) {
        fail(403, 'Your booking session has expired. Please look up the booking again.');
    }
    $identity = portalPmsIdentity($confNum, $tokenPayload);
    if (($identity['reservationId'] ?? '') === '' || count($identity['allocations'] ?? []) !== 1) {
        fail(409, 'This portal can only change a booking with one accommodation. Nothing was changed.');
    }
    return [$confNum, $tokenPayload, $identity];
}

/** Unwrap GuestPoint's optional data/Data envelope. */
function managedRoot(array $payload): array {
    if (isset($payload['data']) && is_array($payload['data'])) return $payload['data'];
    if (isset($payload['Data']) && is_array($payload['Data'])) return $payload['Data'];
    return $payload;
}

/**
 * Re-price a proposed stay against GuestPoint's live Booking Engine response.
 * The browser's displayed total is never trusted: room type, original rate
 * plan, capacity, closures and nightly prices are all resolved server-side.
 */
function portalStayQuote(string $confNum, array $tokenPayload, string $arrival, string $departure, int $adults, int $children, bool $requireInventory): array {
    global $UPSTREAM, $PROPERTY_ID, $API_KEY, $PMS_PRIVATE_WRITES;
    [$manageStatus, $managePayload] = loadManagedReservation(
        $confNum,
        (string)($tokenPayload['sn'] ?? ''),
        (string)($tokenPayload['em'] ?? ''),
        false
    );
    $stays = [];
    if ($manageStatus >= 200 && $manageStatus < 300 && is_array($managePayload)) {
        $root = managedRoot($managePayload);
        $reservation = is_array($root['Reservation'] ?? null) ? $root['Reservation'] : [];
        $stays = array_values(array_filter($reservation['RoomStays'] ?? [], fn($s) => is_array($s) && empty($s['IsCancelled'])));
    } elseif ($PMS_PRIVATE_WRITES) {
        // A reservation created directly in Phoenix has no Booking Engine
        // management record. Its signed portal session has already been
        // verified against Core, so use the authoritative Phoenix allocation
        // to identify the original room type and package before re-pricing.
        $identity = portalPmsIdentity($confNum, $tokenPayload);
        $reservationId = (string)($identity['reservationId'] ?? '');
        if ($reservationId !== '' && count($identity['allocations'] ?? []) === 1) {
            [$detailsStatus, $detailsRaw] = pmsCall('GET', 'Reservation/GetRoomAllocationDetail2sByReservation?reservationID=' . rawurlencode($reservationId));
            $details = json_decode($detailsRaw, true);
            if ($detailsStatus === 200 && is_array($details) && count($details) === 1 && is_array($details[0])) {
                $allocation = $details[0];
                $roomType = is_array($allocation['_RoomType'] ?? null) ? $allocation['_RoomType'] : [];
                $stays = [[
                    'RoomTypeId'=>(string)($allocation['RoomTypeID'] ?? ''),
                    'RoomTypeName'=>(string)($roomType['Name'] ?? $roomType['RoomType'] ?? ''),
                    'RateDetails'=>[[
                        'RatePlanId'=>(string)($allocation['PackageID'] ?? ''),
                        'RatePlanName'=>'',
                    ]],
                ]];
            }
        }
    }
    if (count($stays) !== 1) fail(502, 'GuestPoint could not load the original room and rate. Nothing was changed.');
    $stay = $stays[0];
    $roomTypeId = (string)($stay['RoomTypeId'] ?? '');
    $roomTypeName = strtolower(trim((string)($stay['RoomTypeName'] ?? '')));
    $rate = is_array(($stay['RateDetails'] ?? [])[0] ?? null) ? $stay['RateDetails'][0] : [];
    $ratePlanId = (string)($rate['RatePlanId'] ?? '');
    $ratePlanName = strtolower(trim((string)($rate['RatePlanName'] ?? '')));
    if ($roomTypeId === '') fail(502, 'GuestPoint did not return the booked room type. Nothing was changed.');

    $url = $UPSTREAM . '/properties/' . rawurlencode($PROPERTY_ID) . '/availabilities?' . http_build_query([
        'arrivalDate' => $arrival,
        'departureDate' => $departure,
        'numAdults' => $adults,
        'numChildren' => $children,
        'roomTypes' => $roomTypeId,
    ], '', '&', PHP_QUERY_RFC3986);
    [$status, $raw, $error] = upstreamCall('GET', $url, $API_KEY);
    $availability = json_decode($raw, true);
    if ($status !== 200 || !is_array($availability)) fail(502, 'GuestPoint could not confirm live availability and pricing. Nothing was changed.', $error ?: $raw);
    $availability = managedRoot($availability);
    $properties = is_array($availability['Properties'] ?? null) ? $availability['Properties'] : [];
    $roomTypes = is_array(($properties[0]['RoomTypes'] ?? null)) ? $properties[0]['RoomTypes'] : [];
    $room = null;
    foreach ($roomTypes as $candidate) {
        if (!is_array($candidate)) continue;
        $candidateId = (string)($candidate['Id'] ?? $candidate['RoomTypeId'] ?? '');
        $candidateName = strtolower(trim((string)($candidate['Name'] ?? '')));
        if (($candidateId !== '' && hash_equals($roomTypeId, $candidateId)) || ($roomTypeName !== '' && $candidateName === $roomTypeName)) { $room = $candidate; break; }
    }
    if (!is_array($room)) fail(409, 'Your booked accommodation is not available for those dates. Nothing was changed.');
    $maxGuests = (int)($room['MaxGuests'] ?? $room['MaxOccupancy'] ?? 0);
    if ($maxGuests > 0 && $adults + $children > $maxGuests) fail(409, 'This accommodation allows a maximum of ' . $maxGuests . ' guests.');

    $from = new DateTimeImmutable($arrival);
    $to = new DateTimeImmutable($departure);
    $nights = (int)$from->diff($to)->format('%r%a');
    $availabilityNights = array_values(array_filter($room['Availabilities'] ?? [], function($day) use ($arrival, $departure) {
        $date = substr((string)($day['Date'] ?? ''), 0, 10);
        return is_array($day) && $date >= $arrival && $date < $departure;
    }));
    if ($requireInventory && (count($availabilityNights) !== $nights || array_filter($availabilityNights, fn($day) => !empty($day['Closed']) || (isset($day['ForSale']) && (int)$day['ForSale'] < 1)))) {
        fail(409, 'Your booked accommodation is not available for every selected night. Nothing was changed.');
    }

    $plan = null;
    foreach (($room['RatePlans'] ?? []) as $candidate) {
        if (!is_array($candidate)) continue;
        $candidateId = (string)($candidate['Id'] ?? $candidate['RatePlanId'] ?? '');
        $candidateName = strtolower(trim((string)($candidate['Name'] ?? '')));
        if (($ratePlanId !== '' && $candidateId !== '' && hash_equals($ratePlanId, $candidateId)) || ($ratePlanName !== '' && $candidateName === $ratePlanName)) { $plan = $candidate; break; }
    }
    if (!is_array($plan)) fail(409, 'Your original rate plan is not available for those dates. Nothing was changed.');
    $nightly = [];
    foreach (($plan['Rates'] ?? []) as $day) {
        if (!is_array($day)) continue;
        $date = substr((string)($day['Date'] ?? ''), 0, 10);
        if ($date < $arrival || $date >= $departure) continue;
        if (!empty($day['Closed']) || !empty($day['ClosedDueToCriteria'])) fail(409, 'Your original rate is closed for one or more selected nights. Nothing was changed.');
        $nightly[] = ['date'=>$date, 'rate'=>round((float)($day['SellRate'] ?? 0), 2)];
    }
    usort($nightly, fn($a, $b) => strcmp($a['date'], $b['date']));
    if (count($nightly) !== $nights || array_filter($nightly, fn($day) => $day['rate'] <= 0)) fail(409, 'GuestPoint did not return a complete nightly price. Nothing was changed.');
    return ['nightly'=>$nightly, 'total'=>round(array_sum(array_column($nightly, 'rate')), 2), 'maxGuests'=>$maxGuests];
}

/** Save authoritative nightly prices and zero obsolete rows after shortening. */
function savePortalNightlyCharges(string $roomAllocationId, array $nightly): void {
    global $PROPERTY_ID;
    [$status, $raw] = pmsCall('GET', 'Reservation/GetRoomAllocationChargesForReservationByRoomAllocationID?propertyID=' . rawurlencode($PROPERTY_ID) . '&roomAllocationID=' . rawurlencode($roomAllocationId));
    $charges = json_decode($raw, true);
    if ($status !== 200 || !is_array($charges) || !$charges) fail(502, 'GuestPoint could not load the nightly charges. Nothing was changed.');
    usort($charges, fn($a, $b) => strcmp((string)($a['Date'] ?? ''), (string)($b['Date'] ?? '')));
    $template = $charges[0];
    foreach ($nightly as $i => $day) {
        if (!isset($charges[$i])) {
            $charges[$i] = $template;
            $charges[$i]['RoomAllocationChargeID'] = uuidV4();
            unset($charges[$i]['Version'], $charges[$i]['UpdatedServerDate']);
        }
        $charges[$i]['Date'] = $day['date'] . 'T00:00:00';
        $charges[$i]['Room'] = $day['rate'];
        $charges[$i]['ExtraPersons'] = 0;
        $charges[$i]['Discount'] = 0;
    }
    for ($i = count($nightly); $i < count($charges); $i++) {
        $charges[$i]['Room'] = 0;
        $charges[$i]['ExtraPersons'] = 0;
        $charges[$i]['Discount'] = 0;
    }
    [$saveStatus, $saveRaw] = pmsCall('POST', 'Reservation/SaveRoomAllocationCharges?propertyID=' . rawurlencode($PROPERTY_ID), $charges);
    if ($saveStatus < 200 || $saveStatus >= 300 || !is_array(json_decode($saveRaw, true))) fail(502, 'GuestPoint rejected the revised nightly prices. Please check the booking in GuestPoint.', $saveRaw);
}

/** Fetch the eligible extras from GuestPoint; client-supplied prices are ignored. */
function portalExtrasCatalog(string $confNum, array $tokenPayload): array {
    global $UPSTREAM, $PROPERTY_ID, $API_KEY;
    [$manageStatus, $managePayload] = loadManagedReservation(
        $confNum,
        (string)($tokenPayload['sn'] ?? ''),
        (string)($tokenPayload['em'] ?? ''),
        false
    );
    $root = ($manageStatus >= 200 && $manageStatus < 300 && is_array($managePayload)) ? managedRoot($managePayload) : [];
    $stays = $root['Reservation']['RoomStays'] ?? [];
    if (!is_array($stays) || count($stays) !== 1) {
        $identity = portalPmsIdentity($confNum, $tokenPayload);
        $reservationId = (string)($identity['reservationId'] ?? '');
        if ($reservationId === '' || count($identity['allocations'] ?? []) !== 1) fail(502, 'GuestPoint could not load the booking extras. Nothing was changed.');
        [$detailsStatus, $detailsRaw] = pmsCall('GET', 'Reservation/GetRoomAllocationDetail2sByReservation?reservationID=' . rawurlencode($reservationId));
        $details = json_decode($detailsRaw, true);
        if ($detailsStatus !== 200 || !is_array($details) || count($details) !== 1 || !is_array($details[0])) fail(502, 'GuestPoint could not load the booking extras. Nothing was changed.');
        $allocation = $details[0];
        $arrival = substr((string)($allocation['ArrivalDate'] ?? ''), 0, 10);
        $nights = max(1, (int)($allocation['NumberOfNights'] ?? 1));
        try { $departure = (new DateTimeImmutable($arrival))->modify('+' . $nights . ' days')->format('Y-m-d'); }
        catch (Throwable $e) { fail(502, 'GuestPoint returned invalid booking dates. Nothing was changed.'); }
        $stays = [[
            'Arrival'=>$arrival,
            'Departure'=>$departure,
            'RoomTypeId'=>(string)($allocation['RoomTypeID'] ?? ''),
            'RatePlanId'=>(string)($allocation['PackageID'] ?? ''),
            'Adults'=>(int)($allocation['NumberAdults'] ?? 0),
            'Children'=>(int)($allocation['NumberChildren'] ?? 0),
            'Infants'=>(int)($allocation['NumberInfants'] ?? 0),
        ]];
    }
    if (!is_array($stays) || count($stays) !== 1) fail(409, 'Extras can only be added online to a booking with one accommodation.');
    $body = json_encode(['RoomStays'=>$stays], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $url = $UPSTREAM . '/properties/' . rawurlencode($PROPERTY_ID) . '/extras';
    [$status, $raw, $error] = upstreamCall('POST', $url, $API_KEY, $body);
    $payload = json_decode($raw, true);
    if ($status !== 200 || !is_array($payload)) fail(502, 'GuestPoint could not confirm the eligible extras. Nothing was added.', $error ?: $raw);
    $payload = managedRoot($payload);
    if (!array_is_list($payload)) $payload = $payload['Extras'] ?? [];
    return is_array($payload) ? $payload : [];
}

/** Build the authoritative desired/current extras comparison from GuestPoint. */
function portalExtrasPlan(string $confNum, array $tokenPayload, array $identity, array $items): array {
    global $PROPERTY_ID;
    $roomAllocationId = (string)$identity['allocations'][0]['RoomAllocationId'];
    $reservationId = (string)$identity['reservationId'];
    [$allocationStatus, $allocationRaw] = pmsCall('GET', 'Reservation/GetRoomAllocation?roomAllocationID=' . rawurlencode($roomAllocationId));
    $allocation = json_decode($allocationRaw, true);
    if ($allocationStatus !== 200 || !is_array($allocation) || (int)($allocation['Status'] ?? 0) !== 1) fail(409, 'This booking can no longer accept extras.');
    $catalog = portalExtrasCatalog($confNum, $tokenPayload);
    $eligible = [];
    foreach ($catalog as $extra) if (is_array($extra) && !empty($extra['Id'])) $eligible[strtolower((string)$extra['Id'])] = $extra;
    $requested = [];
    foreach ($items as $item) {
        if (!is_array($item) || !preg_match('/^[a-f0-9-]{36}$/i', (string)($item['id'] ?? ''))) fail(400, 'One of the selected extras is invalid.');
        $key = strtolower((string)$item['id']);
        if (!isset($eligible[$key])) fail(409, 'One of the selected extras is no longer offered for this booking.');
        $requested[$key] = $item;
    }
    // Every id has just been checked against the catalogue, so this can only
    // trip on a payload padded with duplicates.
    if (count($requested) > count($eligible)) fail(400, 'Submit the extras shown for this booking.');
    $currentRows = portalCurrentExtras($roomAllocationId, $reservationId);
    $current = [];
    foreach ($currentRows as $row) if (is_array($row) && !empty($row['Id'])) $current[strtolower((string)$row['Id'])] = $row;
    $masters = pmsAddonDefinitions();
    $adults = max(0, (int)($allocation['NumberAdults'] ?? 0));
    $children = max(0, (int)($allocation['NumberChildren'] ?? 0));
    $nights = max(1, (int)($allocation['NumberOfNights'] ?? 1));
    $planItems = [];
    foreach ($eligible as $key => $definition) {
        $request = $requested[$key] ?? [];
        $quantity = max(0, min(20, (int)($request['quantity'] ?? 0)));
        $childQuantity = max(0, min(20, (int)($request['childQuantity'] ?? 0)));
        $priceType = (string)($definition['PriceType'] ?? 'perBooking');
        $checkoutType = strtolower((string)($definition['CheckoutType'] ?? 'quantity'));
        $perPerson = in_array($priceType, ['perPerson','perPersonPerNight'], true);
        $perNight = in_array($priceType, ['perNight','perPersonPerNight'], true);
        if ($checkoutType === 'service') {
            $selected = $quantity + $childQuantity > 0;
            $quantity = $selected ? ($perPerson ? $adults : 1) : 0;
            $childQuantity = $selected && $perPerson ? $children : 0;
        } elseif ($perPerson) {
            if ($quantity > $adults || $childQuantity > $children) fail(409, 'Extra quantities cannot exceed the guests on this booking.');
        } else {
            $max = (int)($definition['MaxItems'] ?? 0);
            if ($max > 0 && $quantity + $childQuantity > $max) fail(409, 'That extra exceeds GuestPoint’s configured quantity limit.');
        }
        $rate = 0.0; $childRate = 0.0;
        foreach (($definition['Prices'] ?? []) as $price) {
            if (!is_array($price)) continue;
            $name = strtolower((string)($price['Name'] ?? ''));
            $value = round(max(0, (float)($price['Price'] ?? 0)), 2);
            if (str_contains($name, 'child')) $childRate = $value;
            elseif ($rate === 0.0 || str_contains($name, 'adult')) $rate = $value;
        }
        if ($childRate === 0.0) $childRate = $rate;
        $multiplier = $perNight ? $nights : 1;
        $total = round(($quantity * $rate + $childQuantity * $childRate) * $multiplier, 2);
        $currentRow = $current[$key] ?? [];
        $planItems[] = [
            'id'=>(string)$definition['Id'], 'name'=>(string)($masters[$key]['Name'] ?? $definition['Name'] ?? 'Extra'),
            'quantity'=>$quantity, 'childQuantity'=>$childQuantity, 'rate'=>$rate, 'childRate'=>$childRate,
            'perNight'=>$perNight, 'nights'=>$nights, 'total'=>$total,
            'currentTotal'=>round(max(0, (float)($currentRow['Total'] ?? 0)), 2),
            // Captured so a failed charge can put the account back exactly as
            // it was, rather than leaving the guest's selection posted unpaid.
            'currentQuantity'=>max(0, (int)round((float)($currentRow['Quantity'] ?? 0))),
            'currentChildQuantity'=>max(0, (int)round((float)($currentRow['ChildQuantity'] ?? 0))),
            'master'=>$masters[$key] ?? null,
        ];
    }
    $currentTotal = round(array_sum(array_column($planItems, 'currentTotal')), 2);
    $newTotal = round(array_sum(array_column($planItems, 'total')), 2);
    $difference = round($newTotal - $currentTotal, 2);
    $card = pmsSavedCard($roomAllocationId);
    return ['roomAllocationId'=>$roomAllocationId,'reservationId'=>$reservationId,'allocation'=>$allocation,'items'=>$planItems,
        'CurrentTotal'=>$currentTotal,'NewTotal'=>$newTotal,'Difference'=>$difference,'ChargeAmount'=>max(0.0,$difference),'CreditAmount'=>max(0.0,-$difference),
        // How the extra gets paid for, decided once here so the quote the guest
        // accepts and the save that follows can never disagree:
        //   none    — nothing more to pay (a removal, or no change)
        //   card    — a usable saved card, charged when they save
        //   account — no usable card, so the charge is posted to the room
        //             account and settled at reception. Plenty of bookings
        //             have no card; refusing them the extra entirely was a
        //             dead end, not a safeguard.
        'card'=>$card,
        'PaymentMethod'=>$difference <= 0 ? 'none' : (!empty($card['available']) ? 'card' : 'account'),
        // Kept for the browser contract. Every priced change can now be
        // applied; only the way it is paid for varies.
        'CanComplete'=>true];
}

/**
 * The same plan, rewound: every item back to the quantities and totals that
 * were on the account before this request touched it.
 *
 * Feeding this to savePortalExtraTransactions undoes the change through the
 * ordinary reversal path, leaving a complete audit trail rather than trying to
 * delete rows.
 */
function portalExtrasRestorePlan(array $plan): array {
    foreach ($plan['items'] as $index => $item) {
        $plan['items'][$index]['quantity'] = (int)($item['currentQuantity'] ?? 0);
        $plan['items'][$index]['childQuantity'] = (int)($item['currentChildQuantity'] ?? 0);
        $plan['items'][$index]['total'] = round((float)($item['currentTotal'] ?? 0), 2);
    }
    return $plan;
}

function portalExtrasPublicQuote(array $plan): array {
    $card = is_array($plan['card'] ?? null) ? $plan['card'] : [];
    $method = (string)($plan['PaymentMethod'] ?? 'none');
    return ['CurrentTotal'=>$plan['CurrentTotal'],'NewTotal'=>$plan['NewTotal'],'Difference'=>$plan['Difference'],'ChargeAmount'=>$plan['ChargeAmount'],'CreditAmount'=>$plan['CreditAmount'],
        'CanComplete'=>$plan['CanComplete'],'PaymentMethod'=>$method,
        // The guest has to know which of the two they are agreeing to before
        // they accept, not after the account has already moved.
        'PayableAtReception'=>$method === 'account' ? $plan['ChargeAmount'] : 0.0,
        'Card'=>['Available'=>!empty($card['available']),'Mask'=>(string)($card['mask'] ?? ''),'Expiry'=>(string)($card['expiry'] ?? ''),'Name'=>(string)($card['holder'] ?? '')]];
}

/**
 * Post/reverse GuestPoint room-account extras and return the fresh rows.
 *
 * $fatal is false when this is being used to roll a failed change back: a
 * rollback must never end the request with "nothing was charged", because by
 * then something already has been. It returns null instead so the caller can
 * tell the guest what really happened.
 */
function savePortalExtraTransactions(array $plan, bool $fatal = true): ?array {
    global $PROPERTY_ID;
    $reservationId = (string)$plan['reservationId'];
    $roomAllocationId = (string)$plan['roomAllocationId'];
    $allocation = $plan['allocation'];
    $transactions = pmsTransactionItems($reservationId);
    // Memoised, so the payment path's lookup of the card account's name reuses
    // this read rather than spending another call against the request budget.
    $accounts = pmsTransactionAccounts();
    $now = new DateTimeImmutable('now', new DateTimeZone('Australia/Sydney'));
    foreach ($plan['items'] as $desired) {
        $addonId = (string)$desired['id'];
        $activeIndexes = [];
        $activeTotal = 0.0; $activeQuantity = 0; $activeChild = 0;
        foreach ($transactions as $index => $row) {
            if (strcasecmp((string)($row['AddonID'] ?? ''), $addonId) !== 0 || (string)($row['RoomAllocationID'] ?? '') !== $roomAllocationId || (float)($row['AmountInc'] ?? 0) <= 0 || !empty($row['IsReversed']) || !empty($row['ReversedTransactionItemID'])) continue;
            $activeIndexes[] = $index; $activeTotal += (float)$row['AmountInc']; $activeQuantity += (int)round((float)($row['Quantity'] ?? 0)); $activeChild += (int)round((float)($row['QuantityChild'] ?? 0));
        }
        if (abs($activeTotal - (float)$desired['total']) < 0.005 && $activeQuantity === (int)$desired['quantity'] && $activeChild === (int)$desired['childQuantity']) continue;
        foreach ($activeIndexes as $index) {
            $original =& $transactions[$index];
            $original['IsReversed'] = true;
            $transactions[] = [
                'TransactionItemID'=>uuidV4(),'PropertyID'=>$PROPERTY_ID,'TransactionAccountID'=>(string)$original['TransactionAccountID'],'TransactionType'=>(int)($original['TransactionType'] ?? 2),
                'PersonID'=>(string)($original['PersonID'] ?? pmsAccountPersonId($roomAllocationId)),'RoomAllocationID'=>$roomAllocationId,'AddonID'=>$addonId,
                'AccountingDate'=>$now->format('Y-m-d\TH:i:s'),'PrintDate'=>$now->format('Y-m-d\T00:00:00'),'Quantity'=>(float)($original['Quantity'] ?? 0),'QuantityChild'=>(float)($original['QuantityChild'] ?? 0),
                'Tax'=>0,'TaxRate'=>(float)($original['TaxRate'] ?? 0.1),'AmountInc'=>-abs((float)$original['AmountInc']),'Description'=>(string)($original['Description'] ?? $desired['name']),
                'ReversedTransactionItemID'=>(string)$original['TransactionItemID'],'IsNotAllowedToReverse'=>false,'UpdatedLocal'=>0,
            ];
            unset($original);
        }
        if ((float)$desired['total'] <= 0) continue;
        $master = is_array($desired['master']) ? $desired['master'] : [];
        $transactionAccountId = (string)($master['TransactionAccountID'] ?? '');
        if ($transactionAccountId === '') {
            if (!$fatal) return null;
            fail(409, 'GuestPoint has not assigned a transaction account to ' . $desired['name'] . '.');
        }
        $account = $accounts[strtolower($transactionAccountId)] ?? [];
        $transactions[] = [
            'TransactionItemID'=>uuidV4(),'PropertyID'=>$PROPERTY_ID,'TransactionAccountID'=>$transactionAccountId,'TransactionType'=>(int)($account['TransactionType'] ?? 2),
            'PersonID'=>pmsAccountPersonId($roomAllocationId),'RoomAllocationID'=>$roomAllocationId,'AddonID'=>$addonId,
            'AccountingDate'=>$now->format('Y-m-d\TH:i:s'),'PrintDate'=>$now->format('Y-m-d\T00:00:00'),'Quantity'=>(float)$desired['quantity'],'QuantityChild'=>(float)$desired['childQuantity'],
            'Tax'=>0,'TaxRate'=>(float)($account['TaxRate'] ?? 0.1),'AmountInc'=>(float)$desired['total'],'Description'=>(string)$desired['name'],'IsNotAllowedToReverse'=>false,'UpdatedLocal'=>0,
        ];
    }
    [$saveStatus, $saveRaw] = pmsCall('POST', 'Accounts/SaveTransactionItemDetails?propertyID=' . rawurlencode($PROPERTY_ID) . '&addChangeLogs=true', $transactions);
    pmsForgetTransactions($reservationId);
    $saved = json_decode($saveRaw, true);
    if ($saveStatus < 200 || $saveStatus >= 300 || !is_array($saved)) {
        if (!$fatal) return null;
        fail(502, 'GuestPoint rejected the extra account changes. Nothing was charged.', $saveRaw);
    }
    return $saved;
}

/**
 * Charge the exact positive extras delta to the saved card, as Phoenix does.
 *
 * This never exits on failure, because by the time it runs the extras are
 * already on the room account and the caller has to decide whether to unwind
 * them. The distinction that matters is whether we *know* no money moved:
 *
 *   blocked        nothing was attempted           — safe to unwind
 *   declined       the gateway said it did not process — safe to unwind
 *   indeterminate  no answer, a 5xx, or a different amount than we asked for
 *                  — money may well have moved, so never unwind
 *   success        confirmed for the exact amount
 *
 * An HTTP 500 from a payment endpoint is deliberately *not* treated as a
 * decline. Unwinding on one risks handing back extras the guest has paid for.
 */
function chargePortalSavedCard(array $plan): array {
    global $PROPERTY_ID, $PMS_USERNAME;
    $amount = round((float)($plan['ChargeAmount'] ?? 0), 2);
    if ($amount <= 0) return ['charged'=>false,'outcome'=>'skipped','amount'=>0.0,'transactionId'=>null,'detail'=>null];
    $card = $plan['card'] ?? [];
    if (empty($card['available'])) return ['charged'=>false,'outcome'=>'blocked','amount'=>$amount,'transactionId'=>null,'detail'=>'The saved card is unavailable or expired.'];
    $proxyPassword = pmsProxyPassword();
    if ($proxyPassword === '') return ['charged'=>false,'outcome'=>'blocked','amount'=>$amount,'transactionId'=>null,'detail'=>'GuestPoint did not supply the payment credential.'];
    // Phoenix sends the amount the way JSON would ("20", "20.5"), not padded to
    // two decimals. Matching the client GuestPoint already accepts costs nothing.
    $amountParam = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    $query = http_build_query([
        'username'=>$PMS_USERNAME,'password'=>$proxyPassword,'propertyID'=>$PROPERTY_ID,'sourceApplication'=>'GuestPoint',
        'creditCardPaymentInfo.amount'=>$amountParam,'creditCardPaymentInfo.reservationNumber'=>$card['reservationNumber'],
        'creditCardPaymentInfo.isRefund'=>'false','creditCardPaymentInfo.isMotoRefund'=>'false','creditCardPaymentInfo.cCName'=>$card['holder'] ?? '',
        'creditCardPaymentInfo.cCPartialNumber'=>$card['mask'] ?? '','creditCardPaymentInfo.cCTransactionAccountID'=>$card['accountId'],
        'creditCardPaymentInfo.cCExpiry'=>$card['expiry'] ?? '','creditCardPaymentInfo.cCNumberToken'=>$card['token'],
        'creditCardPaymentInfo.isUSedInFutureReservation'=>'false','creditCardPaymentInfo.isEftposTerminal'=>'false','creditCardPaymentInfo.selectedEftposTerminal'=>'','saveOnServer'=>'true',
    ], '', '&', PHP_QUERY_RFC3986);
    // The query string says "charge this card this much". The body says which
    // room account the money lands on. Without it GuestPoint has nothing to
    // attach its own TransactionType 11 row to, so the charge can succeed at the
    // gateway while the booking account never shows the payment — which is
    // exactly what portalPaymentRowIds() below is waiting to see. Captured from
    // the Phoenix client against this property; the WebAPI has no spec here.
    $allocation = is_array($plan['allocation'] ?? null) ? $plan['allocation'] : [];
    $accountId = (string)$card['accountId'];
    $accountName = trim((string)(pmsTransactionAccounts()[strtolower($accountId)]['Name'] ?? ''));
    $body = [
        'CompanyID'=>'', 'PersonID'=>pmsAccountPersonId((string)($plan['roomAllocationId'] ?? '')), 'GroupID'=>'',
        'RoomAllocationID'=>(string)($plan['roomAllocationId'] ?? ''), 'NonResidentialID'=>'',
        'AppuserID'=>pmsProxyAppuserId(),
        'Description'=>$accountName !== '' ? $accountName : 'Card payment',
        'PrintDate'=>(new DateTimeImmutable('now', new DateTimeZone('Australia/Sydney')))->format('Y-m-d\T00:00:00'),
        'Surcharge'=>0, 'SelectedTransactionAccountID'=>$accountId,
    ];
    if ($body['PersonID'] === '' || $body['RoomAllocationID'] === '' || $body['AppuserID'] === '') {
        return ['charged'=>false,'outcome'=>'blocked','amount'=>$amount,'transactionId'=>null,
                'detail'=>'GuestPoint did not supply the account details the payment has to be posted against.'];
    }
    [$status, $raw] = pmsCall('POST', 'CreditCardVault/ProcessPaymentUsingProxyPost?' . $query, $body);
    $result = json_decode($raw, true);
    if ($status >= 200 && $status < 300 && is_array($result) && ($result['IsPaymentProcessed'] ?? false) === true) {
        // Processed, but for a different amount than we asked for. Nobody
        // should unwind anything automatically on that.
        if (abs((float)($result['Amount'] ?? 0) - $amount) > 0.005) {
            return ['charged'=>true,'outcome'=>'indeterminate','amount'=>round((float)($result['Amount'] ?? 0), 2),
                    'transactionId'=>(string)($result['TransactionId'] ?? ''),'detail'=>'The gateway processed a different amount than the one quoted.'];
        }
        return ['charged'=>true,'outcome'=>'success','amount'=>$amount,
                'transactionId'=>(string)($result['TransactionId'] ?? ''),'authCode'=>(string)($result['AuthCode'] ?? ''),'detail'=>null];
    }
    // A parseable answer saying it was not processed is a real decline. Any
    // other shape — a 5xx, an empty body, a connection that never completed —
    // leaves us unable to say whether the card was charged.
    $declined = $status >= 200 && $status < 500 && is_array($result) && ($result['IsPaymentProcessed'] ?? null) === false;
    return ['charged'=>false,'outcome'=>$declined ? 'declined' : 'indeterminate','amount'=>$amount,'transactionId'=>null,
            'detail'=>$declined ? (string)($result['ErrorMessage'] ?? 'The card was declined.') : 'GuestPoint did not answer the payment request.','raw'=>$raw];
}

/**
 * Identify the room-account payment rows already on a reservation.
 *
 * GuestPoint posts its own TransactionType 11 row when ProcessPaymentUsingProxyPost
 * succeeds, so the proxy must never post one itself — it would double-count the
 * payment. Verification is therefore "did a new negative type-11 row appear",
 * which is why we need the set that existed beforehand.
 *
 * @return array<string,true> keyed by TransactionItemID
 */
function portalPaymentRowIds(array $transactions): array {
    $ids = [];
    foreach ($transactions as $row) {
        if (!is_array($row) || (int)($row['TransactionType'] ?? 0) !== 11) continue;
        $id = trim((string)($row['TransactionItemID'] ?? ''));
        if ($id !== '') $ids[$id] = true;
    }
    return $ids;
}

/**
 * Wait, briefly, for GuestPoint to post the payment row for a charge we already
 * know succeeded.
 *
 * Matching on the gateway reference appearing inside the row description is not
 * reliable: the description format is not documented anywhere in this repo. A
 * new row that was not there before, of the right sign and amount, is the
 * signal we can actually depend on; the reference is accepted as well when it
 * does show up.
 *
 * Returns false only when the row has not appeared yet. That is a reporting
 * delay, never a reason to tell the guest their payment failed.
 */
function awaitPortalPaymentRow(string $reservationId, array $knownPaymentRowIds, float $amount, string $reference, float $deadline): bool {
    for ($attempt = 0; $attempt < 6; $attempt++) {
        if ($attempt > 0) {
            if (microtime(true) >= $deadline) return false;
            usleep(400000);
        }
        // Reads are memoised per request, so without this the loop would ask
        // the same cached array six times and could never see a row that lands
        // after the charge — reporting a completed payment as unverified.
        pmsForgetTransactions($reservationId);
        foreach (pmsTransactionItems($reservationId) as $row) {
            if ((int)($row['TransactionType'] ?? 0) !== 11) continue;
            $rowAmount = (float)($row['AmountInc'] ?? 0);
            if ($rowAmount >= 0 || abs(abs($rowAmount) - $amount) > 0.005) continue;
            $id = trim((string)($row['TransactionItemID'] ?? ''));
            if ($id !== '' && !isset($knownPaymentRowIds[$id])) return true;
            if ($reference !== '' && str_contains((string)($row['Description'] ?? ''), $reference)) return true;
        }
    }
    return false;
}

/** Post the calculated cancellation fee once to the reservation room account. */
function postPortalCancellationFee(string $reservationId, string $roomAllocationId, array $allocation, float $fee): ?string {
    return postPortalPolicyFee($reservationId, $roomAllocationId, $fee, 'Cancellation Fees', 'The booking was not cancelled.');
}

/**
 * Post an amendment (transfer) fee once to the reservation room account.
 *
 * Same account as a cancellation fee, because that is where the park books
 * both, but its own description so the two are distinguishable on the guest's
 * account and neither is mistaken for the other when checking whether a fee has
 * already been posted.
 */
function postPortalAmendmentFee(string $reservationId, string $roomAllocationId, float $fee): ?string {
    return postPortalPolicyFee($reservationId, $roomAllocationId, $fee, 'Amendment Fee', 'The booking was not amended.');
}

/**
 * Post a policy fee to the room account, once.
 *
 * Idempotent on (room allocation, description, amount): an unreversed row that
 * already matches is returned rather than posted twice, so a retry after a
 * timeout cannot charge the guest the fee again.
 */
function postPortalPolicyFee(string $reservationId, string $roomAllocationId, float $fee, string $description, string $failureSuffix): ?string {
    global $PROPERTY_ID;
    $fee = round(max(0.0, $fee), 2);
    if ($fee <= 0) return null;
    $transactions = pmsTransactionItems($reservationId);
    $pattern = '/^' . preg_quote($description, '/') . 's?\b/i';
    foreach ($transactions as $row) {
        if ((string)($row['RoomAllocationID'] ?? '') !== $roomAllocationId || !preg_match($pattern, (string)($row['Description'] ?? '')) || !empty($row['IsReversed']) || !empty($row['ReversedTransactionItemID'])) continue;
        if (abs((float)($row['AmountInc'] ?? 0) - $fee) < 0.005) return (string)($row['TransactionItemID'] ?? '');
    }
    [$accountStatus, $accountRaw] = pmsCall('GET', 'Accounts/GetTransactionAccounts?propertyID=' . rawurlencode($PROPERTY_ID));
    $accounts = json_decode($accountRaw, true);
    $account = null;
    if ($accountStatus === 200 && is_array($accounts)) {
        foreach ($accounts as $candidate) if (is_array($candidate) && preg_match('/^Cancellation Fees?$/i', trim((string)($candidate['Name'] ?? '')))) { $account = $candidate; break; }
        if ($account === null) foreach ($accounts as $candidate) if (is_array($candidate) && strcasecmp(trim((string)($candidate['Name'] ?? '')), 'Sundry') === 0) { $account = $candidate; break; }
    }
    if (!is_array($account) || empty($account['TransactionAccountID'])) fail(409, 'GuestPoint has no Cancellation Fees or Sundry transaction account. ' . $failureSuffix);
    $now = new DateTimeImmutable('now', new DateTimeZone('Australia/Sydney'));
    $id = uuidV4();
    $transactions[] = [
        'TransactionItemID'=>$id,'PropertyID'=>$PROPERTY_ID,'TransactionAccountID'=>(string)$account['TransactionAccountID'],'TransactionType'=>(int)($account['TransactionType'] ?? 2),
        'PersonID'=>pmsAccountPersonId($roomAllocationId),'RoomAllocationID'=>$roomAllocationId,'AccountingDate'=>$now->format('Y-m-d\TH:i:s'),'PrintDate'=>$now->format('Y-m-d\T00:00:00'),
        'Quantity'=>1,'QuantityChild'=>0,'Tax'=>0,'TaxRate'=>(float)($account['TaxRate'] ?? 0.1),'AmountInc'=>$fee,'Description'=>$description,'IsNotAllowedToReverse'=>false,'UpdatedLocal'=>0,
    ];
    [$status, $raw] = pmsCall('POST', 'Accounts/SaveTransactionItemDetails?propertyID=' . rawurlencode($PROPERTY_ID) . '&addChangeLogs=true', $transactions);
    pmsForgetTransactions($reservationId);
    $saved = json_decode($raw, true);
    if ($status < 200 || $status >= 300 || !is_array($saved)) fail(502, 'GuestPoint rejected the ' . strtolower($description) . '. ' . $failureSuffix, $raw);
    return $id;
}

/** Best-effort compensation when a later GuestPoint step rejects the change. */
function reversePortalTransaction(string $reservationId, ?string $transactionId): bool {
    global $PROPERTY_ID;
    if ($transactionId === null || $transactionId === '') return true;
    $transactions = pmsTransactionItems($reservationId);
    $index = null;
    foreach ($transactions as $i => $row) if ((string)($row['TransactionItemID'] ?? '') === $transactionId) { $index = $i; break; }
    if ($index === null || !empty($transactions[$index]['IsReversed'])) return true;
    $original =& $transactions[$index];
    $original['IsReversed'] = true;
    $now = new DateTimeImmutable('now', new DateTimeZone('Australia/Sydney'));
    $transactions[] = [
        'TransactionItemID'=>uuidV4(),'PropertyID'=>$PROPERTY_ID,'TransactionAccountID'=>(string)$original['TransactionAccountID'],'TransactionType'=>(int)($original['TransactionType'] ?? 2),
        'PersonID'=>(string)($original['PersonID'] ?? ''),'RoomAllocationID'=>(string)($original['RoomAllocationID'] ?? ''),'AddonID'=>$original['AddonID'] ?? null,
        'AccountingDate'=>$now->format('Y-m-d\TH:i:s'),'PrintDate'=>$now->format('Y-m-d\T00:00:00'),'Quantity'=>(float)($original['Quantity'] ?? 1),'QuantityChild'=>(float)($original['QuantityChild'] ?? 0),
        'Tax'=>0,'TaxRate'=>(float)($original['TaxRate'] ?? 0.1),'AmountInc'=>-abs((float)($original['AmountInc'] ?? 0)),'Description'=>(string)($original['Description'] ?? 'Reversal'),
        'ReversedTransactionItemID'=>$transactionId,'IsNotAllowedToReverse'=>false,'UpdatedLocal'=>0,
    ];
    unset($original);
    [$status, $raw] = pmsCall('POST', 'Accounts/SaveTransactionItemDetails?propertyID=' . rawurlencode($PROPERTY_ID) . '&addChangeLogs=true', $transactions);
    pmsForgetTransactions($reservationId);
    return $status >= 200 && $status < 300 && is_array(json_decode($raw, true));
}

// Test-site amendment bridge. Phoenix itself performs the final optimistic
// concurrency check; we re-read the allocation after saving before claiming
// success to the guest.
if ($endpoint === 'portal/amend') {
    $input = is_array($decodedBody) ? $decodedBody : [];
    [$confNum, $tokenPayload, $identity] = requirePortalSession($input);
    $proposal = is_array($input['Proposal'] ?? null) ? $input['Proposal'] : [];
    if (($input['Acknowledged'] ?? false) !== true) fail(400, 'Accept the displayed conditions before making this change.');
    $reservationId = $identity['reservationId'];
    $roomAllocationId = (string)$identity['allocations'][0]['RoomAllocationId'];
    [$readStatus, $readRaw] = pmsCall('GET', 'Reservation/GetRoomAllocationDetail2sByReservation?reservationID=' . rawurlencode($reservationId));
    $details = json_decode($readRaw, true);
    if ($readStatus !== 200 || !is_array($details) || count($details) !== 1) fail(502, 'GuestPoint could not load the editable booking. Nothing was changed.');
    $allocation =& $details[0];
    if ((string)($allocation['RoomAllocationID'] ?? '') !== $roomAllocationId || (int)($allocation['Status'] ?? 0) !== 1) fail(409, 'This booking can no longer be amended online.');

    $adults = isset($proposal['adults']) ? (int)$proposal['adults'] : (int)($allocation['NumberAdults'] ?? 1);
    $children = isset($proposal['children']) ? (int)$proposal['children'] : (int)($allocation['NumberChildren'] ?? 0);
    $infants = isset($proposal['infants']) ? (int)$proposal['infants'] : (int)($allocation['NumberInfants'] ?? 0);
    $maxGuests = (int)($allocation['_RoomType']['MaxNumberOfGuests'] ?? 0);
    if ($adults < 1 || $children < 0 || $infants < 0 || $adults > 12 || $children > 12 || $infants > 12 || ($maxGuests > 0 && $adults + $children + $infants > $maxGuests)) {
        fail(409, $maxGuests > 0 ? 'This accommodation allows a maximum of ' . $maxGuests . ' guests.' : 'Those guest numbers are not valid.');
    }
    $currentArrival = substr((string)($allocation['ArrivalDate'] ?? ''), 0, 10);
    $currentNights = max(1, (int)($allocation['NumberOfNights'] ?? 1));
    $currentDeparture = (new DateTimeImmutable($currentArrival))->modify('+' . $currentNights . ' days')->format('Y-m-d');
    $allocation['NumberAdults'] = $adults; $allocation['NumberChildren'] = $children; $allocation['NumberInfants'] = $infants;

    $arrival = trim((string)($proposal['checkIn'] ?? ''));
    $departure = trim((string)($proposal['checkOut'] ?? ''));
    // The transfer fee is set by how close to the ORIGINAL arrival the guest is
    // making the change — that is the figure the conditions describe and the
    // portal showed them. Quote it before the allocation is mutated, or a move
    // from "next week" to "next month" would price itself as a month away and
    // come out free.
    $amendFeeDue = 0.0;
    if ($arrival !== '' || $departure !== '') {
        $preAmendQuote = phoenixCancellationQuote($reservationId, $roomAllocationId, $allocation);
        $amendFeeDue = is_array($preAmendQuote) ? round((float)($preAmendQuote['quote']['amendFee'] ?? 0), 2) : 0.0;
    }
    if ($arrival !== '' || $departure !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $arrival) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $departure)) fail(400, 'Choose valid arrival and departure dates.');
        try {
            $from = new DateTimeImmutable($arrival, new DateTimeZone('Australia/Sydney'));
            $to = new DateTimeImmutable($departure, new DateTimeZone('Australia/Sydney'));
            $nights = (int)$from->diff($to)->format('%r%a');
        } catch (Throwable $e) { $nights = 0; }
        if ($nights < 1 || $nights > 60) fail(409, 'The selected stay length is not valid.');
        $allocation['ArrivalDate'] = $arrival . 'T00:00:00';
        $allocation['NumberOfNights'] = $nights;
        // Keep existing unposted extras aligned with the amended stay.
        if (is_array($allocation['_Addons'] ?? null)) {
            foreach ($allocation['_Addons'] as $i => &$addon) {
                if (is_array($addon)) $addon['Date'] = $from->modify('+' . min($i, $nights - 1) . ' days')->format('Y-m-d') . 'T00:00:00';
            }
            unset($addon);
        }
    }
    $targetArrival = $arrival !== '' ? $arrival : $currentArrival;
    $targetDeparture = $departure !== '' ? $departure : $currentDeparture;
    $priceQuote = portalStayQuote($confNum, $tokenPayload, $targetArrival, $targetDeparture, $adults, $children, $targetArrival !== $currentArrival || $targetDeparture !== $currentDeparture);
    [$saveStatus, $saveRaw] = pmsCall('POST', 'Reservation/SaveRoomAllocationDetail2sWithVirtualRooms?propertyID=' . rawurlencode($PROPERTY_ID), $details);
    if ($saveStatus < 200 || $saveStatus >= 300) fail(502, 'GuestPoint rejected the amendment. Nothing was changed.', $saveRaw);
    savePortalNightlyCharges($roomAllocationId, $priceQuote['nightly']);
    // Phoenix recalculates booking/departure balances when the allocation is
    // saved, so re-read the current versions after saving charge rows and save
    // once more. This is the same sequence used by its reservation screen.
    [$recalcReadStatus, $recalcRaw] = pmsCall('GET', 'Reservation/GetRoomAllocationDetail2sByReservation?reservationID=' . rawurlencode($reservationId));
    $recalcDetails = json_decode($recalcRaw, true);
    if ($recalcReadStatus !== 200 || !is_array($recalcDetails)) fail(502, 'GuestPoint saved the dates but could not recalculate the balance. Please check the booking in GuestPoint.');
    [$recalcStatus, $recalcResponse] = pmsCall('POST', 'Reservation/SaveRoomAllocationDetail2sWithVirtualRooms?propertyID=' . rawurlencode($PROPERTY_ID), $recalcDetails);
    if ($recalcStatus < 200 || $recalcStatus >= 300) fail(502, 'GuestPoint saved the dates but rejected the revised balance. Please check the booking in GuestPoint.', $recalcResponse);
    [$verifyStatus, $verifyRaw] = pmsCall('GET', 'Reservation/GetRoomAllocation?roomAllocationID=' . rawurlencode($roomAllocationId));
    $verified = json_decode($verifyRaw, true);
    if ($verifyStatus !== 200 || !is_array($verified)
        || (int)($verified['NumberAdults'] ?? -1) !== $adults
        || (int)($verified['NumberChildren'] ?? -1) !== $children
        || (int)($verified['NumberInfants'] ?? -1) !== $infants
        || ($arrival !== '' && substr((string)($verified['ArrivalDate'] ?? ''), 0, 10) !== $arrival)) {
        fail(502, 'GuestPoint did not verify the amendment. Please check the booking in GuestPoint.');
    }

    // The amendment is saved. Now the transfer fee the conditions attach to it:
    // posted to the room account exactly like an extra, and charged to the
    // saved card when there is one. The amount is the figure this server
    // computes from the policy, never one supplied by the browser.
    $feeCharge = ['posted'=>0.0, 'charged'=>false, 'transactionId'=>null, 'payableAtReception'=>0.0, 'pending'=>false];
    // A transfer fee is for moving the dates. Changing the number of guests is
    // not a transfer and carries none, which is also the only case the portal
    // shows the guest a fee for.
    $datesChanged = $targetArrival !== $currentArrival || $targetDeparture !== $currentDeparture;
    $amendFee = $datesChanged ? $amendFeeDue : 0.0;
    if ($amendFee > 0) {
        $feeRowId = postPortalAmendmentFee($reservationId, $roomAllocationId, $amendFee);
        $feeCharge['posted'] = $amendFee;
        $card = pmsSavedCard($roomAllocationId);
        if (!empty($card['available'])) {
            $knownPaymentRowIds = portalPaymentRowIds(pmsTransactionItems($reservationId));
            $payment = chargePortalSavedCard(['ChargeAmount'=>$amendFee, 'card'=>$card, 'roomAllocationId'=>$roomAllocationId, 'allocation'=>$verified]);
            if ($payment['outcome'] === 'success') {
                $feeCharge['charged'] = true;
                $feeCharge['transactionId'] = $payment['transactionId'];
                $feeCharge['pending'] = !awaitPortalPaymentRow($reservationId, $knownPaymentRowIds, (float)$payment['amount'], (string)$payment['transactionId'], microtime(true) + PAYMENT_VERIFY_BUDGET);
            } else {
                // The dates have already moved in GuestPoint and unwinding that
                // is not something to attempt behind the guest's back. The fee
                // simply stays on the account for reception to take.
                $feeCharge['payableAtReception'] = $amendFee;
            }
        } else {
            $feeCharge['payableAtReception'] = $amendFee;
        }
        if ($feeRowId === null && $feeCharge['posted'] > 0) $feeCharge['posted'] = $amendFee;
    }

    send(200, ['Updated'=>true,'ConfNum'=>$confNum,'ArrivalDate'=>$verified['ArrivalDate'] ?? null,'NumberOfNights'=>$verified['NumberOfNights'] ?? null,'Adults'=>$adults,'Children'=>$children,'Infants'=>$infants,'AccommodationTotal'=>$priceQuote['total'],'DepartureBalance'=>$verified['DepartureBalance'] ?? null,
        'AmendmentFee'=>$feeCharge['posted'], 'AmendmentFeeCharged'=>$feeCharge['charged'], 'TransactionId'=>$feeCharge['transactionId'],
        'PayableAtReception'=>$feeCharge['payableAtReception'], 'PaymentPendingVerification'=>$feeCharge['pending']], ['Cache-Control'=>'no-store']);
}

if ($endpoint === 'portal/extras/quote' || $endpoint === 'portal/extras') {
    $input = is_array($decodedBody) ? $decodedBody : [];
    [$confNum, $tokenPayload, $identity] = requirePortalSession($input);
    $items = is_array($input['Items'] ?? null) ? $input['Items'] : [];
    // The portal sends an entry for every extra it displayed, including the
    // ones set to zero, because an extra left out of the list is treated as
    // removed. A fixed cap of 20 therefore broke the whole feature the moment
    // the catalogue outgrew it. The real bound is the eligible catalogue
    // itself, enforced in portalExtrasPlan; this is only an abuse guard.
    if (!$items || count($items) > MAX_EXTRAS_ITEMS) fail(400, 'Submit the extras shown for this booking.');
    if ($endpoint === 'portal/extras/quote') {
        $plan = portalExtrasPlan($confNum, $tokenPayload, $identity, $items);
        send(200, portalExtrasPublicQuote($plan), ['Cache-Control'=>'no-store']);
    }
    if (($input['Acknowledged'] ?? false) !== true) fail(400, 'Accept the displayed extra total before continuing.');
    $lockPath = $CACHE_DIR . '/portal-extra-' . hash('sha256', (string)$identity['reservationId']) . '.lock';
    $lock = @fopen($lockPath, 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        fail(409, 'Another extra or payment update is already being processed. Wait a moment and refresh the booking.');
    }
    $plan = portalExtrasPlan($confNum, $tokenPayload, $identity, $items);
    $payOnAccount = ($plan['PaymentMethod'] ?? 'none') === 'account';
    // The quote the guest accepted must be the deal they get. If a card
    // appeared or disappeared between quoting and saving, the amount is right
    // but the way it is paid is not what they agreed to.
    $acceptedMethod = trim((string)($input['PaymentMethod'] ?? ''));
    if ($acceptedMethod !== '' && $acceptedMethod !== (string)$plan['PaymentMethod']) {
        flock($lock, LOCK_UN); fclose($lock);
        fail(409, 'The way this extra would be paid for has changed since this page was opened. Nothing has been changed or charged. Please look up the booking again.');
    }
    $saved = savePortalExtraTransactions($plan);
    // The rows already on the account before we charge. GuestPoint posts its
    // own payment row, so a *new* one appearing is the proof we look for.
    $knownPaymentRowIds = portalPaymentRowIds(is_array($saved) ? $saved : []);

    // Never start a card charge we might not live long enough to record.
    // Shared hosting stops a long request without warning, and being stopped
    // between the gateway call and the room-account read is the one state
    // nobody can reconstruct afterwards.
    if (!$payOnAccount && round((float)($plan['ChargeAmount'] ?? 0), 2) > 0 && portalTimeLeft() < PAYMENT_VERIFY_BUDGET + 8.0) {
        $restored = savePortalExtraTransactions(portalExtrasRestorePlan($plan), false) !== null;
        flock($lock, LOCK_UN); fclose($lock);
        fail(503, $restored
            ? 'GuestPoint is responding too slowly to complete this safely. Nothing was changed and no payment was taken — please try again in a few minutes.'
            : 'GuestPoint is responding too slowly to complete this safely. No payment was taken, but please call reception on 02 6456 2224 to confirm the booking extras.');
    }

    // No usable card: the extra is on the room account and that is the whole
    // transaction. Nothing is charged, nothing is pending, and the guest is
    // told the amount is payable at reception.
    $payment = $payOnAccount
        ? ['charged'=>false,'outcome'=>'on-account','amount'=>0.0,'transactionId'=>null,'detail'=>null]
        : chargePortalSavedCard($plan);

    // The extras are on the account by now. If the charge definitively did not
    // happen, put the account back before answering — otherwise the guest keeps
    // the extras for nothing, and because posted extras then count as
    // "current", a retry prices the delta at zero and never asks for the money.
    if (in_array($payment['outcome'], ['blocked', 'declined'], true)) {
        $restored = savePortalExtraTransactions(portalExtrasRestorePlan($plan), false) !== null;
        flock($lock, LOCK_UN); fclose($lock);
        $reason = trim((string)($payment['detail'] ?? 'The card was not charged.'));
        fail($payment['outcome'] === 'declined' ? 402 : 409, $restored
            ? $reason . ' Nothing was changed on your booking and no payment was taken.'
            : $reason . ' The extras could not be rolled back automatically — please call reception on 02 6456 2224 before trying again.',
            $payment['raw'] ?? null);
    }
    // Unknown outcome: the card may or may not have been charged, so unwinding
    // would risk taking back something already paid for. Leave it and say so.
    if ($payment['outcome'] === 'indeterminate') {
        flock($lock, LOCK_UN); fclose($lock);
        fail(502, 'GuestPoint did not confirm the payment, and it may still have gone through. Do not retry — please call reception on 02 6456 2224 so the booking account can be checked.', $payment['raw'] ?? null);
    }

    $paymentPending = false;
    if (!empty($payment['charged'])) {
        $paymentPending = !awaitPortalPaymentRow(
            (string)$plan['reservationId'], $knownPaymentRowIds,
            (float)$payment['amount'], (string)$payment['transactionId'],
            microtime(true) + PAYMENT_VERIFY_BUDGET
        );
    }

    $fresh = portalCurrentExtras((string)$plan['roomAllocationId'], (string)$plan['reservationId']);
    $freshById = [];
    foreach ($fresh as $row) if (is_array($row) && !empty($row['Id'])) $freshById[strtolower((string)$row['Id'])] = round((float)($row['Total'] ?? 0), 2);
    $extrasPending = false;
    foreach ($plan['items'] as $desired) {
        if (abs(($freshById[strtolower((string)$desired['id'])] ?? 0.0) - (float)$desired['total']) > 0.005) { $extrasPending = true; break; }
    }
    // Once the card has been charged, an unverified read is a reporting delay,
    // not a failure. Returning an error here is what made a completed payment
    // look to the guest like nothing had happened.
    if ($extrasPending && empty($payment['charged'])) {
        flock($lock, LOCK_UN); fclose($lock);
        fail(502, 'GuestPoint processed the request but the final extra total could not be verified. Check the booking account before retrying.');
    }

    flock($lock, LOCK_UN); fclose($lock);
    send(200, [
        'Updated'=>true, 'ConfNum'=>$confNum,
        'ChargeAmount'=>$payment['amount'], 'CreditAmount'=>$plan['CreditAmount'],
        'Charged'=>$payment['charged'], 'TransactionId'=>$payment['transactionId'],
        'PaymentMethod'=>$plan['PaymentMethod'],
        'PayableAtReception'=>$payOnAccount ? $plan['ChargeAmount'] : 0.0,
        'PaymentPendingVerification'=>$paymentPending,
        'ExtrasPendingVerification'=>$extrasPending,
        'CurrentExtras'=>$fresh,
    ], ['Cache-Control'=>'no-store']);
}


// All portal mutations are made through this authenticated wrapper. It accepts
// only fields documented by PartialUpdateInput and never trusts the booking
// reference alone. An optional short email is sent only after GuestPoint saves.
if ($endpoint === 'portal/update') {
    $input = is_array($decodedBody) ? $decodedBody : [];
    $confNum = strtoupper(trim((string)($input['ConfNum'] ?? '')));
    $token = (string)($input['PortalToken'] ?? '');
    $changes = $input['Changes'] ?? null;
    $tokenPayload = portalTokenPayload($token, $confNum);
    if (!preg_match('/^[A-Z0-9._-]{3,64}$/', $confNum) || $tokenPayload === null || !is_array($changes)) {
        fail(403, 'Your booking session has expired. Please look up the booking again.');
    }
    $allowed = ['EstimatedArrival', 'AppendExtraInfo', 'Guests', 'RoomStays', 'ProfileFields'];
    foreach (array_keys($changes) as $key) {
        if (!in_array($key, $allowed, true)) fail(400, 'That booking detail cannot be changed online.');
    }
    if (isset($changes['AppendExtraInfo']) && (!is_string($changes['AppendExtraInfo']) || strlen($changes['AppendExtraInfo']) > 1800)) {
        fail(400, 'The request is too long.');
    }
    if (isset($changes['EstimatedArrival']) && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$changes['EstimatedArrival'])) {
        fail(400, 'Enter the estimated arrival time as HH:MM.');
    }
    if (isset($changes['Guests']) && (!is_array($changes['Guests']) || count($changes['Guests']) > 20)) {
        fail(400, 'Guest details are invalid.');
    }

    // ETA, Car Rego and Dimentions are Phoenix allocation/profile fields. Save
    // them through the same full-allocation endpoint used by the PMS so direct
    // and Booking Engine reservations behave identically.
    $changeKeys = array_keys($changes);
    $pmsTravelUpdate = $PMS_PRIVATE_WRITES && !array_diff($changeKeys, ['EstimatedArrival','ProfileFields']) && (isset($changes['EstimatedArrival']) || isset($changes['ProfileFields']));
    if ($pmsTravelUpdate) {
        $identity = portalPmsIdentity($confNum, $tokenPayload);
        if (($identity['reservationId'] ?? '') === '' || count($identity['allocations'] ?? []) !== 1) fail(409, 'This booking cannot update vehicle details online.');
        $reservationId = (string)$identity['reservationId'];
        $roomAllocationId = (string)$identity['allocations'][0]['RoomAllocationId'];
        [$detailStatus, $detailRaw] = pmsCall('GET', 'Reservation/GetRoomAllocationDetail?roomAllocationID=' . rawurlencode($roomAllocationId));
        $allocation = json_decode($detailRaw, true);
        if ($detailStatus !== 200 || !is_array($allocation) || (int)($allocation['Status'] ?? 0) !== 1) fail(409, 'GuestPoint could not load the editable vehicle details. Nothing was changed.');
        if (isset($changes['EstimatedArrival'])) {
            $eta = DateTimeImmutable::createFromFormat('H:i', (string)$changes['EstimatedArrival']);
            if (!$eta) fail(400, 'Enter a valid estimated arrival time.');
            $allocation['Eta'] = $eta->format('h:i A');
        }
        if (isset($changes['ProfileFields'])) {
            if (!is_array($changes['ProfileFields']) || count($changes['ProfileFields']) > 12) fail(400, 'Vehicle profile details are invalid.');
            $profileData = pmsReservationProfiles($reservationId);
            $allowedDefinitions = [];
            foreach ($profileData['definitions'] as $definition) $allowedDefinitions[strtolower((string)$definition['Id'])] = $definition;
            $profiles = is_array($allocation['_Profiles'] ?? null) ? $allocation['_Profiles'] : $profileData['values'];
            foreach ($changes['ProfileFields'] as $field) {
                if (!is_array($field)) fail(400, 'Vehicle profile details are invalid.');
                $fieldId = strtolower(trim((string)($field['Id'] ?? $field['ExternalId'] ?? '')));
                if (!isset($allowedDefinitions[$fieldId])) fail(400, 'Only Car Rego and vehicle dimensions can be changed here.');
                $value = trim((string)($field['Value'] ?? ''));
                if (strlen($value) > 120) fail(400, 'A vehicle detail is too long.');
                $updated = false;
                foreach ($profiles as &$profile) {
                    if (is_array($profile) && strtolower((string)($profile['ProfileFieldID'] ?? '')) === $fieldId) { $profile['Value'] = $value; $updated = true; break; }
                }
                unset($profile);
                if (!$updated) $profiles[] = ['ProfileID'=>uuidV4(),'ReservationID'=>$reservationId,'ProfileFieldID'=>$allowedDefinitions[$fieldId]['Id'],'Value'=>$value,'UpdatedLocal'=>0];
            }
            $allocation['_Profiles'] = $profiles;
        }
        [$saveStatus, $saveRaw] = pmsCall('POST', 'Reservation/SaveRoomAllocationEditWithVirtualRooms?propertyID=' . rawurlencode($PROPERTY_ID) . '&addChangeLogs=true', $allocation);
        pmsForgetProfiles($reservationId);
        if ($saveStatus < 200 || $saveStatus >= 300) fail(502, 'GuestPoint rejected the arrival or vehicle details. Nothing was changed.', $saveRaw);
        // The ETA and the profile values ride on the same save, so a profile
        // that did not stick still leaves the ETA changed. Saying "nothing was
        // changed" here was simply untrue, and sent guests round again to
        // re-enter an arrival time that had already been accepted.
        $etaSaved = isset($changes['EstimatedArrival']);
        $verifiedProfiles = pmsReservationProfiles($reservationId);
        $unverified = [];
        foreach ($changes['ProfileFields'] ?? [] as $field) {
            $fieldId = strtolower((string)($field['Id'] ?? $field['ExternalId'] ?? ''));
            $match = null;
            foreach ($verifiedProfiles['definitions'] as $candidate) if (strtolower((string)$candidate['Id']) === $fieldId) { $match = $candidate; break; }
            if (!is_array($match) || (string)$match['Value'] !== trim((string)($field['Value'] ?? ''))) {
                $unverified[] = is_array($match) ? (string)$match['Name'] : $fieldId;
            }
        }
        if ($unverified) {
            fail(502, ($etaSaved ? 'Your arrival time was saved. ' : '')
                . 'GuestPoint did not store ' . implode(' or ', $unverified) . ', so the vehicle details are unchanged.'
                . ' Please give them to reception on arrival.');
        }
        send(200, ['Updated'=>true,'NotificationSent'=>null,'ProfileFields'=>$verifiedProfiles['definitions']], ['Cache-Control'=>'no-store']);
    }

    $notificationText = '';
    if (isset($changes['AppendExtraInfo'])) {
        $notificationText = trim(strip_tags((string)$changes['AppendExtraInfo']));
        unset($changes['AppendExtraInfo']);
        if ($notificationText === '') fail(400, 'Enter a request before saving.');
        [$lookupStatus, $lookupPayload] = loadManagedReservation(
            $confNum,
            (string)($tokenPayload['sn'] ?? ''),
            (string)($tokenPayload['em'] ?? ''),
            false
        );
        if ($lookupStatus < 200 || $lookupStatus >= 300 || !is_array($lookupPayload)) {
            fail(502, 'The booking could not be refreshed. Nothing was changed.');
        }
        $currentReservation = managedReservationFromPayload($lookupPayload);
        if ((int)($currentReservation['ID'] ?? 0) !== (int)$tokenPayload['rid']) {
            fail(403, 'The booking no longer matches this session. Please look it up again.');
        }
        if (!in_array(strtolower((string)($currentReservation['Status'] ?? '')), ['booked', 'modified'], true)) {
            fail(409, 'This booking can no longer be changed online.');
        }
        $existing = trim((string)($currentReservation['ExtraInfo'] ?? ''));
        $stamp = (new DateTimeImmutable('now', new DateTimeZone('Australia/Sydney')))->format('d/m/Y H:i');
        $entry = 'Guest request (' . $stamp . '): ' . $notificationText;
        $changes['ExtraInfo'] = substr(($existing !== '' ? $existing . "\n\n" : '') . $entry, 0, 4000);
    }
    if (!$changes) fail(400, 'No supported booking changes were supplied.');

    $updateUrl = $UPSTREAM . '/properties/' . rawurlencode($PROPERTY_ID) . '/reservations/manage/' . rawurlencode($confNum);
    $updateBody = json_encode($changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    [$updateStatus, $updateResponse, $updateError] = upstreamCall('PATCH', $updateUrl, $API_KEY, $updateBody);
    if ($updateStatus === 0) fail(502, 'The booking system is not responding. Please try again shortly.', $updateError);
    if ($updateStatus < 200 || $updateStatus >= 300) {
        fail($updateStatus >= 400 && $updateStatus < 500 ? 409 : 502, 'GuestPoint rejected the update. Nothing was changed.');
    }
    if (!guestPointConfirmed($updateResponse)) {
        fail(502, 'GuestPoint did not confirm the update. Nothing was changed.');
    }

    $notificationSent = null;
    if (!empty($input['Notify']) && $notificationText !== '') {
        $notificationSent = false;
        if (filter_var($NOTIFICATION_EMAIL, FILTER_VALIDATE_EMAIL)) {
            $subject = 'Guest request - ' . $confNum;
            $message = "Guest request\nBooking: " . $confNum . "\n\n" . $notificationText;
            $headers = "From: Kosipark portal <no-reply@kosipark.com.au>\r\nContent-Type: text/plain; charset=UTF-8";
            $notificationSent = @mail($NOTIFICATION_EMAIL, $subject, $message, $headers);
        }
    }
    send(200, [
        'Updated' => true,
        'NotificationSent' => $notificationSent,
        'GuestPoint' => json_decode($updateResponse, true),
    ], ['Cache-Control' => 'no-store']);
}

// Cancellation is intentionally available only through the authenticated
// portal wrapper. A reservation id and confirmation number on their own are
// not proof that the caller owns the booking.
if ($endpoint === 'portal/cancel') {
    $input = is_array($decodedBody) ? $decodedBody : [];
    $confNum = strtoupper(trim((string)($input['ConfNum'] ?? '')));
    $token = (string)($input['PortalToken'] ?? '');
    $acknowledged = ($input['Acknowledged'] ?? false) === true;
    $tokenPayload = portalTokenPayload($token, $confNum);
    if (!preg_match('/^[A-Z0-9._-]{3,64}$/', $confNum)
        || $tokenPayload === null
        || !$acknowledged) {
        fail(403, 'Your booking session has expired or the cancellation was not accepted. Please look up the booking again.');
    }
    $reservationId = trim((string)$tokenPayload['rid']);
    $quote = is_array($tokenPayload['cq'] ?? null) ? $tokenPayload['cq'] : null;
    if ($quote === null || !isset($quote['fee'], $quote['refund'], $quote['quotedAt'])) {
        fail(403, 'The cancellation quote is missing or expired. Please look up the booking again.');
    }

    // Phoenix-only reservations follow the same sequence as the PMS UI:
    // validate the fresh allocation, recalculate the three account values,
    // then save and re-read it. They have no Booking Engine manage record, so
    // this branch must run before the Booking Engine refresh below.
    if ($PMS_PRIVATE_WRITES) {
        $identity = portalPmsIdentity($confNum, $tokenPayload);
        if (($identity['reservationId'] ?? '') === '' || count($identity['allocations'] ?? []) !== 1) fail(409, 'This booking cannot be cancelled automatically.');
        $roomAllocationId = (string)$identity['allocations'][0]['RoomAllocationId'];
        [$allocationStatus, $allocationRaw] = pmsCall('GET', 'Reservation/GetRoomAllocation?roomAllocationID=' . rawurlencode($roomAllocationId));
        $allocation = json_decode($allocationRaw, true);
        if ($allocationStatus !== 200 || !is_array($allocation) || (int)($allocation['Status'] ?? 0) !== 1) fail(409, 'This booking can no longer be cancelled.');

        [$validationStatus, $validationRaw, $validationError] = pmsCall('POST', 'Reservation/ValidateCancellation', $allocation);
        if ($validationStatus < 200 || $validationStatus >= 300 || json_decode($validationRaw, true) !== true) {
            fail(409, 'GuestPoint did not allow this booking to be cancelled. Nothing was changed.', $validationRaw ?: $validationError);
        }

        $emptyObject = (object)[];
        $phoenix = phoenixCancellationQuote((string)$identity['reservationId'], $roomAllocationId, $allocation);
        if ($phoenix === null) fail(502, 'GuestPoint could not calculate the cancellation values. Nothing was changed.');
        $bookingValue = $phoenix['bookingValue'];
        $accountBalance = $phoenix['accountBalance'];
        $departureValue = $phoenix['departureValue'];
        $fresh = $phoenix['quote'];

        // The guest ticked a box against specific figures, and those figures
        // are inside the signed token. If the recalculation no longer matches
        // them, the honest move is to refuse and re-quote — not to cancel the
        // booking and post a fee nobody agreed to.
        if (!portalQuotesAgree($quote, $fresh)) {
            $money = fn($value) => '$' . number_format((float)$value, 2);
            fail(409, 'The cancellation cost has changed since this page was opened.'
                . ' You accepted a fee of ' . $money($quote['fee'] ?? 0) . ' with ' . $money($quote['refund'] ?? 0) . ' refunded;'
                . ' it is now a fee of ' . $money($fresh['fee'] ?? 0) . ' with ' . $money($fresh['refund'] ?? 0) . ' refunded.'
                . ' Nothing has been changed or charged. Please look up the booking again to see the current figures.');
        }
        $quote = $fresh;

        $cancellationFeeTransactionId = postPortalCancellationFee((string)$identity['reservationId'], $roomAllocationId, $allocation, (float)($quote['fee'] ?? 0));

        $allocation['CancellationBookingValue'] = round((float)($quote['fee'] ?? 0), 2);
        $allocation['CancellationReason'] = 'Cancelled by guest through the online portal. Policy fee: $' . number_format((float)($quote['fee'] ?? 0), 2) . '; estimated refund: $' . number_format((float)($quote['refund'] ?? 0), 2) . '.';
        $allocation['CancelledAppuserID'] = (string)($allocation['UpdatedAppuserID'] ?? $allocation['CreatedAppuserID'] ?? '');
        $allocation['CancelledDate'] = (new DateTimeImmutable('today', new DateTimeZone('Australia/Sydney')))->format('Y-m-d');
        $allocation['IsDeleted'] = false;
        $allocation['Status'] = 4;
        [$cancelStatus, $cancelResponse, $cancelError] = pmsCall('POST', 'Reservation/SaveRoomAllocationWithVirtualRooms?propertyID=' . rawurlencode($PROPERTY_ID) . '&addChangeLogs=true', $allocation);
        if ($cancelStatus < 200 || $cancelStatus >= 300) {
            $reversed = reversePortalTransaction((string)$identity['reservationId'], $cancellationFeeTransactionId);
            fail(502, $reversed ? 'GuestPoint rejected the cancellation. The cancellation fee was reversed and the booking remains active.' : 'GuestPoint rejected the cancellation. Check the room account before retrying.', $cancelResponse ?: $cancelError);
        }
        [$verifyStatus, $verifyRaw] = pmsCall('GET', 'Reservation/GetRoomAllocation?roomAllocationID=' . rawurlencode($roomAllocationId));
        $verified = json_decode($verifyRaw, true);
        if ($verifyStatus !== 200 || !is_array($verified) || (int)($verified['Status'] ?? 0) !== 4) {
            $reversed = reversePortalTransaction((string)$identity['reservationId'], $cancellationFeeTransactionId);
            fail(502, $reversed ? 'GuestPoint did not verify the cancellation. The cancellation fee was reversed.' : 'GuestPoint did not verify the cancellation. Check the room account before retrying.');
        }
        send(200, ['Cancelled'=>true,'Message'=>'GuestPoint PMS has cancelled the reservation.','PolicyFee'=>$quote['fee'],'EstimatedRefund'=>$quote['refund'],'CancellationFeeTransactionId'=>$cancellationFeeTransactionId,'BookingValue'=>$bookingValue,'AccountBalance'=>$accountBalance,'DepartureValue'=>$departureValue,'GuestPoint'=>$verified], ['Cache-Control'=>'no-store']);
    }

    // Re-read the booking immediately before the destructive action. A valid
    // portal token proves access to the booking, but it must never override a
    // later status/permission change in GuestPoint. GuestPoint's own portal
    // currently marks cancellation and its required financial steps as
    // unavailable for this property.
    [$freshStatus, $freshPayload] = loadManagedReservation(
        $confNum,
        (string)($tokenPayload['sn'] ?? ''),
        (string)($tokenPayload['em'] ?? ''),
        false
    );
    if ($freshStatus < 200 || $freshStatus >= 300 || !is_array($freshPayload)) {
        fail(502, 'The booking could not be refreshed. Your booking has not been cancelled.');
    }
    if (isset($freshPayload['data']) && is_array($freshPayload['data'])) $freshRoot = $freshPayload['data'];
    elseif (isset($freshPayload['Data']) && is_array($freshPayload['Data'])) $freshRoot = $freshPayload['Data'];
    else $freshRoot = $freshPayload;
    $freshReservation = is_array($freshRoot['Reservation'] ?? null) ? $freshRoot['Reservation'] : [];
    $freshLogin = is_array($freshRoot['Login'] ?? null) ? $freshRoot['Login'] : [];
    if (trim((string)($freshReservation['ID'] ?? '')) !== $reservationId
        || !in_array(strtolower((string)($freshReservation['Status'] ?? '')), ['booked', 'modified'], true)) {
        fail(409, 'This booking can no longer be cancelled online. Nothing was changed.');
    }
    if (!$PMS_PRIVATE_WRITES && ($freshLogin['Cancel'] ?? false) !== true) {
        fail(409, 'GuestPoint has not enabled online cancellation for this booking. Nothing was changed.');
    }
    $freshQuote = cancellationQuote($freshReservation);
    if (!$PMS_PRIVATE_WRITES && ((float)($freshQuote['fee'] ?? 0) > 0
        || (float)($freshQuote['refund'] ?? 0) > 0
        || (float)($freshQuote['amountDue'] ?? 0) > 0)) {
        fail(409, 'GuestPoint has not supplied the payment and refund operations needed to settle this cancellation automatically. Nothing was changed.');
    }
    $quote = $freshQuote;

    $cancelUrl = $UPSTREAM . '/properties/' . rawurlencode($PROPERTY_ID) . '/reservations/' . rawurlencode((string)$reservationId);
    $cancelBody = json_encode([
        'PropertyId' => $PROPERTY_ID,
        'ConfNum' => $confNum,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    [$cancelStatus, $cancelResponse, $cancelError] = upstreamCall('DELETE', $cancelUrl, $API_KEY, $cancelBody);
    if ($cancelStatus === 0) fail(502, 'The booking system is not responding. Your booking has not been cancelled.', $cancelError);
    if ($cancelStatus < 200 || $cancelStatus >= 300) {
        fail($cancelStatus >= 400 && $cancelStatus < 500 ? 409 : 502, 'GuestPoint did not accept the cancellation. Your booking has not been cancelled.');
    }
    if (!guestPointConfirmed($cancelResponse)) {
        fail(502, 'GuestPoint did not confirm the cancellation. Your booking has not been cancelled.');
    }

    $audit = [
        'time' => gmdate('c'),
        'event' => 'portal.booking_cancelled',
        'confirmation' => $confNum,
        'reservation_id' => $reservationId,
        'policy_fee_shown' => max(0, (float)$quote['fee']),
        'estimated_refund_shown' => max(0, (float)$quote['refund']),
        'quote_time' => (string)$quote['quotedAt'],
        'ip_hash' => hash('sha256', clientIp()),
    ];
    @file_put_contents($CACHE_DIR . '/portal-audit.jsonl', json_encode($audit, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

    send(200, [
        'Cancelled' => true,
        'GuestPoint' => json_decode($cancelResponse, true),
        'Message' => 'GuestPoint has cancelled the reservation.',
    ], ['Cache-Control' => 'no-store']);
}

// Only forward the query string, never arbitrary client headers.
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = $UPSTREAM . '/properties/' . rawurlencode($PROPERTY_ID) . '/' . $endpoint
        . ($query !== '' ? '?' . $query : '');

// ----------------------------------------------------------------- cache ----

$cacheFile = null;
if ($ttl > 0) {
    $cacheFile = $CACHE_DIR . '/c_' . hash('sha256', $method . ' ' . $target . ' ' . (string)$body) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $age = time() - filemtime($cacheFile);
        send(200, (string)file_get_contents($cacheFile), [
            'X-Proxy-Cache' => 'HIT',
            'Cache-Control' => 'private, max-age=' . max(0, $ttl - $age),
        ]);
    }
}

// -------------------------------------------------------------- upstream ----

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_TIMEOUT        => UPSTREAM_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER     => array_filter([
        'X-API-KEY: ' . $API_KEY,
        'Accept: application/json',
        $body !== null ? 'Content-Type: application/json' : null,
    ]),
]);
if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

$response = curl_exec($ch);
$status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false || $status === 0) {
    fail(502, 'The booking system is not responding. Please try again shortly, or call 02 6456 2224.', $curlErr);
}

// Names come from Phoenix, not from the web catalogue, so the guest sees the
// same wording as reception and the room account. Done before caching so the
// cached copy is already correct: the add-on master is property configuration,
// identical for every guest.
if ($status === 200 && $endpoint === 'extras') $response = overlayAddonNames((string)$response);

// Only 200s are worth caching. Everything else goes straight back.
if ($status === 200 && $cacheFile !== null) {
    $tmp = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $response, LOCK_EX) !== false) {
        @rename($tmp, $cacheFile);   // atomic, so a concurrent read never sees a half file
    } else {
        @unlink($tmp);
    }
    // Opportunistic sweep, ~1 request in 200, of anything older than a day.
    if (random_int(1, 200) === 1) {
        foreach (glob($CACHE_DIR . '/{c_,rl_}*', GLOB_BRACE) ?: [] as $old) {
            if (is_file($old) && time() - filemtime($old) > 86400) @unlink($old);
        }
    }
}

send($status, (string)$response, [
    'X-Proxy-Cache' => $ttl > 0 ? 'MISS' : 'BYPASS',
    'Cache-Control' => $ttl > 0 ? 'private, max-age=' . $ttl : 'no-store',
]);
