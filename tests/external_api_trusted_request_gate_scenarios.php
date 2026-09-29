<?php
declare(strict_types=1);
require __DIR__.'/../src/ExternalApiRequestGate.php';
use ControlBot\ExternalApi\ExternalApiRequestGate;
$r=new ReflectionMethod(ExternalApiRequestGate::class,'authorize');
$types=array_map(static fn(ReflectionParameter $p): string=>(string)$p->getType(),$r->getParameters());
echo json_encode(['types'=>$types,'source'=>file_get_contents(__DIR__.'/../src/ExternalApiRequestGate.php')],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
