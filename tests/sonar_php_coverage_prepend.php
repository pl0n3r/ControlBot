<?php
declare(strict_types=1);

if (!function_exists('xdebug_start_code_coverage')) {
    fwrite(STDERR, "Xdebug coverage no está disponible.\n");
    exit(86);
}

xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

register_shutdown_function(static function (): void {
    $dir = getenv('CONTROLBOT_PHP_COVERAGE_DIR');
    if (!is_string($dir) || $dir === '') {
        return;
    }

    $root = realpath(dirname(__DIR__));
    if ($root === false) {
        return;
    }

    $srcPrefix = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
    $filtered = [];

    foreach (xdebug_get_code_coverage() as $file => $lines) {
        $real = realpath((string) $file);
        if ($real === false || !str_starts_with($real, $srcPrefix) || !is_array($lines)) {
            continue;
        }

        $filtered[$real] = $lines;
    }

    if ($filtered === []) {
        return;
    }

    $payload = json_encode($filtered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $tmp = tempnam($dir, 'coverage-');
    if ($tmp === false) {
        throw new RuntimeException('No se pudo crear fragmento de cobertura PHP.');
    }

    if (file_put_contents($tmp, $payload, LOCK_EX) === false || !rename($tmp, $tmp . '.json')) {
        throw new RuntimeException('No se pudo persistir fragmento de cobertura PHP.');
    }
});
