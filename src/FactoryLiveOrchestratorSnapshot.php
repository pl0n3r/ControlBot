<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

final class FactoryLiveOrchestratorSnapshot
{
    private const REPOS = [
        'pl0n3r/Factory', 'pl0n3r/Condor', 'pl0n3r/GrindFlow', 'pl0n3r/brvtal',
        'pl0n3r/ControlBot', 'pl0n3r/AutoFactory', 'pl0n3r/FactoryRunner',
    ];
    private const FRONT = ['available', 'reserved', 'in_review', 'blocked', 'merged', 'unknown'];
    private const SENSITIVE =
        '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';
    private const PII =
        '/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?(?=(?:[0-9(). -]*[0-9]){10})[0-9][0-9(). -]{7,}[0-9])/i';

    public static function build(array $snapshot, int $now): array
    {
        self::canonical($snapshot, $now);
        $central = self::central($snapshot['work_inventory'] ?? null, $now);
        $fronts = self::fronts($snapshot['sections']['work'], $now);
        $decisions = self::decisions($snapshot['sections']['owner_decisions'], $now);
        $out = [
            'version' => 1, 'observed_at' => $now, 'source_snapshot' => $snapshot['fingerprint'],
            'read_only' => true, 'central' => $central, 'fronts' => $fronts, 'owner_decisions' => $decisions,
        ];
        if (array_key_exists('agent_activity', $snapshot)) {
            $out['agent_activity'] = $snapshot['agent_activity'];
        }
        if (array_key_exists('signal_summary', $snapshot)) {
            $out['signal_summary'] = $snapshot['signal_summary'];
        }
        $encoded = json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return $out + ['fingerprint' => hash('sha256', $encoded)];
    }

