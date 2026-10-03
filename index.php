<?php
declare(strict_types=1);

$root = realpath(__DIR__);
$publicEntrypoint = realpath(__DIR__ . '/public/index.php');
$expectedPublicDir = $root === false ? null : $root . DIRECTORY_SEPARATOR . 'public';

if (
    $root === false
    || $publicEntrypoint === false
    || ! is_file($publicEntrypoint)
    || dirname($publicEntrypoint) !== $expectedPublicDir
) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo "Service unavailable.\n";
    exit;
}

require $publicEntrypoint;
