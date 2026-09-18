<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$configFile = __DIR__ . '/config.php';
$config = is_file($configFile) ? require $configFile : require __DIR__ . '/config.sample.php';
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_GET['action'] ?? 'overview'));

if ($method === 'OPTIONS') {
    header('Allow: GET, POST, DELETE, OPTIONS');
    http_response_code(204);
    exit;
}

if ($action === 'login' && $method === 'POST') {
    $input = opsInput();
    $username = strtolower(opsCleanText($input['username'] ?? '', 'Username', 80));
    $configuredUsername = strtolower((string)($config['admin_username'] ?? 'admin'));
    $hash = opsWithState($config, false, function (array &$state) use ($configuredUsername, $config): string {
        return (string)($state['credentials'][$configuredUsername]['password_hash'] ?? $config['admin_password_hash'] ?? '');
    });
    if ($hash === '') opsFail(503, 'The operations dashboard login is not configured.');
    opsCheckLoginRate($config);
    if (!hash_equals($configuredUsername, $username) || !password_verify((string)($input['password'] ?? ''), $hash)) {
        opsCheckLoginRate($config, true);
        usleep(350000);
        opsFail(401, 'That username or password was not accepted.');
    }
    $session = opsIssueSession($config, $configuredUsername);
    opsSend(200, ['ok' => true, 'csrf' => $session['csrf'], 'user' => ['username' => $configuredUsername, 'role' => 'administrator']]);
}

if ($action === 'forgot-password' && $method === 'POST') {
    $input = opsInput();
    opsCheckLoginRate($config, true);
    $username = strtolower(opsCleanText($input['username'] ?? '', 'Username', 80));
    $configuredUsername = strtolower((string)($config['admin_username'] ?? 'admin'));
    $adminEmail = trim((string)($config['admin_email'] ?? ''));
    if (hash_equals($configuredUsername, $username) && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $token = opsBase64UrlEncode(random_bytes(32));
        opsWithState($config, true, function (array &$state) use ($username, $token): void {
            $state['password_resets'][$username] = ['token_hash' => hash('sha256', $token), 'expires_at' => time() + OPS_RESET_TTL];
            opsAudit($state, 'auth.password_reset_requested', $username);
        });
        $base = rtrim((string)($config['app_url'] ?? ''), '/');
        $link = $base . '/ops/?reset=' . rawurlencode($token) . '&username=' . rawurlencode($username);
        $from = trim((string)($config['mail_from'] ?? ''));
        $headers = $from !== '' ? ['From: ' . $from, 'Content-Type: text/plain; charset=UTF-8'] : ['Content-Type: text/plain; charset=UTF-8'];
        @mail($adminEmail, 'Reset your KosiPark Operations password', "A password reset was requested.\n\nOpen this one-time link within 30 minutes:\n" . $link . "\n\nIf you did not request this, ignore this message.", implode("\r\n", $headers));
    }
    opsSend(202, ['ok' => true, 'message' => 'If recovery is configured for that username, a reset link has been sent.']);
}

if ($action === 'reset-password' && $method === 'POST') {
    $input = opsInput();
    $username = strtolower(opsCleanText($input['username'] ?? '', 'Username', 80));
    $token = opsCleanText($input['token'] ?? '', 'Reset token', 160);
    $password = (string)($input['password'] ?? '');
    if (strlen($password) < 12) opsFail(422, 'Use a password with at least 12 characters.');
    $configuredUsername = strtolower((string)($config['admin_username'] ?? 'admin'));
    opsWithState($config, true, function (array &$state) use ($username, $configuredUsername, $token, $password): void {
        $reset = $state['password_resets'][$username] ?? null;
        if (!hash_equals($configuredUsername, $username) || !is_array($reset) || (int)($reset['expires_at'] ?? 0) < time() || !hash_equals((string)($reset['token_hash'] ?? ''), hash('sha256', $token))) {
            opsFail(400, 'That reset link is invalid or has expired.');
        }
        $state['credentials'][$username] = ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'updated_at' => gmdate('c')];
        unset($state['password_resets'][$username]);
        $state['auth']['session_not_before'] = time();
        opsAudit($state, 'auth.password_reset_completed', $username);
    });
    opsSend(200, ['ok' => true, 'message' => 'Password updated. You can now sign in.']);
}

if ($action === 'logout' && $method === 'POST') {
    opsRequireSession($config, true);
    setcookie('kosipark_ops', '', ['expires' => 1, 'path' => '/api/ops', 'httponly' => true, 'samesite' => 'Strict']);
    opsSend(200, ['ok' => true]);
}