    private static function canonical(array $snapshot, int $now): void
    {
        $invalidShape =
            ($snapshot['version'] ?? null) !== 1
            || ! is_int($snapshot['observed_at'] ?? null)
            || $snapshot['observed_at'] < 1
            || $snapshot['observed_at'] > $now
            || ! is_array($snapshot['sections'] ?? null)
            || array_is_list($snapshot['sections']);
        if ($invalidShape) {
            throw new InvalidArgumentException('Canonical FactoryLiveSnapshot invalid.');
        }

        $expected = ['batches', 'owner_decisions', 'releases', 'blockers', 'production', 'quality', 'work', 'learning'];
        if (array_keys($snapshot['sections']) !== $expected) {
            throw new InvalidArgumentException('FactoryLiveSnapshot sections invalid.');
        }

        $fingerprint = $snapshot['fingerprint'] ?? null;
        if (! is_string($fingerprint) || preg_match('/^[0-9a-f]{64}$/D', $fingerprint) !== 1) {
            throw new InvalidArgumentException('FactoryLiveSnapshot fingerprint invalid.');
        }

        $copy = $snapshot;
        unset($copy['fingerprint']);
        $encoded = json_encode($copy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (! hash_equals(hash('sha256', $encoded), $fingerprint)) {
            throw new InvalidArgumentException('FactoryLiveSnapshot fingerprint mismatch.');
        }
    }

    private static function central(mixed $inventory, int $now): array
    {
        if ($inventory === null) {
            return [
                'activity_state' => 'UNKNOWN', 'available' => null, 'reserved' => null, 'blocked' => null,
                'source_ref' => null, 'observed_at' => null, 'freshness' => 'unknown', 'age_seconds' => null,
            ];
        }

        self::fields($inventory, ['version', 'source_ref', 'observed_at', 'freshness', 'projects'], 'work_inventory');
        if ($inventory['version'] !== 1) {
            throw new InvalidArgumentException('work_inventory.version invalid.');
        }

        $source = self::text($inventory['source_ref'], 'work_inventory.source_ref', 240);
        $observedAt = self::time($inventory['observed_at'], 'work_inventory.observed_at', $now);
        if (! in_array($inventory['freshness'], ['current', 'stale'], true)) {
            throw new InvalidArgumentException('work_inventory.freshness invalid.');
        }

        $projects = $inventory['projects'];
        if (! is_array($projects) || ! array_is_list($projects) || count($projects) !== count(self::REPOS)) {
            throw new InvalidArgumentException('work_inventory.projects invalid.');
        }

        $available = 0;
        $reserved = 0;
        $blocked = 0;
        foreach ($projects as $index => $project) {
            $invalidProject =
                ! is_array($project)
                || array_is_list($project)
                || ($project['repository_ref'] ?? null) !== self::REPOS[$index]
                || ! is_array($project['counts'] ?? null);
            if ($invalidProject) {
                throw new InvalidArgumentException('work_inventory project invalid.');
            }
            $available += self::count($project['counts']['available'] ?? null);
            $reserved += self::count($project['counts']['reserved'] ?? null);
            $blocked += self::count($project['counts']['blocked'] ?? null);
        }

        $state = self::activityState($inventory['freshness'], $available, $reserved, $blocked);
        return [
            'activity_state' => $state, 'available' => $available, 'reserved' => $reserved, 'blocked' => $blocked,
            'source_ref' => $source, 'observed_at' => $observedAt, 'freshness' => $inventory['freshness'],
            'age_seconds' => $now - $observedAt,
        ];
    }

    private static function activityState(string $freshness, int $available, int $reserved, int $blocked): string
    {
        if ($freshness === 'stale') {
            return 'STALE';
        }
        if ($reserved > 0) {
            return 'ACTIVE';
        }
        if ($available > 0) {
            return 'READY';
        }
        if ($blocked > 0) {
            return 'BLOCKED';
        }
        return 'IDLE';
    }

    private static function fronts(mixed $rows, int $now): array
    {
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidArgumentException('work signals invalid.');
        }

        $out = [];
        foreach ($rows as $row) {
            self::signal($row, 'github_project_snapshot', $now);
            if ($row['freshness'] === 'unknown') {
                continue;
            }

            $data = $row['data'] ?? null;
            if (! is_array($data) || array_is_list($data)) {
                throw new InvalidArgumentException('work data invalid.');
            }

            $repository = $data['repository_ref'] ?? null;
            if (! is_string($repository) || ! in_array($repository, self::REPOS, true)) {
                throw new InvalidArgumentException('repository_ref invalid.');
            }

            $status = $data['status'] ?? 'unknown';
            if (! is_string($status) || ! in_array($status, self::FRONT, true)) {
                throw new InvalidArgumentException('work status invalid.');
            }

            [$progress, $progressState] = self::progress($row, $data);
            if ($row['freshness'] === 'stale') {
                $status = 'STALE';
            }

            $out[] = [
                'id' => self::id($row['id']), 'repository_ref' => $repository,
                'issue_ref' => self::issue($data['issue_ref'] ?? null), 'status' => $status,
                'progress_percent' => $progress, 'progress_state' => $progressState,
                'source_ref' => self::text($row['source_ref'], 'work.source_ref', 240),
                'observed_at' => $row['observed_at'], 'freshness' => $row['freshness'],
                'age_seconds' => $now - $row['observed_at'],
            ];
        }

        if (count($out) > 24) {
            throw new InvalidArgumentException('Too many active fronts.');
        }
        usort($out, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        return $out;
    }

    private static function progress(array $row, array $data): array
    {
        if ($row['freshness'] !== 'current' || ! isset($data['progress_percent'], $data['progress_evidence'])) {
            return [null, 'UNKNOWN'];
        }

        $progress = $data['progress_percent'];
        if (! is_int($progress) || $progress < 0 || $progress > 100) {
            throw new InvalidArgumentException('progress invalid.');
        }
        self::text($data['progress_evidence'], 'progress_evidence', 240);
        return [$progress, 'EVIDENCED'];
    }

    private static function decisions(mixed $rows,int $now): array
    {
        if(!is_array($rows)||!array_is_list($rows))throw new InvalidArgumentException('owner_decisions invalid.');
        $out=[];
        foreach($rows as $row){
            self::signal($row,'owner_inbox',$now);
            if($row['freshness']!=='unknown')$out[]=self::decisionProjection($row,$now);
        }
        usort($out,static fn(array $a,array $b):int=>$a['id']<=>$b['id']);
        return $out;
    }

    private static function decisionProjection(array $row,int $now): array
    {
        $data=$row['data']??null;
        if(!is_array($data)||array_is_list($data))throw new InvalidArgumentException('owner decision data invalid.');
        $issue=self::issue($data['issue_ref']??null);
        [$fallbackRepo,$fallbackNumber]=self::decisionIdentity($issue);
        $repo=$data['repository_ref']??$fallbackRepo;
        $number=$data['issue_number']??$fallbackNumber;
        $format=$data['format']??'legacy';
        $valid=in_array($repo,self::REPOS,true)&&is_int($number)&&$number>0
            &&$repo===$fallbackRepo&&$number===$fallbackNumber
            &&in_array($format,['legacy','structured'],true);
        if(!$valid)throw new InvalidArgumentException('owner decision identity invalid.');

        $projection=[
            'id'=>self::id($row['id']),'issue_ref'=>$issue,'repository_ref'=>$repo,'issue_number'=>$number,
            'format'=>$format,'title'=>self::decisionText($data['title']??'Decisión pendiente','decision.title',160),
            'title_simple'=>null,'summary_simple'=>null,'explain_simple'=>null,'why_recommended'=>null,
            'blocks'=>null,'options'=>[],'recommendation'=>null,'safe_default'=>null,
            'expires_at'=>null,'seconds_left'=>null,'expired'=>null,
            'source_ref'=>self::text($row['source_ref'],'decision.source_ref',240),
            'observed_at'=>$row['observed_at'],'freshness'=>$row['freshness'],
            'age_seconds'=>$now-$row['observed_at'],
        ];
        if($format==='legacy')return $projection;

        foreach(['title_simple'=>160,'summary_simple'=>600,'why_recommended'=>600,'blocks'=>600] as $field=>$max)
            $projection[$field]=self::decisionText($data[$field]??null,'decision.'.$field,$max);
        if(($data['explain_simple']??null)!==null)
            $projection['explain_simple']=self::decisionText($data['explain_simple'],'decision.explain_simple',600);
        $projection['options']=self::decisionOptions($data['options']??null);
        $ids=array_column($projection['options'],'id');
        foreach(['recommendation','safe_default'] as $field){
            $value=$data[$field]??null;
            if(!is_string($value)||!in_array($value,$ids,true))
                throw new InvalidArgumentException('decision '.$field.' invalid.');
            $projection[$field]=$value;
        }
        return self::withDecisionExpiry($projection,$data['expires_at']??null,$now);
    }

    private static function withDecisionExpiry(array $projection,mixed $expiresAt,int $now): array
    {
        if($expiresAt===null)return $projection;
        if(!is_string($expiresAt)
            ||preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/D',$expiresAt)!==1
            ||($epoch=strtotime($expiresAt))===false)
            throw new InvalidArgumentException('decision expires_at invalid.');
        $projection['expires_at']=$expiresAt;
        $projection['seconds_left']=max(0,$epoch-$now);
        $projection['expired']=$epoch<=$now;
        return $projection;
    }

    private static function decisionOptions(mixed $raw): array
    {
        if(!is_array($raw)||!array_is_list($raw)||$raw===[]||count($raw)>6)
            throw new InvalidArgumentException('decision options invalid.');
        $out=[];$seen=[];
        foreach($raw as $option){
            $id=is_array($option)&&!array_is_list($option)?($option['id']??null):null;
            if(!is_string($id)||preg_match('/^[A-D]$/D',$id)!==1
                ||isset($seen[$id])||!is_bool($option['reversible']??null))
                throw new InvalidArgumentException('decision option invalid.');
            $seen[$id]=true;
            $out[]=[
                'id'=>$id,'label'=>self::decisionText($option['label']??null,'decision.option.label',200),
                'effect'=>self::decisionText($option['effect']??null,'decision.option.effect',600),
                'pros'=>self::decisionTextList($option['pros']??null,6,300),
                'cons'=>self::decisionTextList($option['cons']??null,6,300),
                'risk'=>self::decisionText($option['risk']??null,'decision.option.risk',80),
                'cost'=>($option['cost']??null)===null?null:self::decisionText($option['cost'],'decision.option.cost',240),
                'reversible'=>$option['reversible'],
                'explain_simple'=>($option['explain_simple']??null)===null
                    ?null:self::decisionText($option['explain_simple'],'decision.option.explain',400),
            ];
        }
        return $out;
    }

    private static function decisionTextList(mixed $raw,int $maxItems,int $maxLength): array
    {
        if(!is_array($raw)||!array_is_list($raw)||count($raw)>$maxItems)throw new InvalidArgumentException('decision list invalid.');
        return array_map(static fn(mixed $value):string=>self::decisionText($value,'decision list item',$maxLength),$raw);
    }

    private static function decisionText(mixed $value,string $label,int $max): string
    {
        if(!is_string($value))throw new InvalidArgumentException($label.' invalid.');$value=trim($value);
        if($value===''||strlen($value)>$max||preg_match('/[\\x00-\\x1f\\x7f]/',$value)===1)throw new InvalidArgumentException($label.' invalid.');
        return $value;
    }

    private static function decisionIdentity(string $issue): array
    {
        if(preg_match('~^github:(pl0n3r/[A-Za-z0-9_.-]+)#([1-9][0-9]*)$~D',$issue,$match)===1)return [$match[1],(int)$match[2]];
        if(preg_match('~^https://github\\.com/(pl0n3r/[A-Za-z0-9_.-]+)/issues/([1-9][0-9]*)$~D',$issue,$match)===1)return [$match[1],(int)$match[2]];
        throw new InvalidArgumentException('owner decision issue invalid.');
    }

    private static function signal(mixed $row, string $authority, int $now): void
    {
        $invalid =
            ! is_array($row)
            || array_is_list($row)
            || ($row['authority'] ?? null) !== $authority
            || ! in_array($row['freshness'] ?? null, ['current', 'stale', 'unknown'], true);
        if ($invalid) {
            throw new InvalidArgumentException('Canonical signal invalid.');
        }

        if ($row['freshness'] === 'unknown') {
            if (($row['source_ref'] ?? null) !== null || ($row['observed_at'] ?? null) !== null) {
                throw new InvalidArgumentException('Unknown signal invalid.');
            }
            return;
        }

        self::text($row['source_ref'] ?? null, 'signal.source_ref', 240);
        self::time($row['observed_at'] ?? null, 'signal.observed_at', $now);
        if ($row['freshness'] === 'stale' && ($row['state'] ?? null) === 'healthy') {
            throw new InvalidArgumentException('Stale signal cannot be healthy.');
        }
    }

    private static function issue(mixed $value): string
    {
        $pattern =
            '~^(?:https://github\.com/pl0n3r/[A-Za-z0-9_.-]+/issues/[1-9][0-9]*'
            . '|github:pl0n3r/[A-Za-z0-9_.-]+#[1-9][0-9]*)$~D';
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException('issue_ref invalid.');
        }
        return self::text($value, 'issue_ref', 240);
    }

    private static function id(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[a-z][a-z0-9._:\/#-]{2,160}$/D', $value) !== 1) {
            throw new InvalidArgumentException('signal id invalid.');
        }
        return self::text($value, 'signal.id', 180);
    }

    private static function text(mixed $value, string $label, int $max): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException($label . ' unsafe.');
        }

        $value = trim($value);
        $invalid =
            $value === ''
            || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match(self::SENSITIVE, $value) === 1
            || preg_match(self::PII, $value) === 1;
        if ($invalid) {
            throw new InvalidArgumentException($label . ' unsafe.');
        }
        return $value;
    }

    private static function time(mixed $value, string $label, int $now): int
    {
        if (! is_int($value) || $value < 1 || $value > $now) {
            throw new InvalidArgumentException($label . ' invalid.');
        }
        return $value;
    }

    private static function count(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > 1_000_000) {
            throw new InvalidArgumentException('count invalid.');
        }
        return $value;
    }

    private static function fields(mixed $row, array $expected, string $label): void
    {
        if (! is_array($row) || array_is_list($row)) {
            throw new InvalidArgumentException($label . ' invalid.');
        }

        $actual = array_keys($row);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException($label . ' fields invalid.');
        }
    }
}
