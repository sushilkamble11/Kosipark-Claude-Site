<?php
/**
 * Proxy configuration for the integration harness.
 *
 * Every value here is fake and points at test/harness/fake-guestpoint.php.
 * No real credential belongs in this file — the harness never talks to
 * GuestPoint. test/proxy.test.mjs copies it to public_html/api/gp/config.php
 * for the duration of a run and restores whatever was there afterwards.
 *
 * HARNESS_PORT and HARNESS_CACHE are substituted by the runner.
 */
return [
    'api_key'            => 'harness-be-key',
    'core_api_key'       => 'harness-core-key',
    'property_id'        => 'PROP-HARNESS',
    'upstream'           => 'http://127.0.0.1:{{PORT}}/be',
    'core_upstream'      => 'http://127.0.0.1:{{PORT}}/core',
    'pms_upstream'       => 'http://127.0.0.1:{{PORT}}/pms',
    'pms_private_writes' => true,
    'pms_serial'         => 'HARNESS-SERIAL',
    'pms_username'       => 'harness-user',
    'pms_password'       => 'harness-password',
    'cache_dir'          => '{{CACHE}}',
    'debug'              => true,
];
