<?php
declare(strict_types=1);
require __DIR__ . '/../src/FactoryOrchestratorFieldSafety.php';

use ControlBot\Business\FactoryOrchestratorFieldSafety as Guard;

function validProjection(): array
{
    return [
        'central' => ['source_ref' => 'canonical-offline'],
        'fronts' => [['id'=>'front-001', 'source_ref'=>'synthetic-source']],
        'owner_decisions' => [[
            'id'=>'decision-001', 'source_ref'=>'synthetic-source',
            'format'=>'structured', 'title'=>'Decision', 'title_simple'=>'Summary',
            'summary_simple'=>'Context', 'explain_simple'=>null,
            'why_recommended'=>'Reason', 'blocks'=>'Stage',
            'options'=>[['id'=>'A', 'label'=>'A label', 'effect'=>'Some effect',
                'pros'=>['Good'], 'cons'=>['Tradeoff'], 'risk'=>'Low',
                'cost'=>null, 'explain_simple'=>null]],
        ]],
    ];
}

function accepts(array $view): bool
{
    try { Guard::assertProjection($view); return true; }
    catch (InvalidArgumentException) { return false; }
}

if (($argv[1] ?? '') === 'shape') {
    $changes = [
        'valid_empty_collections' => static function (array $v): array { $v['fronts'] = []; $v['owner_decisions'] = []; return $v; },
        'valid_central_unknown' => static function (array $v): array { $v['central']['source_ref'] = null; return $v; },
        'valid_legacy' => static function (array $v): array { $v['owner_decisions'][0]['format'] = 'legacy'; $v['owner_decisions'][0]['options'] = []; return $v; },
        'valid_fronts_24' => static function (array $v): array {
            $v['fronts'] = array_map(static fn(int $i): array =>
                ['id' => 'front-'.$i, 'source_ref' => 'synthetic-source'], range(1, 24));
            return $v;
        },
        'bad_fronts_25' => static function (array $v): array {
            $v['fronts'] = array_map(static fn(int $i): array =>
                ['id' => 'front-'.$i, 'source_ref' => 'synthetic-source'], range(1, 25));
            return $v;
        },
        'valid_decisions_100' => static function (array $v): array {
            $prototype = $v['owner_decisions'][0];
            $v['owner_decisions'] = array_map(static function (int $i) use ($prototype): array {
                $row = $prototype; $row['id'] = 'decision-'.$i; return $row;
            }, range(1, 100));
            return $v;
        },
        'bad_decisions_101' => static function (array $v): array {
            $prototype = $v['owner_decisions'][0];
            $v['owner_decisions'] = array_map(static function (int $i) use ($prototype): array {
                $row = $prototype; $row['id'] = 'decision-'.$i; return $row;
            }, range(1, 101));
            return $v;
        },
        'bad_central_list' => static function (array $v): array { $v['central'] = []; return $v; },
        'bad_central_pii' => static function (array $v): array { $v['central']['source_ref'] = 'reader@example.test'; return $v; },
        'bad_fronts_object' => static function (array $v): array { $v['fronts'] = ['x' => $v['fronts'][0]]; return $v; },
        'bad_front_list' => static function (array $v): array { $v['fronts'][0] = []; return $v; },
        'bad_decisions_object' => static function (array $v): array { $v['owner_decisions'] = ['x' => $v['owner_decisions'][0]]; return $v; },
        'bad_decision_scalar' => static function (array $v): array { $v['owner_decisions'][0] = 'invalid'; return $v; },
        'bad_structured_options_empty' => static function (array $v): array { $v['owner_decisions'][0]['options'] = []; return $v; },
        'bad_structured_options_seven' => static function (array $v): array { $v['owner_decisions'][0]['options'] = array_fill(0, 7, $v['owner_decisions'][0]['options'][0]); return $v; },
        'bad_option_scalar' => static function (array $v): array { $v['owner_decisions'][0]['options'][0] = 'invalid'; return $v; },
        'bad_option_pros_object' => static function (array $v): array { $v['owner_decisions'][0]['options'][0]['pros'] = ['x' => 'Good']; return $v; },
        'bad_option_cons_seven' => static function (array $v): array { $v['owner_decisions'][0]['options'][0]['cons'] = array_fill(0, 7, 'Tradeoff'); return $v; },
        'bad_option_explain_control' => static function (array $v): array { $v['owner_decisions'][0]['options'][0]['explain_simple'] = "bad\ncontrol"; return $v; },
        'bad_required_title_null' => static function (array $v): array { $v['owner_decisions'][0]['title'] = null; return $v; },
    ];
    $result = [];
    foreach ($changes as $name => $modify) $result[$name] = accepts($modify(validProjection()));
    $positives = ['valid_empty_collections', 'valid_central_unknown', 'valid_legacy', 'valid_fronts_24', 'valid_decisions_100'];
    $unexpected = array_keys(array_filter($result, static fn(bool $actual, string $name): bool => $actual !== in_array($name, $positives, true), ARRAY_FILTER_USE_BOTH));
    echo json_encode(['total'=>count($result), 'positives'=>count($positives), 'unexpected'=>$unexpected, 'cases'=>$result], JSON_THROW_ON_ERROR), PHP_EOL;
    exit($unexpected ? 1 : 0);
}

