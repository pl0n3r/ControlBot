<?php
declare(strict_types=1);
require __DIR__.'/../src/HostingReadPanel.php';
require __DIR__.'/../src/HostingReadUi.php';

use ControlBot\Production\HostingReadUi;

$row=[
    'project'=>'alpha','environment'=>'prod','profile_id'=>'22222222-2222-4222-8222-222222222222',
    'observed_at'=>1790713200,'connection_status'=>'connected','site_status'=>'online',
    'disk'=>'ok','databases'=>'ok','cron'=>'ok','ssl'=>'ok','deploy_status'=>'ok','recent_errors'=>'ok',
    'health'=>'healthy','source'=>'hostinger:ssh','freshness'=>'fresh',
];
$case=$argv[1]??'';
if($case==='render') $out=HostingReadUi::render([$row]);
elseif($case==='stale') {$row['freshness']='stale';$out=['state'=>HostingReadUi::state($row),'view'=>HostingReadUi::render([$row])];}
elseif($case==='source') $out=['source'=>file_get_contents(__DIR__.'/../src/HostingReadUi.php')];
else{fwrite(STDERR,"unknown scenario\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
