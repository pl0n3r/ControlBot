<?php
declare(strict_types=1);

require __DIR__ . '/../src/DecisionBatch.php';

use ControlBot\Decisions\DecisionBatch;

function decision(int $issue, string $category, ?string $risk, string $recommendation = 'A'): array {
    $a=['id'=>'A','label'=>'Aprobar'];
    if ($risk !== null) $a['risk']=$risk;
    return [
        'repository'=>'pl0n3r/demo','issue'=>$issue,'title'=>"Decisión {$issue}",'category'=>$category,
        'options'=>[$a,['id'=>'B','label'=>'No aprobar','risk'=>'low']],
        'recommendation'=>$recommendation,
    ];
}

$scenario=$argv[1]??'';
if ($scenario==='eligible') {
    $decisions=[
        decision(1,'brand','low'),
        decision(2,'brand','medium'),
        decision(3,'legal','low'),
        decision(4,'brand',null),
        decision(5,'money','low'),
        decision(6,'go-live','low'),
        decision(7,'factory-release','low'),
        decision(8,'real-customer-data','low'),
        decision(9,'release-1.0.0','low'),
    ];
    echo json_encode(['eligible'=>DecisionBatch::eligible($decisions)],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit;
}
if ($scenario==='partial') {
    $calls=[];
    $decisions=[decision(1,'brand','low'),decision(2,'brand','low'),decision(3,'brand','low')];
    $result=DecisionBatch::execute($decisions,static function(array $entry) use (&$calls): array {
        $calls[]=$entry['issue'];
        if ($entry['issue']===2) throw new RuntimeException('fixture failure');
        return ['ok'=>true,'issue'=>$entry['issue']];
    });
    echo json_encode(['result'=>$result,'calls'=>$calls],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
    exit;
}
fwrite(STDERR,"scenario inválido\n");
exit(2);
