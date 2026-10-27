<?php
declare(strict_types=1);

/**
 * ControlBot #976: synthetic-only viewport fixture bridge.
 * The canonical UI fixture provides real rendered HTML without network or writes.
 * Width selection is evaluated against emitted media queries by the Python test.
 */
$viewport = $argv[1] ?? '';
$scenario = $argv[2] ?? 'full';
if (!in_array($viewport, ['360', '390', '1280'], true)
    || !in_array($scenario, ['full', 'empty', 'stale', 'incoherent', 'hostile'], true)) {
    fwrite(STDERR, "Invalid synthetic scenario or viewport\n");
    exit(2);
}
$argv = [__DIR__.'/factory_live_ui_scenarios.php', $scenario];
require __DIR__.'/factory_live_ui_scenarios.php';
