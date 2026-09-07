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
    'reservations/manage/*' => ['PATCH',              0],
    'reservations/*'        => ['DELETE',             0],
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
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', (string)$_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

// -------------------------------------------------------- request parsing ----

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'OPTIONS') {
    $allowed = $config['allow_origins'] ?? [];
    $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
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

if ($API_KEY === '' || str_starts_with($API_KEY, 'REPLACE_WITH')) {
    fail(503, 'Booking service is not configured yet.', 'api_key missing in config.php');
}
if ($PROPERTY_ID === '' || str_starts_with($PROPERTY_ID, 'REPLACE_WITH')) {
    fail(503, 'Booking service is not configured yet.', 'property_id missing in config.php');
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
if ($method !== 'GET') {
    $body = file_get_contents('php://input') ?: '';
    if (strlen($body) > MAX_BODY_BYTES) fail(413, 'Request too large.');

    // The browser sends PropertyId: "self" for the same reason the path does —
    // the real id is server-side only. Swap it in here.
    if ($body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['PropertyId']) && $decoded['PropertyId'] === 'self') {
            $decoded['PropertyId'] = $PROPERTY_ID;
            $body = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
    if ($body !== '' && json_decode($body) === null && json_last_error() !== JSON_ERROR_NONE) {
        fail(400, 'Body must be JSON.');
    }
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
