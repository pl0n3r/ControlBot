<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use ControlBot\Project\ProjectModel;
use InvalidArgumentException;
use RuntimeException;

final class GitHubProjectSnapshot
{
    private const LIMIT=100;
    private const PR_DETAIL_LIMIT = 20;
    private const REVIEW_STATES = ['APPROVED', 'CHANGES_REQUESTED', 'COMMENTED', 'DISMISSED', 'PENDING'];
    private const STATES=['queued','in_progress','completed','waiting','requested','pending'];
    private const CONCLUSIONS=['success','failure','neutral','cancelled','skipped','timed_out','action_required','stale','startup_failure'];

    public function __construct(private readonly ApiClient $api) {}

    public function project(array $rawProject,int $observedAt): array
    {
        if($observedAt<1) throw new InvalidArgumentException('observed_at invalid.');
        $project=ProjectModel::normalize($rawProject);
        $repositories=[];
        foreach($project['repositories'] as $repository)$repositories[]=$this->repository($repository,$observedAt);
        return ['version'=>1,'project_id'=>$project['project_id'],'observed_at'=>$observedAt,'repositories'=>$repositories];
    }

    private function repository(array $repository,int $observedAt): array
    {
        $name=$repository['repository']; $base=Gateway::repoPath($name);
        $branch=$this->api->json('GET',$base.'/branches/main',null,[200]);
        $sha=self::sha(self::object($branch['commit']??null,'main.commit')['sha']??null,'main.sha');
        $checks=$this->checks($this->api->json('GET',$base.'/commits/'.$sha.'/check-runs',null,[200],['per_page'=>self::LIMIT]));
        $prs = $this->pullRequests(
            $this->api->json(
                'GET',
                $base . '/pulls',
                null,
                [200],
                ['state' => 'open', 'per_page' => self::LIMIT]
            ),
            $base
        );
        $issues=$this->issues($this->api->json('GET',$base.'/issues',null,[200],['state'=>'open','per_page'=>self::LIMIT]));
        $release=$this->release($this->api->json('GET',$base.'/releases',null,[200],['per_page'=>1]));
        $workflow=$this->workflow($this->api->json('GET',$base.'/actions/runs',null,[200],['branch'=>'main','per_page'=>1]));
        return [
            'repository_id'=>$repository['repository_id'],'repository'=>$name,'source_ref'=>$repository['source_ref'],
            'observed_at'=>$observedAt,'main_sha'=>$sha,'checks'=>$checks,'pull_requests'=>$prs,'issues'=>$issues,
            'latest_release'=>$release,'latest_workflow'=>$workflow,
        ];
    }

    private function checks(array $payload): array
    {
        $total=self::natural($payload['total_count']??null,'checks.total_count',true);
        $rows=self::rows($payload['check_runs']??null,'checks.check_runs'); $items=[];
        foreach($rows as $row){
            $row=self::object($row,'check');
            $status=self::enum($row['status']??null,self::STATES,'check.status');
            $conclusion=self::nullableEnum($row['conclusion']??null,self::CONCLUSIONS,'check.conclusion');
            self::terminalPair($status,$conclusion,'Check');
            $items[]=['name'=>self::text($row['name']??null,'check.name',200),'status'=>$status,'conclusion'=>$conclusion];
        }
        if($total<count($items)) throw new RuntimeException('Check count invalid.');
        return ['items'=>$items,'truncated'=>$total>count($items)||count($rows)>=self::LIMIT];
    }

    private function pullRequests(array $rows, string $base): array
    {
        $rows = self::rows($rows, 'pull_requests');
        $items = [];
        $partial = count($rows) >= self::LIMIT || count($rows) > self::PR_DETAIL_LIMIT;

        foreach ($rows as $index => $row) {
            $row = self::object($row, 'pull_request');

            if (!is_bool($row['draft'] ?? null)) {
                throw new RuntimeException('Pull request draft invalid.');
            }

            $number = self::natural($row['number'] ?? null, 'pull_request.number');
            $reviewState = 'unknown';
            $mergeability = 'unknown';

            if ($index < self::PR_DETAIL_LIMIT) {
                try {
                    $detail = self::object(
                        $this->api->json('GET', $base . '/pulls/' . $number, null, [200]),
                        'pull_request.detail'
                    );
                    $reviews = $this->api->json(
                        'GET',
                        $base . '/pulls/' . $number . '/reviews',
                        null,
                        [200],
                        ['per_page' => self::LIMIT]
                    );
                    $mergeability = self::mergeability($detail['mergeable'] ?? null);
                    $reviewState = self::reviewState($reviews);

                    if ($mergeability === 'unknown' || $reviewState === 'unknown') {
                        $partial = true;
                    }
                } catch (\Throwable) {
                    $partial = true;
                }
            }

            $items[] = [
                'number' => $number,
                'title' => self::text($row['title'] ?? null, 'pull_request.title', 300),
                'draft' => $row['draft'],
                'head_sha' => self::sha(
                    self::object($row['head'] ?? null, 'pull_request.head')['sha'] ?? null,
                    'pull_request.head_sha'
                ),
                'base_ref' => self::ref(
                    self::object($row['base'] ?? null, 'pull_request.base')['ref'] ?? null,
                    'pull_request.base_ref'
                ),
                'review_state' => $reviewState,
                'mergeability' => $mergeability,
            ];
        }

        return ['items' => $items, 'truncated' => $partial];
    }

