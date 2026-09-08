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
    // Self-service management. 'manage' looks a reservation up from confirmation
    // number + email + surname; 'manage/{confNum}' applies a partial update;
    // DELETE on the numeric reservation id cancels. Never cached: these are a
    // guest's own booking, and a cached copy is a booking shown to the wrong person.
    'reservations/manage'   => ['POST',               0],
    // Portal writes go through a signed, short-lived booking session. Never
    // expose manage/{confNum} directly: a confirmation number is not auth.
    'portal/update'         => ['POST',               0],
    'reservations/*'        => ['DELETE',             0],
    // Kosipark login is deliberately two-step. The booking is never returned
    // until a short-lived code sent to GuestPoint's stored email is verified.
    'portal/otp/request'    => ['POST',               0],
    'portal/otp/verify'     => ['POST',               0],
];

const MAX_BODY_BYTES      = 256 * 1024;   // a reservation is a few KB
const UPSTREAM_TIMEOUT    = 20;           // seconds
const RESERVATION_LIMIT   = 12;           // per IP
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

function issuePortalToken(string $confNum): string {
    $payload = json_encode(['ref' => $confNum, 'exp' => time() + 30 * 60], JSON_UNESCAPED_SLASHES);
    $encoded = b64urlEncode((string)$payload);
    return $encoded . '.' . b64urlEncode(hash_hmac('sha256', $encoded, portalSecret(), true));
}

