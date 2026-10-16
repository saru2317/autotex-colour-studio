<?php
declare(strict_types=1);
// Default layout: /home/CPANEL_USER/public_html/colour-studio and /home/CPANEL_USER/autotex-private.
$privatePath = getenv('AUTOTEX_PRIVATE_DIR') ?: dirname(__DIR__, 2) . '/autotex-private';
$privateReal = realpath($privatePath);
$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);
if ($privateReal === false || !is_file($privateReal . '/bootstrap.php')) {
    http_response_code(503);
    exit('Autotex private files were not found. Follow INSTALL.html and check paths.php.');
}
// Fail closed if confidential storage has accidentally been placed in the web root.
if ($documentRoot !== false && ($privateReal === $documentRoot || str_starts_with($privateReal, rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
    http_response_code(503);
    exit('The autotex-private folder must be outside the public document root.');
}
define('AT_PRIVATE', $privateReal);
define('AT_PUBLIC', __DIR__);
require AT_PRIVATE . '/bootstrap.php';
