<?php
declare(strict_types=1);
require __DIR__.'/../src/GlobalSearchCore.php';
require __DIR__.'/../src/GlobalSearchIndex.php';
use ControlBot\Search\GlobalSearchIndex;

function rejected(callable $f): bool { try{$f();return false;}catch(InvalidArgumentException){return true;} }
function doc(int $n,string $source='github',string $access='allow',string $fresh='fresh',string $title='Capacity incident',string $repo='pl0n3r/ControlBot'): array {
    $name=explode('/',$repo)[1];$url=$source==='github'?"https://github.com/pl0n3r/$name/issues/$n":"/$source/$n";
    return ['version'=>1,'type'=>'incident','title'=>$title,'project'=>'controlbot','repo'=>$repo,'number_or_id'=>$n,
        'state'=>'open','updated_at'=>100+$n,'snippet'=>'capacity signal','source'=>$source,'canonical_url'=>$url,
        'freshness'=>$fresh,'roles'=>['seguridad','sre'],'access'=>$access];
}
function up(string $id,array $d): array { return ['op'=>'upsert','source_identity'=>$id,'document'=>$d]; }
function del(string $id): array { return ['op'=>'delete','source_identity'=>$id]; }
function batch(string $source,int $watermark,array $changes): array { return compact('source','watermark','changes'); }
function q(string $text='capacity'): array { return ['version'=>1,'text'=>$text,'project'=>null,'type'=>null,'state'=>null,'role'=>null,'page'=>1,'per_page'=>100]; }
function apply(array $s,array $b): array { return GlobalSearchIndex::applyBatch($s,$b); }

$case=$argv[1]??'';
if($case==='incremental'){
    $b=batch('github',1,[up('issue:1',doc(1))]);$a=apply(GlobalSearchIndex::emptyState(),$b);
    $out=['retry'=>$a===apply($a,$b),'watermark'=>apply($a,batch('github',2,[up('issue:2',doc(2))]))['watermarks']['github']['value']];
}elseif($case==='invalid'){
    $a=apply(GlobalSearchIndex::emptyState(),batch('github',2,[up('issue:1',doc(1))]));
    $out=['stale'=>rejected(fn()=>apply($a,batch('github',1,[del('issue:1')]))),
        'conflict'=>rejected(fn()=>apply($a,batch('github',2,[del('issue:1')]))),
        'source'=>rejected(fn()=>apply(GlobalSearchIndex::emptyState(),batch('factory',1,[up('x',doc(3))])))];
}elseif($case==='rename'){
    $a=apply(GlobalSearchIndex::emptyState(),batch('github',1,[up('issue:78',doc(78,title:'Old capacity'))]));
    $b=apply($a,batch('github',2,[up('issue:78',doc(78,title:'Renamed capacity',repo:'pl0n3r/factory'))]));
    $out=['count'=>count($b['entities']),'entity'=>$b['entities']['github|issue:78']];
}elseif($case==='reindex'){
    $g1=batch('github',1,[up('issue:1',doc(1)),up('issue:2',doc(2))]);$g2=batch('github',2,[del('issue:1')]);
    $f=batch('factory',1,[up('lesson:9',doc(9,'factory'))]);$a=apply(apply(apply(GlobalSearchIndex::emptyState(),$g1),$f),$g2);
    $rev=batch('github',1,[up('issue:2',array_reverse(doc(2),true)),up('issue:1',array_reverse(doc(1),true))]);
    $b=apply(apply(apply(GlobalSearchIndex::emptyState(),$f),$rev),$g2);
    $out=['fingerprint'=>GlobalSearchIndex::fingerprint($a)===GlobalSearchIndex::fingerprint($b),
        'retry'=>$a===apply($a,$g2),'factory'=>$a['watermarks']['factory']['value'],'exists'=>isset($a['entities']['factory|lesson:9'])];
}elseif($case==='query'){
    $s=apply(GlobalSearchIndex::emptyState(),batch('github',1,[up('a',doc(1)),up('b',doc(2,access:'deny')),up('c',doc(3,fresh:'stale'))]));
    $out=GlobalSearchIndex::search($s,q());
}elseif($case==='performance'){
    $c=[];for($i=1;$i<=200;$i++)$c[]=up("issue:$i",doc($i));$s=apply(GlobalSearchIndex::emptyState(),batch('github',1,$c));$t=[];
    for($i=0;$i<25;$i++){ $x=hrtime(true);GlobalSearchIndex::search($s,q());$t[]=(hrtime(true)-$x)/1e6; } sort($t);
    $src=strtolower(file_get_contents(__DIR__.'/../src/GlobalSearchIndex.php'));$hits=[];
    foreach(['new pdo','mysqli','curl_','shell_exec','proc_open','file_put_contents','fopen(','exec('] as $x)if(str_contains($src,$x))$hits[]=$x;
    $out=['p95'=>$t[(int)floor(.95*(count($t)-1))],'hits'=>$hits];
}else{fwrite(STDERR,"scenario invalid\n");exit(2);}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
