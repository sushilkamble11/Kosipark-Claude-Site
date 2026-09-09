<?php
declare(strict_types=1);

/**
 * Kosipark V2 read-only GuestPoint proxy.
 *
 * V2 may read inventory, rates and eligible extras to help guests choose.
 * It cannot create, change, cancel or pay for a reservation. Final booking is
 * completed in GuestPoint's secure hosted booking system.
 */

$configFile = __DIR__ . '/config.php';
$config = is_file($configFile) ? require $configFile : require __DIR__ . '/config.sample.php';

$apiKey = trim((string)($config['api_key'] ?? ''));
$propertyId = trim((string)($config['property_id'] ?? ''));
$upstream = rtrim((string)($config['upstream'] ?? 'https://beapi.guestpoint.dev/api/v1'), '/');
$cacheDir = trim((string)($config['cache_dir'] ?? ''));
$debug = (bool)($config['debug'] ?? false);

if ($cacheDir === '') $cacheDir = sys_get_temp_dir() . '/kosipark-v2-gp-cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0770, true);

function respond(int $status, $payload, array $headers = []): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    foreach ($headers as $name => $value) header($name . ': ' . $value);
    echo is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function errorResponse(int $status, string $message, ?string $detail = null): never {
    global $debug;
    $payload = ['Error' => ['Code' => $status, 'Message' => $message]];
    if ($debug && $detail !== null) $payload['Error']['Detail'] = $detail;
    respond($status, $payload, ['Cache-Control' => 'no-store']);
}

// A quiet marker lets the local/demo client switch to clearly-labelled sample
// data without logging failed network requests.
if ($apiKey === '' || $propertyId === '' || str_starts_with($apiKey, 'REPLACE_') || str_starts_with($propertyId, 'REPLACE_')) {
    respond(200, ['Unconfigured' => true], ['Cache-Control' => 'no-store']);
}

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
$prefix = '/api/gp/properties/self/';
if (!str_starts_with($path, $prefix)) errorResponse(404, 'Unknown GuestPoint route.');
$endpoint = trim(substr($path, strlen($prefix)), '/');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// POST /extras is a read operation in GuestPoint: it returns add-ons eligible
// for proposed room stays. No route in this list can mutate a reservation.
$routes = [
    'availabilities'     => ['GET', 5 * 60],
    'bestavailablerates' => ['GET', 15 * 60],
    'extras'             => ['POST', 15 * 60],
    'beprofilefields'    => ['GET', 24 * 60 * 60],
];

if (preg_match('#^promocodes/[^/]+$#', $endpoint)) {
    $allowedMethod = 'GET';
    $ttl = 10 * 60;
} elseif (isset($routes[$endpoint])) {
    [$allowedMethod, $ttl] = $routes[$endpoint];
} else {
    errorResponse(404, 'That GuestPoint operation is not available in Kosipark V2.');
}

if ($method !== $allowedMethod) errorResponse(405, 'Method not allowed.');

$body = null;
if ($method === 'POST') {
    $body = file_get_contents('php://input');
    if ($body === false || strlen($body) > 256 * 1024) errorResponse(413, 'Request is too large.');
    if ($body !== '' && json_decode($body, true) === null && json_last_error() !== JSON_ERROR_NONE) {
        errorResponse(400, 'Request body must be valid JSON.');
    }
}

$query = (string)($_SERVER['QUERY_STRING'] ?? '');
$target = $upstream . '/properties/' . rawurlencode($propertyId) . '/' . $endpoint
        . ($query !== '' ? '?' . $query : '');
$cacheKey = hash('sha256', $method . ' ' . $target . ' ' . (string)$body);
$cacheFile = $cacheDir . '/v2_' . $cacheKey . '.json';

if (is_file($cacheFile) && time() - filemtime($cacheFile) < $ttl) {
    respond(200, (string)file_get_contents($cacheFile), [
        'X-Proxy-Cache' => 'HIT',
        'Cache-Control' => 'private, max-age=' . max(0, $ttl - (time() - filemtime($cacheFile))),
    ]);
}

$curl = curl_init($target);
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => array_filter([
        'X-API-KEY: ' . $apiKey,
        'Accept: application/json',
        $body !== null ? 'Content-Type: application/json' : null,
    ]),
]);
if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);

$response = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$curlError = curl_error($curl);
curl_close($curl);

if ($response === false || $status === 0) {
    errorResponse(502, 'The availability service is not responding. Please try again shortly.', $curlError);
}

if ($status >= 200 && $status < 300) {
    $temp = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($temp, $response, LOCK_EX) !== false) @rename($temp, $cacheFile);
    else @unlink($temp);
}

respond($status, (string)$response, [
    'X-Proxy-Cache' => 'MISS',
    'Cache-Control' => $status >= 200 && $status < 300 ? 'private, max-age=' . $ttl : 'no-store',
]);