$cases = [];
$base = validProjection();
$cases['valid_projection'] = accepts($base);
$cases['valid_600_effect'] = accepts((static function () use ($base): array {
    $base['owner_decisions'][0]['options'][0]['effect']=str_repeat('x',600); return $base;
})());
$cases['valid_200_label'] = accepts((static function () use ($base): array {
    $base['owner_decisions'][0]['options'][0]['label']=str_repeat('x',200); return $base;
})());
$cases['valid_300_pro'] = accepts((static function () use ($base): array {
    $base['owner_decisions'][0]['options'][0]['pros']=[str_repeat('x',300)]; return $base;
})());
$cases['valid_160_title'] = accepts((static function () use ($base): array {
    $base['owner_decisions'][0]['title']=str_repeat('x',160); return $base;
})());
$cases['valid_161_byte_id'] = accepts((static function () use ($base): array {
    $base['fronts'][0]['id']='f'.str_repeat('x',160); return $base;
})());
$changes = [
    'id_241_bytes' => ['fronts', 'id', str_repeat('f',241)],
    'email_id' => ['fronts', 'id', 'viewer@example.test'],
    'sensitive_id' => ['fronts', 'id', 'front-token-123'],
    'sensitive_front_ref' => ['fronts', 'source_ref', 'synthetic-token-marker'],
    'email_front_ref' => ['fronts', 'source_ref', 'viewer@example.test'],
    'phone_front_ref' => ['fronts', 'source_ref', '555-123-4567'],
    'original_length_241_front_ref' => ['fronts', 'source_ref', str_repeat('x',239).'  '],
    'decision_id_email' => ['owner_decisions', 'id', 'viewer@example.test'],
    'decision_source_token' => ['owner_decisions', 'source_ref', 'synthetic-token'],
    'decision_title_161' => ['owner_decisions', 'title', str_repeat('x',161)],
    'title_simple_161' => ['owner_decisions', 'title_simple', str_repeat('x',161)],
    'summary_simple_601' => ['owner_decisions', 'summary_simple', str_repeat('x',601)],
    'label_201' => ['options', 'label', str_repeat('x',201)],
    'effect_601' => ['options', 'effect', str_repeat('x',601)],
    'risk_81' => ['options', 'risk', str_repeat('x',81)],
    'cost_241' => ['options', 'cost', str_repeat('x',241)],
    'option_explain_401' => ['options', 'explain_simple', str_repeat('x',401)],
    'pros_item_301' => ['options', 'pros', [str_repeat('x',301)]],
    'cons_item_301' => ['options', 'cons', [str_repeat('x',301)]],
    'pros_seven' => ['options', 'pros', array_fill(0,7,'x')],
    'pros_control' => ['options', 'pros', ["line\nbreak"]],
];
foreach ($changes as $name=>[$level, $field, $value]) {
    $v = validProjection();
    if ($level === 'fronts') $v['fronts'][0][$field] = $value;
    elseif ($level === 'owner_decisions') $v['owner_decisions'][0][$field] = $value;
    else $v['owner_decisions'][0]['options'][0][$field] = $value;
    $cases[$name] = accepts($v);
}
$expectedPositive = ['valid_projection','valid_600_effect','valid_200_label','valid_300_pro','valid_160_title','valid_161_byte_id'];
$unexpected = array_keys(array_filter($cases, static fn(bool $actual, string $name): bool => $actual !== in_array($name,$expectedPositive,true), ARRAY_FILTER_USE_BOTH));
echo json_encode(['cases'=>$cases, 'total'=>count($cases), 'negatives'=>count($cases)-count($expectedPositive), 'unexpected'=>$unexpected, 'pure_no_side_effects'=>true], JSON_THROW_ON_ERROR), PHP_EOL;
if ($unexpected) exit(1);
