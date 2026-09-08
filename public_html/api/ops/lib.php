<?php
declare(strict_types=1);

const OPS_SESSION_TTL = 8 * 60 * 60;
const OPS_MAX_BODY_BYTES = 64 * 1024;
const OPS_UPSTREAM_TIMEOUT = 20;
const OPS_LOGIN_LIMIT = 8;
const OPS_LOGIN_WINDOW = 15 * 60;
const OPS_RESET_TTL = 30 * 60;

function opsSend(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function opsFail(int $status, string $message): void {
    opsSend($status, ['ok' => false, 'message' => $message]);
}

function opsBase64UrlEncode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function opsBase64UrlDecode(string $value): string|false {
    return base64_decode(strtr($value, '-_', '+/'), true);
}

function opsConfiguredValue(mixed $value): bool {
    $text = trim((string)$value);
    return $text !== '' && !str_starts_with($text, 'REPLACE_WITH');
}

function opsClientIp(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = trim(explode(',', (string)$_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

function opsCheckLoginRate(array $config, bool $recordFailure = false): void {
    $dataDir = (string)($config['data_dir'] ?? '');
    if ($dataDir === '' || (!is_dir($dataDir) && !@mkdir($dataDir, 0770, true) && !is_dir($dataDir))) {
        opsFail(503, 'The middleware data directory is not writable.');
    }
    $path = $dataDir . '/login_' . hash('sha256', opsClientIp()) . '.json';
    $handle = @fopen($path, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) opsFail(503, 'Login protection is unavailable.');
    $raw = stream_get_contents($handle);
    $attempts = $raw ? (json_decode($raw, true) ?: []) : [];
    $cutoff = time() - OPS_LOGIN_WINDOW;
    $attempts = array_values(array_filter($attempts, fn($at) => (int)$at >= $cutoff));
    if (count($attempts) >= OPS_LOGIN_LIMIT) {
        flock($handle, LOCK_UN);
        fclose($handle);
        opsFail(429, 'Too many sign-in attempts. Try again in 15 minutes.');
    }
    if ($recordFailure) $attempts[] = time();
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($attempts));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function opsMasked(mixed $value): ?string {
    if (!opsConfiguredValue($value)) return null;
    $text = (string)$value;
    return '••••' . substr($text, -4);
}

function opsIssueSession(array $config, string $username): array {
    $secret = (string)($config['session_secret'] ?? '');
    if (strlen($secret) < 32) opsFail(503, 'The operations session secret is not configured.');
    $payload = [
        'iat' => time(),
        'exp' => time() + OPS_SESSION_TTL,
        'csrf' => bin2hex(random_bytes(18)),
        'username' => $username,
        'role' => 'administrator',
    ];
    $encoded = opsBase64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $signature = opsBase64UrlEncode(hash_hmac('sha256', $encoded, $secret, true));
    $cookie = $encoded . '.' . $signature;
    setcookie('kosipark_ops', $cookie, [
        'expires' => $payload['exp'],
        'path' => '/api/ops',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    return $payload;
}

function opsSession(array $config): ?array {
    $cookie = (string)($_COOKIE['kosipark_ops'] ?? '');
    $parts = explode('.', $cookie, 2);
    $secret = (string)($config['session_secret'] ?? '');
    if (count($parts) !== 2 || strlen($secret) < 32) return null;
    $expected = opsBase64UrlEncode(hash_hmac('sha256', $parts[0], $secret, true));
    if (!hash_equals($expected, $parts[1])) return null;
    $raw = opsBase64UrlDecode($parts[0]);
    $payload = $raw === false ? null : json_decode($raw, true);
    if (!is_array($payload) || (int)($payload['exp'] ?? 0) < time()) return null;
    return $payload;
}

function opsRequireSession(array $config, bool $mutation = false): array {
    $session = opsSession($config);
    if ($session === null) opsFail(401, 'Please sign in.');
    $notBefore = opsWithState($config, false, fn(array &$state): int => (int)($state['auth']['session_not_before'] ?? 0));
    if ((int)($session['iat'] ?? 0) < $notBefore) opsFail(401, 'Your session has expired. Please sign in again.');
    if ($mutation) {
        $provided = (string)($_SERVER['HTTP_X_OPS_CSRF'] ?? '');
        if ($provided === '' || !hash_equals((string)$session['csrf'], $provided)) {
            opsFail(403, 'Your session could not be verified. Refresh and try again.');
        }
    }
    return $session;
}

function opsInput(): array {
    $body = file_get_contents('php://input') ?: '';
    if (strlen($body) > OPS_MAX_BODY_BYTES) opsFail(413, 'Request too large.');
    if ($body === '') return [];
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) opsFail(400, 'Body must be a JSON object.');
    return $decoded;
}

function opsCleanText(mixed $value, string $label, int $max = 120, bool $required = true): string {
    $text = trim((string)$value);
    if ($required && $text === '') opsFail(422, "$label is required.");
    if (strlen($text) > $max) opsFail(422, "$label is too long.");
    return $text;
}

function opsInitialState(): array {
    return [
        'version' => 1,
        'mappings' => [],
        'ttlock_inventory' => [],
        'sync' => ['ttlock_at' => null, 'guestpoint_at' => null],
        'issues' => [],
        'events' => [],
        'audit' => [],
        'credentials' => [],
        'password_resets' => [],
        'diagnostics' => [],
        'auth' => ['session_not_before' => 0],
    ];
}

/** Run a callback while holding the single state-file lock. */
function opsWithState(array $config, bool $write, callable $callback): mixed {
    $dataDir = (string)($config['data_dir'] ?? '');
    if ($dataDir === '') opsFail(503, 'The middleware data directory is not configured.');
    if (!is_dir($dataDir) && !@mkdir($dataDir, 0770, true) && !is_dir($dataDir)) {
        opsFail(503, 'The middleware data directory is not writable.');
    }
    $handle = @fopen($dataDir . '/state.json', 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) opsFail(503, 'Middleware state is unavailable.');
    rewind($handle);
    $raw = stream_get_contents($handle);
    $state = $raw ? json_decode($raw, true) : null;
    if (!is_array($state)) $state = opsInitialState();
    $state = array_replace(opsInitialState(), $state);
    $result = $callback($state);
    if ($write) {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($handle);
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    return $result;
}

function opsAudit(array &$state, string $action, string $detail): void {
    array_unshift($state['audit'], [
        'id' => bin2hex(random_bytes(8)),
        'at' => gmdate('c'),
        'action' => $action,
        'detail' => $detail,
    ]);
    $state['audit'] = array_slice($state['audit'], 0, 100);
}

function opsEvent(array &$state, string $source, string $event, string $target, string $status, array $trace = []): array {
    $correlationId = bin2hex(random_bytes(16));
    $record = [
        'id' => bin2hex(random_bytes(8)),
        'at' => gmdate('c'),
        'source' => $source,
        'event' => $event,
        'target' => $target,
        'status' => $status,
        'correlation_id' => $correlationId,
        // Trace steps contain operational metadata only. Provider payloads and
        // secrets must be redacted before they reach this boundary.
        'trace' => $trace,
    ];
    array_unshift($state['events'], $record);
    $state['events'] = array_slice($state['events'], 0, 500);
    return $record;
}

/** @return array{0:int,1:array|string} */
function opsHttp(string $method, string $url, array $headers = [], ?array $form = null): array {
    if (!function_exists('curl_init')) opsFail(503, 'PHP cURL is required for provider sync.');
    $ch = curl_init($url);
    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => OPS_UPSTREAM_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $requestHeaders,
    ]);
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        $requestHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false || $status === 0) return [0, 'Provider did not respond.'];
    $decoded = json_decode((string)$raw, true);
    return [$status, is_array($decoded) ? $decoded : (string)$raw];
}

/** @return array{0:int,1:array|string} */
function opsHttpJson(string $url, array $headers, array $payload): array {
    if (!function_exists('curl_init')) opsFail(503, 'PHP cURL is required for AI diagnostics.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false || $status === 0) return [0, 'AI provider did not respond.'];
    $decoded = json_decode((string)$raw, true);
    return [$status, is_array($decoded) ? $decoded : (string)$raw];
}

function opsRedact(mixed $value, int $depth = 0): mixed {
    if ($depth > 8) return '[depth limit]';
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            $name = strtolower((string)$key);
            if (preg_match('/password|passcode|pin|token|secret|api.?key|email|phone|address/', $name)) {
                $clean[$key] = '[redacted]';
            } else {
                $clean[$key] = opsRedact($item, $depth + 1);
            }
        }
        return $clean;
    }
    if (is_string($value)) {
        $value = preg_replace('/\b\d{4,8}\b/', '[redacted-number]', $value) ?? $value;
        $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted-email]', $value) ?? $value;
        return substr($value, 0, 4000);
    }
    return $value;
}

function opsAiDiagnose(array $config, array $context): array {
    $ai = $config['ai'] ?? [];
    $provider = strtolower(trim((string)($ai['provider'] ?? '')));
    $model = trim((string)($ai['model'] ?? ''));
    if ($provider === '' || $model === '') opsFail(503, 'AI diagnostics are not configured.');
    $safeContext = opsRedact($context);
    $system = 'You are a read-only operations diagnostician for a tourist park. Analyse only the supplied redacted evidence. Return a concise diagnosis, confidence, safest proposed remediation, verification steps, and risks. Never claim an action was executed. Never request or reveal credentials, guest personal data, passcodes, or unrestricted shell access.';
    $prompt = "Diagnose this middleware issue:\n" . json_encode($safeContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($provider === 'openai') {
        $key = (string)($ai['openai_api_key'] ?? '');
        if (!opsConfiguredValue($key)) opsFail(503, 'OpenAI diagnostics are not configured.');
        [$status, $body] = opsHttpJson('https://api.openai.com/v1/responses', [
            'Authorization: Bearer ' . $key,
            'X-Client-Request-Id: ' . bin2hex(random_bytes(16)),
        ], ['model' => $model, 'store' => false, 'instructions' => $system, 'input' => $prompt]);
        if ($status < 200 || $status >= 300 || !is_array($body)) opsFail(502, 'OpenAI could not complete the diagnosis.');
        $text = (string)($body['output_text'] ?? '');
        if ($text === '') {
            foreach (($body['output'] ?? []) as $output) foreach (($output['content'] ?? []) as $part) if (($part['type'] ?? '') === 'output_text') $text .= (string)($part['text'] ?? '');
        }
    } elseif ($provider === 'anthropic') {
        $key = (string)($ai['anthropic_api_key'] ?? '');
        if (!opsConfiguredValue($key)) opsFail(503, 'Claude diagnostics are not configured.');
        [$status, $body] = opsHttpJson('https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ], ['model' => $model, 'max_tokens' => 1200, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $prompt]]]);
        if ($status < 200 || $status >= 300 || !is_array($body)) opsFail(502, 'Claude could not complete the diagnosis.');
        $text = '';
        foreach (($body['content'] ?? []) as $part) if (($part['type'] ?? '') === 'text') $text .= (string)($part['text'] ?? '');
    } else {
        opsFail(422, 'Unsupported AI provider.');
    }
    if (trim($text) === '') opsFail(502, 'The AI provider returned an empty diagnosis.');
    return ['provider' => $provider, 'model' => $model, 'text' => trim($text)];
}

function opsSyncTtlock(array $config): array {
    $tt = $config['ttlock'] ?? [];
    if (!opsConfiguredValue($tt['client_id'] ?? '') || !opsConfiguredValue($tt['access_token'] ?? '')) {
        opsFail(503, 'TTLock credentials are not configured.');
    }
    $query = http_build_query([
        'clientId' => $tt['client_id'],
        'accessToken' => $tt['access_token'],
        'pageNo' => 1,
        'pageSize' => 200,
        'date' => (int)round(microtime(true) * 1000),
    ], '', '&', PHP_QUERY_RFC3986);
    [$status, $payload] = opsHttp('GET', rtrim((string)$tt['upstream'], '/') . '/v3/lock/list?' . $query);
    if ($status !== 200 || !is_array($payload) || (isset($payload['errcode']) && (int)$payload['errcode'] !== 0)) {
        $message = is_array($payload) ? (string)($payload['errmsg'] ?? 'TTLock sync failed.') : 'TTLock sync failed.';
        opsFail(502, $message);
    }
    $rows = $payload['list'] ?? [];
    if (!is_array($rows)) opsFail(502, 'TTLock returned an unexpected lock list.');
    $locks = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !isset($row['lockId'])) continue;
        $locks[] = [
            'lock_id' => (string)$row['lockId'],
            'name' => trim((string)($row['lockAlias'] ?? $row['lockName'] ?? ('Lock ' . $row['lockId']))),
            'battery' => isset($row['electricQuantity']) ? (int)$row['electricQuantity'] : null,
            'has_gateway' => isset($row['hasGateway']) ? (bool)$row['hasGateway'] : null,
            'updated_at' => gmdate('c'),
        ];
    }
    return $locks;
}

function opsCheckGuestpoint(array $config): array {
    $gp = $config['guestpoint'] ?? [];
    if (!opsConfiguredValue($gp['core_upstream'] ?? '') || !opsConfiguredValue($gp['core_api_key'] ?? '')) {
        opsFail(503, 'GuestPoint Core credentials are not configured.');
    }
    $date = gmdate('Y-m-d', time() - 2 * 86400);
    $url = rtrim((string)$gp['core_upstream'], '/') . '/v1/reservations/changesFrom/' . rawurlencode($date);
    [$status, $payload] = opsHttp('GET', $url, ['X-API-KEY: ' . $gp['core_api_key']]);
    if ($status !== 200 || !is_array($payload)) opsFail(502, 'GuestPoint reconciliation check failed.');
    $rows = $payload['Data'] ?? $payload['data'] ?? $payload;
    if (!is_array($rows)) $rows = [];
    return ['changed_reservations' => count($rows), 'since' => $date];
}

function opsProviderStatus(array $config): array {
    $gp = $config['guestpoint'] ?? [];
    $tt = $config['ttlock'] ?? [];
    $bg = $config['boomgate'] ?? [];
    return [
        'guestpoint' => [
            'configured' => opsConfiguredValue($gp['property_id'] ?? '') && opsConfiguredValue($gp['core_api_key'] ?? '') && opsConfiguredValue($gp['core_upstream'] ?? ''),
            'key' => opsMasked($gp['core_api_key'] ?? ''),
            'property' => opsMasked($gp['property_id'] ?? ''),
            'scope' => 'Bookings, guests, stays',
            'version' => (string)($gp['secret_version'] ?? 'v1'),
            'rotation_due' => (string)($gp['rotation_due'] ?? '') ?: 'Not scheduled',
        ],
        'ttlock' => [
            'configured' => opsConfiguredValue($tt['client_id'] ?? '') && opsConfiguredValue($tt['access_token'] ?? ''),
            'client_id' => opsMasked($tt['client_id'] ?? ''),
            'token' => opsMasked($tt['access_token'] ?? ''),
            'scope' => 'Locks, passcodes, gateways',
            'version' => (string)($tt['secret_version'] ?? 'v1'),
            'rotation_due' => (string)($tt['rotation_due'] ?? '') ?: 'Not scheduled',
        ],
        'boomgate' => [
            'configured' => opsConfiguredValue($bg['park_id'] ?? '') && opsConfiguredValue($bg['sign_secret'] ?? '') && opsConfiguredValue($bg['control_url'] ?? ''),
            'park_id' => opsMasked($bg['park_id'] ?? ''),
            'scope' => 'Entry, exit, visitors',
            'version' => (string)($bg['secret_version'] ?? 'v1'),
            'rotation_due' => (string)($bg['rotation_due'] ?? '') ?: 'Awaiting setup',
            'control_enabled' => false,
            'note' => 'Waiting for the vendor to confirm control direction and production authentication.',
        ],
    ];
}
