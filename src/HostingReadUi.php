<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;

final class HostingReadUi
{
    public static function render(array $snapshots): array
    {
        $rows=HostingReadPanel::aggregate($snapshots);
        return [
            'version'=>1,'title'=>'Hostinger','mode'=>'read_only',
            'empty'=>$rows===[],'rows'=>$rows,
            'columns'=>['project','environment','connection_status','site_status','health','freshness','observed_at','source'],
        ];
    }

    public static function state(array $snapshot): string
    {
        foreach(['health','freshness','observed_at','source'] as $key)
            if(!array_key_exists($key,$snapshot)) throw new InvalidArgumentException('Snapshot UI incompleto.');
        if($snapshot['health']==='critical') return 'error';
        if($snapshot['health']==='degraded'||$snapshot['freshness']!=='fresh') return 'warning';
        return 'ready';
    }
}
