<?php
declare(strict_types=1);

require __DIR__.'/../src/WeeklyFocus.php';
require __DIR__.'/../src/WeeklyFocusUi.php';

use ControlBot\Scheduler\WeeklyFocus;
use ControlBot\Scheduler\WeeklyFocusUi;

$focus = WeeklyFocus::normalize([
    'focus_id' => 'weekly-focus-2026-40',
    'week_start' => '2026-09-28',
    'scope' => 'controlbot:scope/owner',
    'ordered_refs' => [
        'controlbot:project/alpha',
        'controlbot:project/beta',
        'controlbot:epic/gamma',
    ],
    'version' => 3,
    'created_at' => 100,
    'updated_at' => 300,
    'updated_by' => 'controlbot:actor/owner',
]);

$states = [
    'controlbot:project/alpha' => ['state' => 'available', 'reason' => null],
    'controlbot:project/beta' => ['state' => 'unavailable', 'reason' => 'blocked'],
    'controlbot:epic/gamma' => ['state' => 'available', 'reason' => null],
];

$view = WeeklyFocusUi::project($focus, $states);
$desktop = WeeklyFocusUi::reorderIntent($focus, [
    'controlbot:epic/gamma',
    'controlbot:project/alpha',
    'controlbot:project/beta',
], 3);
$mobile = WeeklyFocusUi::moveIntent($focus, 'controlbot:epic/gamma', 'up', 3);
$conflict = WeeklyFocusUi::reorderIntent($focus, [
    'controlbot:epic/gamma',
    'controlbot:project/beta',
    'controlbot:project/alpha',
], 2);
$clear = WeeklyFocusUi::clearIntent($focus, 3);
$clearedFocus = WeeklyFocus::normalize(array_replace($focus, [
    'ordered_refs' => [],
    'version' => 4,
    'updated_at' => 400,
]));
$emptyView = WeeklyFocusUi::project($clearedFocus, []);

echo json_encode([
    'view' => $view,
    'desktop' => $desktop,
    'mobile' => $mobile,
    'conflict' => $conflict,
    'clear' => $clear,
    'empty_view' => $emptyView,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
