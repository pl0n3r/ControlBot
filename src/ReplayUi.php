<?php
declare(strict_types=1);

namespace ControlBot\Replay;

require_once __DIR__.'/UiTheme.php';
require_once __DIR__.'/AgentReplayCore.php';
require_once __DIR__.'/AgentReplayLifecycle.php';

use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class ReplayUi
{
    private const CATEGORIES=['code','ci','coordination','decisions','production','security'];

    public static function render(array $rows,string $filter='all'): string
    {
        if($filter!=='all'&&!in_array($filter,self::CATEGORIES,true))
            throw new InvalidArgumentException('Replay UI filter invalid.');
        if(!array_is_list($rows)||$rows===[]||count($rows)>256)
            throw new InvalidArgumentException('Replay UI rows invalid.');

        $entries=[];$categoryByVariant=[];$eventCategories=[];
        foreach($rows as $row){
            self::fields($row,['category','stage','event']);
            if(!is_string($row['category'])||!in_array($row['category'],self::CATEGORIES,true)
                ||!is_string($row['stage'])||!is_array($row['event']))
                throw new InvalidArgumentException('Replay UI row invalid.');
            $event=AgentReplayCore::event($row['event']);
            $key=hash('sha256',serialize($event));
            if(isset($categoryByVariant[$key])&&$categoryByVariant[$key]!==$row['category'])
                throw new InvalidArgumentException('Replay UI category binding conflict.');
            $categoryByVariant[$key]=$row['category'];
            $eventCategories[$event['event_id']][$row['category']]=true;
            $entries[]=['stage'=>$row['stage'],'event'=>$event];
        }

        $lifecycle=AgentReplayLifecycle::build($entries);
        $items='';
        foreach($lifecycle['stages'] as $stage){
            foreach($stage['events'] as $event){
                $category=$categoryByVariant[hash('sha256',serialize($event))]??null;
                if($category===null) throw new InvalidArgumentException('Replay UI category binding missing.');
                if($filter!=='all'&&$filter!==$category) continue;
                $items.=self::eventRow($category,$stage['name'],$event);
            }
        }
        foreach($lifecycle['auxiliary_evidence'] as $entry){
            $event=$entry['event'];
            $category=$categoryByVariant[hash('sha256',serialize($event))]??null;
            if($category===null) throw new InvalidArgumentException('Replay UI category binding missing.');
            if($filter!=='all'&&$filter!==$category) continue;
            $items.=self::eventRow($category,$entry['stage'],$event);
        }
        if($items==='') $items='<p class="empty">Sin evidencia para este filtro.</p>';

        $conflicts='';
        foreach($lifecycle['conflicts'] as $conflict){
            if($filter!=='all'&&!isset($eventCategories[$conflict['event_id']][$filter])) continue;
            $conflicts.='<li><strong>unknown</strong> · '.self::e($conflict['event_id'])
                .' · '.self::e($conflict['reason']).'</li>';
        }
        if($conflicts==='') $conflicts='<li>Sin conflictos materializados.</li>';

        $filters='<nav aria-label="Filtros de replay">';
        foreach(array_merge(['all'],self::CATEGORIES) as $category){
            $active=$category===$filter?' aria-current="page"':'';
            $filters.='<a'.$active.' href="?category='.self::e($category).'">'.self::e($category).'</a>';
        }
        $filters.='</nav>';

        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
            .'<title>ControlBot · Replay</title><style>'.self::styles().'</style></head><body><main class="shell">'
            .'<header><p class="eyebrow">CONTROLBOT / AGENT REPLAY</p><h1>Replay verificable</h1>'
            .'<p>WorkItem <code>'.self::e($lifecycle['work_item_id']).'</code></p></header>'
            .$filters.'<section class="events" aria-label="Evidencia">'.$items.'</section>'
            .'<section class="panel" aria-labelledby="conflicts-title"><h2 id="conflicts-title">Conflictos</h2><ul>'.$conflicts.'</ul></section>'
            .'</main></body></html>';
    }

    private static function eventRow(string $category,string $stage,array $event): string
    {
        return '<article class="panel event" data-category="'.self::e($category).'">'
            .'<p class="meta">'.self::e($category).' · '.self::e($stage).' · <strong>'.self::e($event['kind']).'</strong></p>'
            .'<h2>'.self::e($event['event_id']).'</h2>'
            .'<p>Actor: <code>'.self::e($event['actor_type'].':'.$event['actor_ref']).'</code></p>'
            .'<p>'.self::e($event['summary']).'</p><p>'.self::evidence($event['evidence_ref']).'</p></article>';
    }

    private static function evidence(string $ref): string
    {
        if(str_starts_with($ref,'https://github.com/'))
            return '<a href="'.self::e($ref).'">Ver evidencia</a>';
        return '<code>'.self::e($ref).'</code>';
    }

    private static function fields(mixed $row,array $expected): void
    {
        if(!is_array($row)||array_is_list($row)) throw new InvalidArgumentException('Replay UI row invalid.');
        $actual=array_keys($row);sort($actual,SORT_STRING);sort($expected,SORT_STRING);
        if($actual!==$expected) throw new InvalidArgumentException('Replay UI row fields invalid.');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }

    private static function styles(): string
    {
        return UiTheme::tokensCss().<<<'CSS'
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,sans-serif}.shell{width:min(100%,1050px);margin:auto;padding:24px 16px 48px}.eyebrow,.meta,code{font-family:"JetBrains Mono",ui-monospace,monospace}.eyebrow{color:var(--cyan)}nav{display:flex;gap:8px;overflow-x:auto;padding:16px 0}nav a{color:var(--text);border:1px solid var(--line);border-radius:999px;padding:8px 10px;text-decoration:none;white-space:nowrap}nav a[aria-current="page"]{border-color:var(--cyan);color:var(--cyan)}a:focus-visible{outline:2px solid var(--amber);outline-offset:3px}.events{display:grid;grid-template-columns:1fr;gap:12px}.panel{min-width:0;padding:15px;border:1px solid var(--line);border-radius:10px;background:var(--panel);margin-top:12px}.meta{color:var(--muted)}h1,h2,p,code{overflow-wrap:anywhere}.empty{color:var(--muted)}@media(min-width:760px){.shell{padding:40px 28px 64px}.events{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
CSS;
    }
}
