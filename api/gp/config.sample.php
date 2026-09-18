<?php
/**
 * Copy to config.php on the server and fill in. config.php is gitignored and
 * blocked by .htaccess — it must never reach the repo or the browser.
 *
 *   cp config.sample.php config.php && nano config.php
 *
 * Safer still: leave this file as-is and set the two values as environment
 * variables in hPanel (Advanced -> PHP Configuration -> environment), which
 * keeps the key off the filesystem entirely.
 */
return [
    // From GuestPoint. This is the only place it exists. Never ships to the browser.
    'api_key'     => getenv('GP_API_KEY')     ?: 'REPLACE_WITH_API_KEY',

    // The KTP property GUID in GuestPoint.
    'property_id' => getenv('GP_PROPERTY_ID') ?: 'REPLACE_WITH_PROPERTY_ID',

    // GuestPoint Booking Engine base. Change only if GuestPoint moves it.
    'upstream'    => 'https://beapi.guestpoint.dev/api/v1',

    // GuestPoint Core/PMS API. GuestPoint must supply the correct environment
    // URL; the uploaded Core specification deliberately does not declare one.
    // This is used server-side to verify reference + surname + stored mobile
    // before the Booking Engine manage endpoint is called.
    'core_upstream' => getenv('GP_CORE_UPSTREAM') ?: '',
    'core_api_key'  => getenv('GP_CORE_API_KEY') ?: (getenv('GP_API_KEY') ?: 'REPLACE_WITH_API_KEY'),

    // Short guest-request notifications from the manage-booking portal.
    // Confirm this address before production if the park uses .com.au instead.
    'notification_email' => getenv('GP_NOTIFICATION_EMAIL') ?: 'stay@kosipark.com',

    // Hostinger mailbox used for one-time booking access codes. Create the
    // mailbox in hPanel, then keep its password in an environment variable —
    // never put the real password in this file. Hostinger Email uses encrypted
    // SMTP on smtp.hostinger.com:465.
    'smtp_host'     => getenv('KOSIPARK_SMTP_HOST') ?: 'smtp.hostinger.com',
    'smtp_port'     => (int)(getenv('KOSIPARK_SMTP_PORT') ?: 465),
    'smtp_username' => getenv('KOSIPARK_SMTP_USERNAME') ?: '',
    'smtp_password' => getenv('KOSIPARK_SMTP_PASSWORD') ?: '',
    'otp_from_email'=> getenv('KOSIPARK_OTP_FROM_EMAIL') ?: (getenv('KOSIPARK_SMTP_USERNAME') ?: ''),
    'otp_from_name' => 'Kosciuszko Tourist Park',
    'otp_ttl'       => 10 * 60,
    'otp_max_attempts' => 5,

    // Server-side cache directory. Must be writable and OUTSIDE public_html.
    // Falls back to the system temp dir when this path is not writable.
    'cache_dir'   => dirname($_SERVER['DOCUMENT_ROOT']) . '/gp-cache',

    // Only these origins may call the proxy. Empty array = same-origin only.
    'allow_origins' => [],

    // Set true while wiring things up: echoes upstream errors to the browser.
    // MUST be false in production — upstream errors can name internal detail.
    'debug'       => false,
];
