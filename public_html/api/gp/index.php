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
$OTP_TTL = max(300, min(900, (int)($config['otp_ttl'] ?? 600)));
$OTP_MAX_ATTEMPTS = max(3, min(8, (int)($config['otp_max_attempts'] ?? 5)));
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

function issuePortalToken(string $confNum, int $reservationId, string $surname, string $email, array $quote): string {
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
        || (int)($payload['rid'] ?? 0) < 1) return null;
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
        $oneNight += max(0.0, gpNumber($stay['RoomTotal'] ?? null) ?? 0.0) / $nights;
        foreach (is_array($stay['RateDetails'] ?? null) ? $stay['RateDetails'] : [] as $rate) {
            if (is_array($rate) && is_array($rate['CancelRule'] ?? null)) $rules[] = $rate['CancelRule'];
        }
    }

    try {
        $today = new DateTimeImmutable('today', new DateTimeZone('Australia/Sydney'));
        $arrivalDate = new DateTimeImmutable($arrival, new DateTimeZone('Australia/Sydney'));
        $days = (int)$today->diff($arrivalDate)->format('%r%a');
    } catch (Throwable $e) { $days = -1; }

    $fee = 0.0;
    $detail = '';
    $nonRefundable = null;
    foreach ($rules as $rule) {
        if (strtolower(trim((string)($rule['CancelRule'] ?? ''))) === 'none') { $nonRefundable = $rule; break; }
    }
    if ($nonRefundable !== null) {
        $fee = $total;
        $detail = trim((string)($nonRefundable['CancelRuleText'] ?? '')) ?: 'This rate is non-refundable.';
    } elseif ($rules) {
        $free = $rules[0];
        $periods = max(0, (int)($free['CancelRuleNumPeriods'] ?? 0));
        $period = strtolower(trim((string)($free['CancelRulePeriod'] ?? 'day')));
        $cutoffDays = $period === 'week' ? $periods * 7 : ($period === 'hour' ? (int)ceil($periods / 24) : $periods);
        $fee = $days >= $cutoffDays ? 0.0 : $total;
        $detail = trim((string)($free['CancelRuleText'] ?? '')) ?: 'The cancellation conditions attached to your booked rate apply.';
    } else {
        $fee = $days >= 15 ? max($total * 0.1, $oneNight)
            : ($days >= 8 ? max($total * 0.5, $oneNight) : $total);
        $detail = $days >= 15
            ? "15 or more days before arrival, the greater of 10% of the tariff or one night's tariff is retained."
            : ($days >= 8
                ? "8 to 14 days before arrival, the greater of 50% of the tariff or one night's tariff is retained."
                : 'Within 7 days of arrival, the booking is non-refundable.');
    }
    $fee = min($total, max(0.0, $fee));
    return [
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
function pmsCall(string $method, string $path, ?array $payload = null): array {
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

function otpDirectory(): string {
    global $CACHE_DIR;
    $dir = $CACHE_DIR . '/portal-otp';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function otpChallengePath(string $id): string {
    return otpDirectory() . '/challenge_' . hash('sha256', $id) . '.json';
}

/** Limit code requests by IP and entered booking identity. */
function allowOtpRequest(string $identity): bool {
    $path = otpDirectory() . '/request_' . hash('sha256', clientIp() . '|' . $identity) . '.json';
    $now = time();
    $record = ['window' => $now, 'last' => 0, 'count' => 0];
    if (is_file($path)) {
        $loaded = json_decode((string)@file_get_contents($path), true);
        if (is_array($loaded)) $record = array_merge($record, $loaded);
    }
    if ($now - (int)$record['window'] >= 900) $record = ['window' => $now, 'last' => 0, 'count' => 0];
    if ($now - (int)$record['last'] < 60 || (int)$record['count'] >= 4) return false;
    $record['last'] = $now;
    $record['count'] = (int)$record['count'] + 1;
    return @file_put_contents($path, json_encode($record), LOCK_EX) !== false;
}

/** Return the Core reservation only when reference, property and surname match. */
function resolvePortalCoreReservation(string $confNum, string $surname): ?array {
    global $CORE_UPSTREAM, $CORE_KEY, $PROPERTY_ID;
    if ($CORE_UPSTREAM === '' || $CORE_KEY === '') return null;
    $search = $CORE_UPSTREAM . '/v1/reservations?' . http_build_query([
        'bookingReference' => $confNum,
        'lastName' => $surname,
    ], '', '&', PHP_QUERY_RFC3986);
    [$status, $response] = upstreamCall('GET', $search, $CORE_KEY);
    if ($status !== 200) return null;
    $payload = json_decode($response, true);
    $rows = [];
    if (is_array($payload)) {
        if (isset($payload['ReservationNumber'])) $rows = [$payload];
        else $rows = $payload['Data'] ?? $payload['data'] ?? [];
    }
    if (!is_array($rows)) return null;
    foreach ($rows as $reservation) {
        if (!is_array($reservation)) continue;
        $rowProperty = strtolower(trim((string)($reservation['PropertyId'] ?? '')));
        // GuestPoint accepts either its reservation number or the booking
        // engine/channel reference in the bookingReference search parameter.
        if (!hash_equals(strtolower($PROPERTY_ID), $rowProperty)) continue;
        foreach (($reservation['Allocations'] ?? []) as $allocation) {
            if (!is_array($allocation) || empty($allocation['IsPrimary'])) continue;
            $rowSurname = strtolower(trim((string)($allocation['GuestLastName'] ?? '')));
            if (!hash_equals(strtolower($surname), $rowSurname)) continue;
            return $reservation;
        }
    }
    return null;
}

/** Return the stored primary email only when the reference and surname match. */
function resolvePortalEmail(string $confNum, string $surname): string {
    $reservation = resolvePortalCoreReservation($confNum, $surname);
    if (!is_array($reservation)) return '';
    foreach (($reservation['Allocations'] ?? []) as $allocation) {
        if (!is_array($allocation) || empty($allocation['IsPrimary'])) continue;
        $email = trim((string)($allocation['GuestEmail'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
    }
    return '';
}

/** Use the verified identity to fetch GuestPoint's complete management view. */
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
    $reservationId = (int)($reservation['ID'] ?? 0);
    if ($reservationId < 1) return [502, null, 'GuestPoint returned a reservation without an id.'];
    // The Booking Engine management view can lag behind a Phoenix PMS edit.
    // On the test portal, overlay authoritative dates, occupancy and current
    // room balance from Core/Phoenix so a refresh never appears to undo a
    // change that the PMS has already accepted.
    global $PMS_PRIVATE_WRITES;
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
        }
    }
    $quote = cancellationQuote($reservation);
    $root['PortalToken'] = issuePortalToken($confNum, $reservationId, $surname, $email, $quote);
    $root['CancellationQuote'] = $quote;
    if ($PMS_PRIVATE_WRITES) {
        $root['PortalCapabilities'] = ['Amend'=>true, 'Extras'=>true, 'Cancel'=>true];
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
    $email = $validInput ? resolvePortalEmail($confNum, $surname) : '';
    if ($email === '') fail(403, 'We could not verify those booking details. Check them and try again.');
    [$status, $payload, $detail] = loadManagedReservation($confNum, $surname, $email);
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
    global $UPSTREAM, $PROPERTY_ID, $API_KEY;
    [$manageStatus, $managePayload] = loadManagedReservation(
        $confNum,
        (string)($tokenPayload['sn'] ?? ''),
        (string)($tokenPayload['em'] ?? ''),
        false
    );
    if ($manageStatus < 200 || $manageStatus >= 300 || !is_array($managePayload)) {
        fail(502, 'GuestPoint could not load the original room and rate. Nothing was changed.');
    }
    $root = managedRoot($managePayload);
    $reservation = is_array($root['Reservation'] ?? null) ? $root['Reservation'] : [];
    $stays = array_values(array_filter($reservation['RoomStays'] ?? [], fn($s) => is_array($s) && empty($s['IsCancelled'])));
    if (count($stays) !== 1) fail(409, 'This portal can only re-price a booking with one accommodation. Nothing was changed.');
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
    if ($manageStatus < 200 || $manageStatus >= 300 || !is_array($managePayload)) fail(502, 'GuestPoint could not load the booking extras. Nothing was added.');
    $root = managedRoot($managePayload);
    $stays = $root['Reservation']['RoomStays'] ?? [];
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
    send(200, ['Updated'=>true,'ConfNum'=>$confNum,'ArrivalDate'=>$verified['ArrivalDate'] ?? null,'NumberOfNights'=>$verified['NumberOfNights'] ?? null,'Adults'=>$adults,'Children'=>$children,'Infants'=>$infants,'AccommodationTotal'=>$priceQuote['total'],'DepartureBalance'=>$verified['DepartureBalance'] ?? null], ['Cache-Control'=>'no-store']);
}

if ($endpoint === 'portal/extras') {
    $input = is_array($decodedBody) ? $decodedBody : [];
    [$confNum, $tokenPayload, $identity] = requirePortalSession($input);
    if (($input['Acknowledged'] ?? false) !== true) fail(400, 'Confirm the selected extras before adding them.');
    $items = is_array($input['Items'] ?? null) ? $input['Items'] : [];
    if (!$items || count($items) > 12) fail(400, 'Select at least one valid extra.');
    $catalog = portalExtrasCatalog($confNum, $tokenPayload);
    $eligible = [];
    foreach ($catalog as $extra) if (is_array($extra) && !empty($extra['Id'])) $eligible[strtolower((string)$extra['Id'])] = $extra;
    $roomAllocationId = (string)$identity['allocations'][0]['RoomAllocationId'];
    [$allocationStatus, $allocationRaw] = pmsCall('GET', 'Reservation/GetRoomAllocation?roomAllocationID=' . rawurlencode($roomAllocationId));
    $allocation = json_decode($allocationRaw, true);
    if ($allocationStatus !== 200 || !is_array($allocation) || (int)($allocation['Status'] ?? 0) !== 1) fail(409, 'This booking can no longer accept extras.');
    [$existingStatus, $existingRaw] = pmsCall('GET', 'Reservation/GetRoomAllocationAddons?propertyID=' . rawurlencode($PROPERTY_ID) . '&roomAllocationID=' . rawurlencode($roomAllocationId));
    $addons = json_decode($existingRaw, true);
    if ($existingStatus !== 200 || !is_array($addons)) $addons = [];
    $arrival = new DateTimeImmutable(substr((string)$allocation['ArrivalDate'], 0, 10), new DateTimeZone('Australia/Sydney'));
    $nights = max(1, (int)($allocation['NumberOfNights'] ?? 1));
    foreach ($items as $item) {
        if (!is_array($item) || !preg_match('/^[a-f0-9-]{36}$/i', (string)($item['id'] ?? ''))) fail(400, 'One of the selected extras is invalid.');
        $addonId = (string)$item['id'];
        $definition = $eligible[strtolower($addonId)] ?? null;
        if (!is_array($definition)) fail(409, 'One of the selected extras is no longer offered for this booking. Nothing was added.');
        $priceType = (string)($definition['PriceType'] ?? 'perBooking');
        $perPerson = in_array($priceType, ['perPerson', 'perPersonPerNight'], true);
        $perNight = in_array($priceType, ['perNight', 'perPersonPerNight'], true);
        $quantity = max(0, min(12, (int)($item['quantity'] ?? 0)));
        $childQuantity = max(0, min(12, (int)($item['childQuantity'] ?? 0)));
        if ($perPerson) {
            if ($quantity > (int)($allocation['NumberAdults'] ?? 0) || $childQuantity > (int)($allocation['NumberChildren'] ?? 0)) fail(409, 'Extra quantities cannot exceed the guests on this booking.');
        } else {
            $maxItems = (int)($definition['MaxItems'] ?? 0);
            if ($maxItems > 0 && $quantity + $childQuantity > $maxItems) fail(409, 'That extra exceeds GuestPoint’s quantity limit.');
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
        if ($rate <= 0 && $childRate <= 0) fail(409, 'GuestPoint did not return a valid price for that extra. Nothing was added.');
        if ($quantity + $childQuantity < 1) continue;
        $repeat = $perNight ? $nights : 1;
        for ($i = 0; $i < $repeat; $i++) {
            $date = $arrival->modify('+' . $i . ' days')->format('Y-m-d') . 'T00:00:00';
            $found = false;
            foreach ($addons as &$addon) {
                if (strcasecmp((string)($addon['AddonID'] ?? ''), $addonId) === 0 && substr((string)($addon['Date'] ?? ''), 0, 10) === substr($date, 0, 10)) {
                    $addon['Quantity'] = $quantity; $addon['QuantityChild'] = $childQuantity; $addon['Rate'] = $rate; $addon['ChildRate'] = $childRate; $addon['Total'] = round($quantity*$rate + $childQuantity*$childRate, 2); $found = true; break;
                }
            }
            unset($addon);
            if (!$found) $addons[] = ['RoomAllocationAddonID'=>uuidV4(),'RoomAllocationID'=>$roomAllocationId,'AddonID'=>$addonId,'Date'=>$date,'Quantity'=>$quantity,'QuantityChild'=>$childQuantity,'Rate'=>$rate,'ChildRate'=>$childRate,'Total'=>round($quantity*$rate+$childQuantity*$childRate,2),'UpdatedLocal'=>0];
        }
    }
    [$saveStatus, $saveRaw] = pmsCall('POST', 'Reservation/SaveRoomAllocationAddons', $addons);
    $saved = json_decode($saveRaw, true);
    if ($saveStatus < 200 || $saveStatus >= 300 || !is_array($saved)) fail(502, 'GuestPoint rejected the extras. Nothing was added.', $saveRaw);
    send(200, ['Updated'=>true,'ConfNum'=>$confNum,'AddonCount'=>count($saved),'GuestPoint'=>$saved], ['Cache-Control'=>'no-store']);
}

// Step 2: atomically count attempts and return the booking only after the code
// succeeds. The challenge is single-use and deleted before GuestPoint is called.
if ($endpoint === 'portal/otp/verify') {
    $input = is_array($decodedBody) ? $decodedBody : [];
    $challengeId = strtolower(trim((string)($input['ChallengeId'] ?? '')));
    $code = trim((string)($input['Code'] ?? ''));
    if (!preg_match('/^[a-f0-9]{48}$/', $challengeId) || !preg_match('/^\d{6}$/', $code)) {
        fail(400, 'Enter the six-digit verification code.');
    }
    $path = otpChallengePath($challengeId);
    $handle = @fopen($path, 'r+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) fclose($handle);
        fail(403, 'That code is invalid or has expired. Request a new code.');
    }
    $challenge = json_decode((string)stream_get_contents($handle), true);
    if (!is_array($challenge) || (int)($challenge['expires'] ?? 0) < time()) {
        flock($handle, LOCK_UN); fclose($handle); @unlink($path);
        fail(403, 'That code is invalid or has expired. Request a new code.');
    }
    $attempts = (int)($challenge['attempts'] ?? 0) + 1;
    $challenge['attempts'] = $attempts;
    $accepted = !empty($challenge['matched'])
        && $attempts <= $OTP_MAX_ATTEMPTS
        && password_verify($code, (string)($challenge['code_hash'] ?? ''));
    if (!$accepted) {
        if ($attempts >= $OTP_MAX_ATTEMPTS) {
            flock($handle, LOCK_UN); fclose($handle); @unlink($path);
        } else {
            ftruncate($handle, 0); rewind($handle);
            fwrite($handle, json_encode($challenge)); fflush($handle);
            flock($handle, LOCK_UN); fclose($handle);
        }
        fail(403, $attempts >= $OTP_MAX_ATTEMPTS
            ? 'Too many incorrect attempts. Request a new code.'
            : 'That code is invalid or has expired. Request a new code if needed.');
    }
    flock($handle, LOCK_UN); fclose($handle); @unlink($path);

    [$status, $payload, $detail] = loadManagedReservation(
        (string)$challenge['conf_num'],
        (string)$challenge['surname'],
        (string)$challenge['email']
    );
    if (!is_array($payload)) fail(502, 'The booking system is not responding. Please try again shortly.', (string)$detail);
    send($status, $payload, ['Cache-Control' => 'no-store']);
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
    $reservationId = (int)$tokenPayload['rid'];
    $quote = is_array($tokenPayload['cq'] ?? null) ? $tokenPayload['cq'] : null;
    if ($quote === null || !isset($quote['fee'], $quote['refund'], $quote['quotedAt'])) {
        fail(403, 'The cancellation quote is missing or expired. Please look up the booking again.');
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
    if ((int)($freshReservation['ID'] ?? 0) !== $reservationId
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

    if ($PMS_PRIVATE_WRITES) {
        $identity = portalPmsIdentity($confNum, $tokenPayload);
        if (($identity['reservationId'] ?? '') === '' || count($identity['allocations'] ?? []) !== 1) fail(409, 'This booking cannot be cancelled automatically.');
        $roomAllocationId = (string)$identity['allocations'][0]['RoomAllocationId'];
        [$allocationStatus, $allocationRaw] = pmsCall('GET', 'Reservation/GetRoomAllocation?roomAllocationID=' . rawurlencode($roomAllocationId));
        $allocation = json_decode($allocationRaw, true);
        if ($allocationStatus !== 200 || !is_array($allocation) || (int)($allocation['Status'] ?? 0) !== 1) fail(409, 'This booking can no longer be cancelled.');
        $allocation['CancellationBookingValue'] = round((float)($quote['fee'] ?? 0), 2);
        $allocation['CancellationReason'] = 'Cancelled by guest through the online portal. Policy fee: $' . number_format((float)($quote['fee'] ?? 0), 2) . '; estimated refund: $' . number_format((float)($quote['refund'] ?? 0), 2) . '.';
        $allocation['CancelledAppuserID'] = (string)($allocation['UpdatedAppuserID'] ?? $allocation['CreatedAppuserID'] ?? '');
        $allocation['CancelledDate'] = (new DateTimeImmutable('now', new DateTimeZone('Australia/Sydney')))->format('Y-m-d\TH:i:s');
        $allocation['IsDeleted'] = false;
        $allocation['Status'] = 4;
        [$cancelStatus, $cancelResponse, $cancelError] = pmsCall('POST', 'Reservation/SaveRoomAllocationWithVirtualRooms?propertyID=' . rawurlencode($PROPERTY_ID) . '&addChangeLogs=true', $allocation);
        if ($cancelStatus < 200 || $cancelStatus >= 300) fail(502, 'GuestPoint rejected the cancellation. The booking remains active.', $cancelResponse ?: $cancelError);
        [$verifyStatus, $verifyRaw] = pmsCall('GET', 'Reservation/GetRoomAllocation?roomAllocationID=' . rawurlencode($roomAllocationId));
        $verified = json_decode($verifyRaw, true);
        if ($verifyStatus !== 200 || !is_array($verified) || (int)($verified['Status'] ?? 0) !== 4) fail(502, 'GuestPoint did not verify the cancellation. Please check the booking in GuestPoint.');
        send(200, ['Cancelled'=>true,'Message'=>'GuestPoint PMS has cancelled the reservation.','PolicyFee'=>$quote['fee'],'EstimatedRefund'=>$quote['refund'],'GuestPoint'=>$verified], ['Cache-Control'=>'no-store']);
    }

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