$session = opsRequireSession($config, $method !== 'GET');

if ($action === 'overview' && $method === 'GET') {
    $snapshot = opsWithState($config, false, function (array &$state): array {
        return [
            'mappings' => array_values($state['mappings'] ?? []),
            'inventory' => array_values($state['ttlock_inventory'] ?? []),
            'sync' => $state['sync'] ?? [],
            'issues' => array_values($state['issues'] ?? []),
            'events' => array_slice(array_values($state['events'] ?? []), 0, 100),
            'audit' => array_slice(array_values($state['audit'] ?? []), 0, 20),
        ];
    });
    opsSend(200, ['ok' => true, 'csrf' => $session['csrf'], 'user' => ['username' => $session['username'] ?? 'admin', 'role' => $session['role'] ?? 'administrator'], 'providers' => opsProviderStatus($config)] + $snapshot);
}

if ($action === 'diagnose' && $method === 'POST') {
    $input = opsInput();
    $id = opsCleanText($input['id'] ?? '', 'Issue ID', 80);
    $context = opsWithState($config, false, function (array &$state) use ($id): array {
        $issue = $state['issues'][$id] ?? null;
        if (!is_array($issue)) opsFail(404, 'Issue not found.');
        $event = null;
        foreach (($state['events'] ?? []) as $candidate) if (($candidate['id'] ?? '') === ($issue['event_id'] ?? '')) { $event = $candidate; break; }
        return ['issue' => $issue, 'event' => $event];
    });
    $result = opsAiDiagnose($config, $context);
    opsWithState($config, true, function (array &$state) use ($id, $result): void {
        array_unshift($state['diagnostics'], ['id' => bin2hex(random_bytes(8)), 'issue_id' => $id, 'at' => gmdate('c'), 'provider' => $result['provider'], 'model' => $result['model'], 'text' => $result['text'], 'status' => 'proposal_only']);
        $state['diagnostics'] = array_slice($state['diagnostics'], 0, 100);
        opsAudit($state, 'ai.diagnosis_created', 'Read-only diagnosis for issue ' . $id);
    });
    opsSend(200, ['ok' => true] + $result + ['status' => 'proposal_only']);
}

if ($action === 'mapping' && $method === 'POST') {
    $input = opsInput();
    $mapping = [
        'id' => opsCleanText($input['id'] ?? bin2hex(random_bytes(8)), 'Mapping ID', 64),
        'unit_id' => opsCleanText($input['unit_id'] ?? '', 'GuestPoint room ID', 80),
        'unit_name' => opsCleanText($input['unit_name'] ?? '', 'Unit name', 100),
        'lock_id' => opsCleanText($input['lock_id'] ?? '', 'TTLock ID', 80),
        'lock_name' => opsCleanText($input['lock_name'] ?? '', 'Lock name', 100, false),
        'gate_access' => !empty($input['gate_access']),
        'updated_at' => gmdate('c'),
    ];
    opsWithState($config, true, function (array &$state) use ($mapping): void {
        foreach ($state['mappings'] as $existing) {
            if (($existing['id'] ?? '') !== $mapping['id'] && (($existing['unit_id'] ?? '') === $mapping['unit_id'] || ($existing['lock_id'] ?? '') === $mapping['lock_id'])) {
                opsFail(409, 'That room or lock is already mapped. Edit the existing mapping instead.');
            }
        }
        $state['mappings'][$mapping['id']] = $mapping;
        opsAudit($state, 'mapping.saved', $mapping['unit_name'] . ' linked to ' . ($mapping['lock_name'] ?: $mapping['lock_id']));
        opsEvent($state, 'Middleware', 'mapping.updated', 'TTLock', 'Delivered', [
            ['status' => 'complete', 'label' => 'Mapping validated', 'detail' => $mapping['unit_name']],
            ['status' => 'complete', 'label' => 'Mapping saved', 'detail' => 'Lock ' . $mapping['lock_id']],
        ]);
    });
    opsSend(200, ['ok' => true, 'mapping' => $mapping]);
}

if ($action === 'mapping' && $method === 'DELETE') {
    $id = opsCleanText($_GET['id'] ?? '', 'Mapping ID', 64);
    opsWithState($config, true, function (array &$state) use ($id): void {
        if (!isset($state['mappings'][$id])) opsFail(404, 'Mapping not found.');
        $name = (string)($state['mappings'][$id]['unit_name'] ?? $id);
        unset($state['mappings'][$id]);
        opsAudit($state, 'mapping.deleted', $name . ' was unlinked');
        opsEvent($state, 'Middleware', 'mapping.deleted', 'TTLock', 'Delivered');
    });
    opsSend(200, ['ok' => true]);
}

