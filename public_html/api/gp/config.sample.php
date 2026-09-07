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

    // Server-side cache directory. Must be writable and OUTSIDE public_html.
    // Falls back to the system temp dir when this path is not writable.
    'cache_dir'   => dirname($_SERVER['DOCUMENT_ROOT']) . '/gp-cache',

    // Only these origins may call the proxy. Empty array = same-origin only.
    'allow_origins' => [],

    // Set true while wiring things up: echoes upstream errors to the browser.
    // MUST be false in production — upstream errors can name internal detail.
    'debug'       => false,
];