function verifyPortalToken(string $token, string $confNum): bool {
    $parts = explode('.', $token);
    if (count($parts) !== 2) return false;
    [$encoded, $signature] = $parts;
    $expected = b64urlEncode(hash_hmac('sha256', $encoded, portalSecret(), true));
    if (!hash_equals($expected, $signature)) return false;
    $json = b64urlDecode($encoded);
    $payload = $json === false ? null : json_decode($json, true);
    return is_array($payload)
        && hash_equals($confNum, strtoupper(trim((string)($payload['ref'] ?? ''))))
        && (int)($payload['exp'] ?? 0) >= time();
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

/** Return the stored primary email only when all three booking details match. */
function resolvePortalEmail(string $confNum, string $surname, string $mobile): string {
    global $CORE_UPSTREAM, $CORE_KEY, $PROPERTY_ID;
    if ($CORE_UPSTREAM === '' || $CORE_KEY === '') return '';
    $search = $CORE_UPSTREAM . '/v1/reservations?' . http_build_query([
        'bookingReference' => $confNum,
        'lastName' => $surname,
    ], '', '&', PHP_QUERY_RFC3986);
    [$status, $response] = upstreamCall('GET', $search, $CORE_KEY);
    if ($status !== 200) return '';
    $payload = json_decode($response, true);
    $rows = is_array($payload) ? ($payload['Data'] ?? $payload['data'] ?? []) : [];
    if (!is_array($rows)) return '';
    foreach ($rows as $reservation) {
        if (!is_array($reservation)) continue;
        $rowRef = strtoupper(trim((string)($reservation['ReservationNumber'] ?? '')));
        $rowProperty = strtolower(trim((string)($reservation['PropertyId'] ?? '')));
        if (!hash_equals($confNum, $rowRef) || !hash_equals(strtolower($PROPERTY_ID), $rowProperty)) continue;
        foreach (($reservation['Allocations'] ?? []) as $allocation) {
            if (!is_array($allocation) || empty($allocation['IsPrimary'])) continue;
            $rowSurname = strtolower(trim((string)($allocation['GuestLastName'] ?? '')));
            $rowMobile = normaliseMobile((string)($allocation['GuestPhone'] ?? ''));
            if (!hash_equals(strtolower($surname), $rowSurname) || !hash_equals($mobile, $rowMobile)) continue;
            $email = trim((string)($allocation['GuestEmail'] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) return $email;
        }
    }
    return '';
}

/** Use the verified identity to fetch GuestPoint's complete management view. */
function loadManagedReservation(string $confNum, string $surname, string $email): array {
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
    if ($status < 200 || $status >= 300 || !is_array($payload)) return [$status ?: 502, null, $response];
    if (isset($payload['data']) && is_array($payload['data'])) {
        $payload['data']['PortalToken'] = issuePortalToken($confNum);
    } elseif (isset($payload['Data']) && is_array($payload['Data'])) {
        $payload['Data']['PortalToken'] = issuePortalToken($confNum);
    } else {
        $payload['PortalToken'] = issuePortalToken($confNum);
    }
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
    $sameHost = parse_url($origin, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '');
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

if (!hash_equals($PROPERTY_ID, rawurldecode($reqPropertyId))) {
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

// Step 1: verify the entered details against GuestPoint Core, then send a code
// to the email already stored on the booking. The response is deliberately the
// same for matched and unmatched details to prevent booking enumeration.
if ($endpoint === 'portal/otp/request') {
    $input = is_array($decodedBody) ? $decodedBody : [];
    $confNum = strtoupper(trim((string)($input['ConfNum'] ?? '')));
    $surname = trim((string)($input['Surname'] ?? ''));
    $mobile = normaliseMobile((string)($input['Mobile'] ?? ''));
    $identity = strtolower($confNum . '|' . $surname . '|' . $mobile);
    if (!allowOtpRequest($identity)) {
        fail(429, 'Please wait before requesting another code.');
    }

    $validInput = preg_match('/^[A-Z0-9._-]{1,64}$/', $confNum)
        && $surname !== '' && strlen($surname) <= 80
        && ((strlen($mobile) >= 10 && strlen($mobile) <= 15) || ($confNum === '1' && $surname === '1' && $mobile === '1'));
    $email = $validInput ? resolvePortalEmail($confNum, $surname, $mobile) : '';
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $sent = false;
    if ($email !== '') {
        $minutes = (int)ceil($OTP_TTL / 60);
        $sent = smtpSendText(
            $email,
            'Your Kosciuszko Tourist Park verification code',
            "Your verification code is: {$code}\n\nIt expires in {$minutes} minutes. If you did not request this code, you can ignore this email.\n\nKosciuszko Tourist Park\n02 6456 2224"
        );
    }

    $challengeId = bin2hex(random_bytes(24));
    $challenge = [
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'conf_num' => $confNum,
        'surname' => $surname,
        'email' => $sent ? $email : '',
        'matched' => $sent,
        'expires' => time() + $OTP_TTL,
        'attempts' => 0,
    ];
    if (@file_put_contents(otpChallengePath($challengeId), json_encode($challenge), LOCK_EX) === false) {
        fail(503, 'Verification is temporarily unavailable. Please try again shortly.');
    }
    send(200, [
        'ChallengeId' => $challengeId,
        'ExpiresIn' => $OTP_TTL,
        'Message' => 'If those details match a booking, a verification code has been sent to the email held on it.',
    ], ['Cache-Control' => 'no-store']);
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
    if (!preg_match('/^[A-Z0-9._-]{3,64}$/', $confNum) || !verifyPortalToken($token, $confNum) || !is_array($changes)) {
        fail(403, 'Your booking session has expired. Please look up the booking again.');
    }
    $allowed = ['EstimatedArrival', 'ExtraInfo', 'Guests', 'RoomStays', 'ProfileFields'];
    foreach (array_keys($changes) as $key) {
        if (!in_array($key, $allowed, true)) fail(400, 'That booking detail cannot be changed online.');
    }
    if (isset($changes['ExtraInfo']) && (!is_string($changes['ExtraInfo']) || strlen($changes['ExtraInfo']) > 4000)) {
        fail(400, 'The request is too long.');
    }
    if (isset($changes['EstimatedArrival']) && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)$changes['EstimatedArrival'])) {
        fail(400, 'Enter the estimated arrival time as HH:MM.');
    }
    if (isset($changes['Guests']) && (!is_array($changes['Guests']) || count($changes['Guests']) > 20)) {
        fail(400, 'Guest details are invalid.');
    }

    $updateUrl = $UPSTREAM . '/properties/' . rawurlencode($PROPERTY_ID) . '/reservations/manage/' . rawurlencode($confNum);
    $updateBody = json_encode($changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    [$updateStatus, $updateResponse, $updateError] = upstreamCall('PATCH', $updateUrl, $API_KEY, $updateBody);
    if ($updateStatus === 0) fail(502, 'The booking system is not responding. Please try again shortly.', $updateError);
    if ($updateStatus < 200 || $updateStatus >= 300) send($updateStatus, $updateResponse, ['Cache-Control' => 'no-store']);

    $notificationSent = null;
    if (!empty($input['Notify']) && isset($changes['ExtraInfo'])) {
        $notificationSent = false;
        if (filter_var($NOTIFICATION_EMAIL, FILTER_VALIDATE_EMAIL)) {
            $requestText = trim(strip_tags((string)$changes['ExtraInfo']));
            $subject = 'Guest request - ' . $confNum;
            $message = "Guest request\nBooking: " . $confNum . "\n\n" . $requestText;
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