if ($action === 'sync-ttlock' && $method === 'POST') {
    $locks = opsSyncTtlock($config);
    opsWithState($config, true, function (array &$state) use ($locks): void {
        $state['ttlock_inventory'] = [];
        foreach ($locks as $lock) $state['ttlock_inventory'][$lock['lock_id']] = $lock;
        $state['sync']['ttlock_at'] = gmdate('c');
        opsAudit($state, 'ttlock.synced', count($locks) . ' locks imported');
        opsEvent($state, 'TTLock', 'locks.inventory_synced', 'Middleware', 'Delivered', [
            ['status' => 'complete', 'label' => 'Provider request', 'detail' => 'GET /v3/lock/list'],
            ['status' => 'complete', 'label' => 'Inventory stored', 'detail' => count($locks) . ' locks'],
        ]);
    });
    opsSend(200, ['ok' => true, 'count' => count($locks)]);
}

if ($action === 'check-guestpoint' && $method === 'POST') {
    $result = opsCheckGuestpoint($config);
    opsWithState($config, true, function (array &$state) use ($result): void {
        $state['sync']['guestpoint_at'] = gmdate('c');
        opsAudit($state, 'guestpoint.checked', $result['changed_reservations'] . ' changed reservations found (dry run)');
        opsEvent($state, 'GuestPoint', 'reservations.changes_checked', 'Middleware', 'Delivered', [
            ['status' => 'complete', 'label' => 'Change feed requested', 'detail' => 'Since ' . $result['since']],
            ['status' => 'complete', 'label' => 'Changes received', 'detail' => $result['changed_reservations'] . ' reservations'],
        ]);
    });
    opsSend(200, ['ok' => true] + $result);
}

if ($action === 'test-connection' && $method === 'POST') {
    $input = opsInput();
    $provider = opsCleanText($input['provider'] ?? '', 'Provider', 24);
    $started = microtime(true);
    if ($provider === 'ttlock') {
        $result = ['count' => count(opsSyncTtlock($config))];
    } elseif ($provider === 'guestpoint') {
        $result = opsCheckGuestpoint($config);
    } elseif ($provider === 'boomgate') {
        opsFail(409, 'Boom-gate testing is disabled until the vendor confirms authentication and control direction.');
    } else {
        opsFail(422, 'Unknown provider.');
    }
    $latency = (int)round((microtime(true) - $started) * 1000);
    opsWithState($config, true, function (array &$state) use ($provider, $latency): void {
        opsAudit($state, 'connection.tested', ucfirst($provider) . ' responded in ' . $latency . 'ms');
        opsEvent($state, 'Middleware', 'connection.tested', ucfirst($provider), 'Delivered');
    });
    opsSend(200, ['ok' => true, 'provider' => $provider, 'latency_ms' => $latency] + $result);
}

if ($action === 'retry-issue' && $method === 'POST') {
    $input = opsInput();
    $id = opsCleanText($input['id'] ?? '', 'Issue ID', 80);
    opsWithState($config, true, function (array &$state) use ($id): void {
        if (!isset($state['issues'][$id])) opsFail(404, 'Issue not found.');
        $issue = &$state['issues'][$id];
        if (empty($issue['replayable'])) opsFail(409, 'This issue cannot be retried automatically.');
        // Scheduling is separate from provider execution. A future worker must
        // claim this item and perform an idempotency check before any write.
        $issue['status'] = 'retry_scheduled';
        $issue['retries'] = (int)($issue['retries'] ?? 0) + 1;
        $issue['last_attempt'] = gmdate('c');
        opsAudit($state, 'issue.retry_scheduled', (string)($issue['title'] ?? $id));
        opsEvent($state, 'Middleware', 'issue.retry_scheduled', (string)($issue['provider'] ?? 'Provider'), 'Processing', [
            ['status' => 'complete', 'label' => 'Retry authorised', 'detail' => 'Operator requested a safe retry'],
            ['status' => 'processing', 'label' => 'Idempotency check required', 'detail' => 'Awaiting worker'],
        ]);
    });
    opsSend(202, ['ok' => true, 'status' => 'retry_scheduled']);
}

opsFail(404, 'Unknown operations action.');
