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
