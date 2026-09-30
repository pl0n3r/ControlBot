<?php

declare(strict_types=1);

$coverageDir = getenv('CONTROLBOT_PHP_COVERAGE_DIR');
$repoRoot = getenv('CONTROLBOT_REPO_ROOT');

if (
    !is_string($coverageDir)
    || $coverageDir === ''
    || !is_string($repoRoot)
    || $repoRoot === ''
    || !function_exists('xdebug_start_code_coverage')
) {
    return;
}

if (!is_dir($coverageDir) && !mkdir($coverageDir, 0777, true) && !is_dir($coverageDir)) {
    throw new RuntimeException('No fue posible crear el directorio de cobertura PHP.');
}

xdebug_set_filter(XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_INCLUDE, [$repoRoot]);
xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

register_shutdown_function(
    static function () use ($coverageDir): void {
        $coverage = xdebug_get_code_coverage();
        if ($coverage === []) {
            return;
        }

        $name = sprintf(
            '%d-%s.json',
            getmypid(),
            bin2hex(random_bytes(6)),
        );
        $payload = json_encode($coverage, JSON_THROW_ON_ERROR);
        if (
            file_put_contents(
                $coverageDir.DIRECTORY_SEPARATOR.$name,
                $payload,
                LOCK_EX,
            ) === false
        ) {
            throw new RuntimeException('No fue posible persistir cobertura PHP.');
        }
    },
);
