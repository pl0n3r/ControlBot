<?php
declare(strict_types=1);

namespace ControlBot\Runtime;

use ControlBot\Production\ProductionOperation;

final class PauseProductionGate
{
    public static function evaluate(array $context,array $pauseStates,string $operationId): array
    {
        $operation=ProductionOperation::fromId($operationId);
        $mutation=$operation->effect()==='write';
        $effective=PauseControl::effective($context,$pauseStates,$mutation);
        $pauseBlocked=$mutation&&$effective['blocked'];

        $reason=$pauseBlocked
            ? 'pause_blocked'
            : ($effective['blocked'] ? 'typed_read_during_pause' : 'pause_clear');

        $decision=[
            'version'=>1,
            'operation_id'=>$operation->operationId(),
            'capability'=>$operation->capability(),
            'effect'=>$operation->effect(),
            'pause_allows'=>!$pauseBlocked,
            'requires_existing_authority'=>true,
            'authorization'=>'not_granted',
            'reason'=>$reason,
            'effective_pause'=>[
                'scope'=>$effective['effective_scope'],
                'pause_id'=>$effective['effective_pause_id'],
                'state'=>$effective['effective_state'],
            ],
        ];
        return $decision+['fingerprint'=>self::fingerprint($decision)];
    }

    private static function fingerprint(array $value): string
    {
        return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }
}