    private static function mergeability(mixed $value): string
    {
        if ($value === true) {
            return 'mergeable';
        }
        if ($value === false) {
            return 'conflicting';
        }
        if ($value === null) {
            return 'unknown';
        }

        throw new RuntimeException('Pull request mergeability invalid.');
    }

    private static function reviewState(array $rows): string
    {
        $rows = self::rows($rows, 'pull_request.reviews');

        if ($rows === []) {
            return 'pending';
        }
        if (count($rows) >= self::LIMIT) {
            return 'unknown';
        }

        $latest = [];
        foreach ($rows as $row) {
            $row = self::object($row, 'pull_request.review');
            $state = self::enum(
                $row['state'] ?? null,
                self::REVIEW_STATES,
                'pull_request.review.state'
            );
            $user = self::object($row['user'] ?? null, 'pull_request.review.user');
            $reviewer = self::text(
                $user['login'] ?? null,
                'pull_request.review.user.login',
                100
            );
            $latest[$reviewer] = $state;
        }

        foreach ([
            'CHANGES_REQUESTED' => 'changes_requested',
            'APPROVED' => 'approved',
            'COMMENTED' => 'commented',
            'DISMISSED' => 'dismissed',
            'PENDING' => 'pending',
        ] as $raw => $normalized) {
            if (in_array($raw, array_values($latest), true)) {
                return $normalized;
            }
        }

        return 'unknown';
    }

    private function issues(array $rows): array
    {
        $rows=self::rows($rows,'issues'); $items=[];
        foreach($rows as $row){
            $row=self::object($row,'issue'); if(array_key_exists('pull_request',$row))continue;
            $labels=self::rows($row['labels']??null,'issue.labels',50); $names=[];
            foreach($labels as $label)$names[]=self::text(self::object($label,'issue.label')['name']??null,'issue.label.name',100);
            sort($names,SORT_STRING);
            $items[]=['number'=>self::natural($row['number']??null,'issue.number'),'title'=>self::text($row['title']??null,'issue.title',300),'labels'=>$names];
        }
        return ['items'=>$items,'truncated'=>count($rows)>=self::LIMIT];
    }

    private function release(array $rows): ?array
    {
        $rows=self::rows($rows,'releases',1); if($rows===[])return null;
        $row=self::object($rows[0],'release');
        if(!is_bool($row['draft']??null)||!is_bool($row['prerelease']??null))throw new RuntimeException('Release flags invalid.');
        return ['tag_name'=>self::ref($row['tag_name']??null,'release.tag_name'),'draft'=>$row['draft'],'prerelease'=>$row['prerelease']];
    }

    private function workflow(array $payload): ?array
    {
        $total=self::natural($payload['total_count']??null,'workflow.total_count',true);
        $rows=self::rows($payload['workflow_runs']??null,'workflow.runs',1);
        if($total<count($rows))throw new RuntimeException('Workflow count invalid.');
        if($rows===[])return null;
        $row=self::object($rows[0],'workflow');
        if(($row['head_branch']??null)!=='main')throw new RuntimeException('Workflow scope invalid.');
        $status=self::enum($row['status']??null,self::STATES,'workflow.status');
        $conclusion=self::nullableEnum($row['conclusion']??null,self::CONCLUSIONS,'workflow.conclusion');
        self::terminalPair($status,$conclusion,'Workflow');
        return [
            'name'=>self::text($row['name']??null,'workflow.name',200),'status'=>$status,'conclusion'=>$conclusion,
            'head_sha'=>self::sha($row['head_sha']??null,'workflow.head_sha'),
            'run_number'=>self::natural($row['run_number']??null,'workflow.run_number'),
        ];
    }

    private static function terminalPair(string $status,?string $conclusion,string $label): void
    { if(($status==='completed')!==($conclusion!==null))throw new RuntimeException($label.' status/conclusion ambiguous.'); }

    private static function rows(mixed $value,string $label,int $limit=self::LIMIT): array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>$limit)throw new RuntimeException($label.' invalid.');
        return $value;
    }

    private static function object(mixed $value,string $label): array
    { if(!is_array($value)||array_is_list($value))throw new RuntimeException($label.' invalid.'); return $value; }

    private static function sha(mixed $value,string $label): string
    { if(!is_string($value)||preg_match('/^[0-9a-f]{40}$/D',$value)!==1)throw new RuntimeException($label.' invalid.'); return $value; }

    private static function natural(mixed $value,string $label,bool $zero=false): int
    { if(!is_int($value)||$value<($zero?0:1)||$value>1_000_000_000)throw new RuntimeException($label.' invalid.'); return $value; }

    private static function text(mixed $value,string $label,int $max): string
    {
        if(!is_string($value)||trim($value)===''||strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value)===1)
            throw new RuntimeException($label.' invalid.');
        return trim($value);
    }

    private static function ref(mixed $value,string $label): string
    {
        if(!is_string($value)||trim($value)===''||strlen($value)>200||preg_match('/[\x00-\x20\x7f]/',$value)===1)
            throw new RuntimeException($label.' invalid.');
        return $value;
    }

    private static function enum(mixed $value,array $allowed,string $label): string
    { if(!is_string($value)||!in_array($value,$allowed,true))throw new RuntimeException($label.' invalid.'); return $value; }

    private static function nullableEnum(mixed $value,array $allowed,string $label): ?string
    { return $value===null?null:self::enum($value,$allowed,$label); }
}
