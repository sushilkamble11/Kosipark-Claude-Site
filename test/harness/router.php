<?php
/**
 * Front controller for the harness copy of the site.
 *
 * `php -S ... -t public_html test/harness/router.php` serves the static site
 * and hands anything under /api/gp to the real proxy with PATH_INFO set the
 * way Hostinger's LiteSpeed sets it.
 */
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (str_starts_with($path, '/api/gp')) {
    $_SERVER['PATH_INFO'] = substr($path, strlen('/api/gp'));
    require dirname(__DIR__, 2) . '/public_html/api/gp/index.php';
    return true;
}
return false;
