<?php
/**
 * Kosipark operations middleware configuration.
 *
 * Prefer environment variables in Hostinger. If that is not available, copy
 * this file to config.php; config.php is ignored by git and blocked by the
 * site's .htaccess rules.
 *
 * Generate the admin password hash with:
 *   php -r "echo password_hash('choose-a-long-password', PASSWORD_DEFAULT), PHP_EOL;"
 * Generate the session secret with:
 *   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 */
return [
    'admin_username'      => getenv('OPS_ADMIN_USERNAME') ?: 'admin',
    'admin_password_hash' => getenv('OPS_ADMIN_PASSWORD_HASH') ?: '',
    'admin_email'         => getenv('OPS_ADMIN_EMAIL') ?: '',
    'session_secret'      => getenv('OPS_SESSION_SECRET') ?: '',
    'data_dir'            => getenv('OPS_DATA_DIR') ?: dirname($_SERVER['DOCUMENT_ROOT']) . '/kosipark-middleware',
    'app_url'             => rtrim(getenv('OPS_APP_URL') ?: 'https://kosipark.com.au', '/'),
    'mail_from'           => getenv('OPS_MAIL_FROM') ?: '',

    // AI is diagnostic-only. Keys remain server-side and provider payloads are
    // redacted before transmission. Set model explicitly so upgrades are deliberate.
    'ai' => [
        'provider' => strtolower(getenv('OPS_AI_PROVIDER') ?: ''), // openai or anthropic
        'model' => getenv('OPS_AI_MODEL') ?: '',
        'openai_api_key' => getenv('OPENAI_API_KEY') ?: '',
        'anthropic_api_key' => getenv('ANTHROPIC_API_KEY') ?: '',
    ],

    'guestpoint' => [
        'property_id' => getenv('GP_PROPERTY_ID') ?: '',
        'core_api_key' => getenv('GP_CORE_API_KEY') ?: (getenv('GP_API_KEY') ?: ''),
        'core_upstream' => rtrim(getenv('GP_CORE_UPSTREAM') ?: '', '/'),
        'secret_version' => getenv('GP_SECRET_VERSION') ?: 'v1',
        'rotation_due' => getenv('GP_ROTATION_DUE') ?: '',
    ],

    'ttlock' => [
        'client_id'    => getenv('TTLOCK_CLIENT_ID') ?: '',
        'access_token' => getenv('TTLOCK_ACCESS_TOKEN') ?: '',
        'upstream'     => rtrim(getenv('TTLOCK_UPSTREAM') ?: 'https://api.sciener.com', '/'),
        'secret_version' => getenv('TTLOCK_SECRET_VERSION') ?: 'v1',
        'rotation_due' => getenv('TTLOCK_ROTATION_DUE') ?: '',
    ],

    // The supplied boom-gate document does not define production auth or
    // which party hosts remote gate control. Keep disabled until confirmed.
    'boomgate' => [
        'park_id'      => getenv('BOOMGATE_PARK_ID') ?: '',
        'sign_secret'  => getenv('BOOMGATE_SIGN_SECRET') ?: '',
        'control_url'  => rtrim(getenv('BOOMGATE_CONTROL_URL') ?: '', '/'),
        'secret_version' => getenv('BOOMGATE_SECRET_VERSION') ?: 'v1',
        'rotation_due' => getenv('BOOMGATE_ROTATION_DUE') ?: '',
    ],
];
