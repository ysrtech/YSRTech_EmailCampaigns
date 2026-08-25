<?php
// Router for `php -S`. Without this, PHP's built-in server serves any file that
// exists on disk verbatim — including app/etc/local.xml (DB credentials) and other
// paths .htaccess would normally block. Deny those explicitly, then fall through
// to normal static-file / index.php handling.
$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

$denyPrefixes = ['/app/', '/lib/', '/var/', '/shell/', '/bbscripts/', '/.git/'];
foreach ($denyPrefixes as $prefix) {
    if (str_starts_with($path, $prefix)) {
        http_response_code(403);
        echo 'Forbidden';
        return true;
    }
}

$docRoot = $_SERVER['DOCUMENT_ROOT'];
$file = $docRoot . $path;
if ($path !== '/' && is_file($file)) {
    return false; // serve the static file (skin/js/media/etc.) as-is
}

require $docRoot . '/index.php';
