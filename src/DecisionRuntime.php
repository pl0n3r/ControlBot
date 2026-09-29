<?php
declare(strict_types=1);

namespace ControlBot\Decisions;

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Approvals\ApprovalEndpoint;
use ControlBot\Approvals\HumanGate;
use ControlBot\Business\VentureAccessRuntime;
use ControlBot\Business\VentureAccessSource;
use ControlBot\Business\VentureAccessSourceContract;
use ControlBot\GitHub\ApiClient;
use ControlBot\GitHub\ApiTransport;
use ControlBot\GitHub\Gateway;
use ControlBot\GitHub\GateSource;
use ControlBot\GitHub\HumanGateSource;
use ControlBot\Security\OwnerSessionService;
use InvalidArgumentException;
use RuntimeException;

final class DecisionRuntime
{
    private const TRACKING_KEY = '_controlbot_release_tracking';
    private const VENTURE_PAGE_SIZE = 100;
    private const VENTURE_MAX_PAGES = 10;
    private const VENTURE_LABEL = 'factory-human-gate';
    private const VENTURE_LEDGER_REPOSITORY = '__controlbot_venture__/idempotency';
    private const VENTURE_LEDGER_ACTOR_PREFIX = 'venture-idem:';
    private const VENTURE_TRUSTED_AUTHORS = ['OWNER', 'MEMBER', 'COLLABORATOR'];
    private array $repositories;

    public function __construct(
        private readonly OwnerSessionService $sessions,
        private readonly ApprovalEndpoint $approvals,
        private readonly AppendOnlyAuditLog $audit,
        private readonly \Closure $githubFactory,
        array $repositories,
        private readonly ?DecisionConversationProvider $conversationProvider = null,
        private readonly ?VentureAccessSource $ventureAccessSource = null,
    ) {
        if ($repositories === [] || count($repositories) > 20) {
            throw new InvalidArgumentException('Allowlist de repositorios inválida.');
        }
        $normalized = [];
        foreach ($repositories as $repository) {
            if (!is_string($repository)) {
                throw new InvalidArgumentException('Allowlist de repositorios inválida.');
            }
            Gateway::repoPath($repository);
            $normalized[$repository] = true;
        }
        $this->repositories = array_keys($normalized);
    }

    public static function fromServer(
        OwnerSessionService $sessions,
        AppendOnlyAuditLog $audit,
        array $repositories,
        ?VentureAccessSource $ventureAccessSource = null,
    ): self {
        $factory = static function (string $token): array {
            $api = new ApiClient($token, new ApiTransport());
            return [
                'api' => $api,
                'gateway' => new Gateway($api),
                'source' => new GateSource($api),
            ];
        };

        return new self(
            $sessions,
            new ApprovalEndpoint($sessions, $audit, $factory),
            $audit,
            $factory,
            $repositories,
            null,
            $ventureAccessSource,
        );
    }

    public function handle(
        string $method,
        string $path,
        array &$session,
        array $request,
        int $now,
    ): array {
        $method = strtoupper($method);
        if ($method === 'GET' && $path === '/decisions') {
            return self::response(200, 'text/html; charset=utf-8', $this->render($session, $now));
        }
        if ($method === 'GET' && $path === '/decisions/history') {
            $this->sessions->githubToken($session);
            $repository = self::optionalFilter($request, 'repository');
            $category = self::optionalFilter($request, 'category');
            $history = (new DecisionHistory($this->audit, $this->repositories))->load($repository, $category);
            return self::jsonResponse(200, ['history' => $history]);
        }
        if ($method === 'POST' && $path === '/approvals/execute') {
            return self::jsonResponse(200, $this->approve($session, $request, $now));
        }
        if ($method === 'POST' && $path === '/approvals/batch') {
            return self::jsonResponse(200, $this->approveBatch($session, $request, $now));
        }
        if ($method === 'POST' && $path === '/decisions/question') {
            return self::jsonResponse(200, $this->askQuestion($session, $request, $now));
        }
        if ($method === 'GET' && $path === '/release/status') {
            return self::jsonResponse(200, $this->safeReleaseStatus($session));
        }
        return self::jsonResponse(404, ['error' => 'not-found']);
    }

    public function resolveVentureAccess(
        array &$session,
        string $repository,
        array $request,
        int $now,
    ): array {
        if (!in_array($repository, $this->repositories, true)) {
            throw new InvalidArgumentException('Repositorio fuera de la allowlist runtime.');
        }
        $this->sessions->githubToken($session);
        if ($this->ventureAccessSource === null) {
            throw new RuntimeException('Venture access source no configurado.');
        }
        $query = VentureAccessSourceContract::query($request);
        $resolved = $this->ventureAccessSource->resolve(
            $query['identity_id'],
            $query['scope'],
            $query['capability'],
            $now,
        );
        return VentureAccessSourceContract::resolved($resolved, $query, $now);
    }

    public function executeVentureAccess(
        array &$session,
        string $repository,
        array $state,
        array $request,
        array $trusted,
        int $now,
    ): array {
        if (!in_array($repository, $this->repositories, true)) {
            throw new InvalidArgumentException('Repositorio fuera de la allowlist runtime.');
        }

        // Writer authority is resolved server-side before lifecycle evaluation.
        $components = $this->components($this->sessions->githubToken($session));
        $result = VentureAccessRuntime::execute($state, $request, $trusted, $now);
        $result['owner_decision'] = null;

        $gateBody = $result['owner_decision_gate'] ?? null;
        if ($gateBody === null) {
            return $result;
        }
        if (!is_string($gateBody) || $gateBody === '') {
            throw new RuntimeException('Owner Decision inválida.');
        }

        $gate = HumanGate::fromIssueBody($gateBody);
        if (
            $gate->category !== 'product-direction'
            || $gate->safeDefault !== 'B'
            || $gate->recommendation !== 'B'
        ) {
            throw new RuntimeException('Owner Decision fuera del contrato Venture.');
        }

        $result['owner_decision'] = $this->materializeVentureDecision(
            $components['api'],
            $repository,
            $request,
            $gateBody,
            $now,
        );
        return $result;
    }

    private function render(array $session, int $now): string
    {
        $decisions = $this->loadDecisions($session);
        $csrf = $this->sessions->csrfToken($session);
        $reauthenticated = false;
        try {
            $this->sessions->contextFromRequest($session, ['_csrf' => $csrf], $now);
            $reauthenticated = true;
        } catch (RuntimeException) {
            $reauthenticated = false;
        }
        return DecisionUi::render(
            $decisions,
            $reauthenticated,
            $csrf,
            DecisionBatch::eligible($decisions),
            DecisionQuestions::forUi($session),
            $this->conversationProvider !== null,
        );
    }

    private function loadDecisions(array $session): array
    {
        $components = $this->components($this->sessions->githubToken($session));
        return (new GateInbox($components['api'], $components['gateway']))->load($this->repositories);
    }

    private function askQuestion(array &$session, array $request, int $now): array
    {
        if (
            array_diff(array_keys($request), ['_csrf', 'repository', 'issue', 'question']) !== []
            || !is_string($request['_csrf'] ?? null)
            || !is_string($request['repository'] ?? null)
            || (!is_int($request['issue'] ?? null)
                && !(is_string($request['issue'] ?? null) && ctype_digit($request['issue'])))
            || !is_string($request['question'] ?? null)
        ) {
            throw new InvalidArgumentException('Solicitud de pregunta inválida.');
        }

        $repository = $request['repository'];
        $issue = (int) $request['issue'];
        if (!in_array($repository, $this->repositories, true) || $issue < 1) {
            throw new InvalidArgumentException('Decisión fuera de allowlist runtime.');
        }

        $this->sessions->contextFromRequest($session, ['_csrf' => $request['_csrf']], $now);
        $decisions = $this->loadDecisions($session);

        return (new DecisionQuestions($this->conversationProvider))->ask(
            $session,
            $decisions,
            $repository,
            $issue,
            $request['question'],
            $now,
        );
    }

    private function approveBatch(array &$session, array $request, int $now): array
    {
        if (
            array_diff(array_keys($request), ['_csrf']) !== []
            || !is_string($request['_csrf'] ?? null)
        ) {
            throw new InvalidArgumentException('Solicitud de lote inválida.');
        }

        $this->sessions->contextFromRequest($session, ['_csrf' => $request['_csrf']], $now);
        $decisions = $this->loadDecisions($session);

        return DecisionBatch::execute(
            $decisions,
            function (array $entry) use (&$session, $request, $now): array {
                return $this->approve($session, [
                    '_csrf' => $request['_csrf'],
                    'repository' => $entry['repository'],
                    'issue' => (string) $entry['issue'],
                    'option' => $entry['option'],
                    'displayed_sha' => $entry['displayed_sha'],
                ], $now);
            },
        );
    }

    private function approve(array &$session, array $request, int $now): array
    {
        $repository = $request['repository'] ?? null;
        if (!is_string($repository) || !in_array($repository, $this->repositories, true)) {
            throw new InvalidArgumentException('Repositorio fuera de la allowlist runtime.');
        }

        $result = $this->approvals->execute($session, $request, $now);
        if (
            ($result['category'] ?? null) === 'factory-release'
            && ($result['option'] ?? null) === 'A'
            && is_string($result['sha'] ?? null)
        ) {
            $session[self::TRACKING_KEY] = [
                'repository' => $repository,
                'workflow' => 'release-bootstrap.yml',
                'sha' => $result['sha'],
                'dispatched_at' => $now,
            ];
            $result['release'] = $this->safeReleaseStatus($session);
        }
        return $result;
    }

    private function releaseStatus(array &$session): array
    {
        $tracking = $session[self::TRACKING_KEY] ?? null;
        if ($tracking === null) {
            return ['state' => 'idle', 'terminal' => true, 'run_url' => null];
        }
        if (
            !is_array($tracking)
            || !is_string($tracking['repository'] ?? null)
            || !in_array($tracking['repository'], $this->repositories, true)
            || !is_string($tracking['workflow'] ?? null)
            || !is_string($tracking['sha'] ?? null)
            || !is_int($tracking['dispatched_at'] ?? null)
        ) {
            throw new RuntimeException('Correlación server-side inválida.');
        }

        $components = $this->components($this->sessions->githubToken($session));
        $status = (new ReleaseRunTracker($components['api']))->status(
            $tracking['repository'],
            $tracking['workflow'],
            $tracking['sha'],
            $tracking['dispatched_at'],
        );
        if (($status['terminal'] ?? false) === true) {
            unset($session[self::TRACKING_KEY]);
        }
        return $status;
    }

    private function safeReleaseStatus(array &$session): array
    {
        try {
            return $this->releaseStatus($session);
        } catch (RuntimeException) {
            return ['state' => 'blocked', 'terminal' => false, 'run_url' => null];
        }
    }

    private function materializeVentureDecision(ApiClient $api, string $repository, array $request, string $gateBody, int $now): array
    {
        $key=self::ventureTrackingKey($repository,$request); $marker=self::ventureMarker($request);
        $ledger=$this->ventureLedgerState($key,$repository);
        if (($ledger['status']??null)==='finalized') return $ledger['evidence']+['created'=>false];
        if (($ledger['status']??null)==='pending') {
            $existing=$this->findVentureDecision($api,$repository,$marker);
            if ($existing===null) throw new RuntimeException('Owner Decision pendiente sin evidencia recuperable.');
            $this->recordVentureFinalized($key,$existing,$now); return $existing+['created'=>false];
        }
        $existing=$this->findVentureDecision($api,$repository,$marker);
        if ($existing!==null) { $this->recordVentureFinalized($key,$existing,$now); return $existing+['created'=>false]; }

        $claim=bin2hex(random_bytes(8)); $this->recordVenturePending($key,$claim,$now);
        $claimed=$this->ventureLedgerState($key,$repository);
        if (($claimed['status']??null)!=='pending'||($claimed['claim']??null)!==$claim) {
            $existing=$this->findVentureDecision($api,$repository,$marker);
            if ($existing===null) throw new RuntimeException('Owner Decision reclamada por otra ejecución.');
            $this->recordVentureFinalized($key,$existing,$now); return $existing+['created'=>false];
        }

        $body=$gateBody."\n".$marker;
        if (strlen($body)>65536) throw new RuntimeException('Owner Decision excede límite de Issue.');
        $created=$api->json('POST',Gateway::repoPath($repository).'/issues',[
            'title'=>'Venture access owner decision: '.$request['command_id'],
            'body'=>$body,'labels'=>[self::VENTURE_LABEL],
        ],[201]);
        $evidence=self::ventureIssueEvidence($repository,$created);
        $this->recordVentureFinalized($key,$evidence,$now);
        return $evidence+['created'=>true];
    }

    private function findVentureDecision(ApiClient $api, string $repository, string $marker): ?array
    {
        $match=null; $path=Gateway::repoPath($repository).'/issues';
        for ($page=1;$page<=self::VENTURE_MAX_PAGES;$page++) {
            $issues=$api->json('GET',$path,null,[200],[
                'state'=>'all','labels'=>self::VENTURE_LABEL,'per_page'=>self::VENTURE_PAGE_SIZE,'page'=>$page,
            ]);
            if (!array_is_list($issues)||count($issues)>self::VENTURE_PAGE_SIZE) throw new RuntimeException('Página de Owner Decisions inválida.');
            foreach ($issues as $issue) {
                if (!is_array($issue)||isset($issue['pull_request'])||!is_string($issue['body']??null)
                    ||!str_contains($issue['body'],$marker)
                    ||!in_array($issue['author_association']??null,self::VENTURE_TRUSTED_AUTHORS,true)) continue;
                $gate=HumanGate::fromIssueBody($issue['body']);
                if ($gate->category!=='product-direction'||$gate->safeDefault!=='B'||$gate->recommendation!=='B') continue;
                $candidate=self::ventureIssueEvidence($repository,$issue);
                if ($match!==null&&$match['issue']!==$candidate['issue']) throw new RuntimeException('Owner Decision idempotente ambigua.');
                $match=$candidate;
            }
            if (count($issues)<self::VENTURE_PAGE_SIZE) return $match;
        }
        throw new RuntimeException('Búsqueda de Owner Decision excede límite defensivo.');
    }

    private static function ventureTrackingKey(string $repository, array $request): string
    {
        foreach (['command_id','idempotency_key'] as $field)
            if (!is_string($request[$field]??null)||preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,79}$/D',$request[$field])!==1)
                throw new InvalidArgumentException('Identificador Venture inválido.');
        return hash('sha256',$repository."\0".$request['command_id']."\0".$request['idempotency_key']);
    }

    private static function ventureMarker(array $request): string
    {
        return '<!-- venture-access-materialization '.json_encode([
            'version'=>1,'command_id'=>$request['command_id'],'idempotency_key'=>$request['idempotency_key'],
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).' -->';
    }

    private function ventureLedgerState(string $key, string $repository): ?array
    {
        $prefix=self::VENTURE_LEDGER_ACTOR_PREFIX.$key.':'; $pending=null; $finalized=null;
        foreach ($this->audit->entries() as $entry) {
            $actor=$entry['actor']??null;
            if (!is_string($actor)||!str_starts_with($actor,$prefix)) continue;
            if (($entry['repository']??null)!==self::VENTURE_LEDGER_REPOSITORY||($entry['action']??null)!=='comment'
                ||($entry['category']??null)!=='product-direction'||($entry['option']??null)!=='B'
                ||($entry['sha']??null)!==null||!is_int($entry['at']??null)||$entry['at']<1)
                throw new RuntimeException('Ledger Venture inválido.');
            $suffix=substr($actor,strlen($prefix));
            if (($entry['result']??null)==='blocked') {
                if (preg_match('/^[0-9a-f]{16}$/D',$suffix)!==1||($entry['issue']??null)!==1||($entry['evidence']??null)!==null)
                    throw new RuntimeException('Ledger Venture pending inválido.');
                $pending??=$suffix; continue;
            }
            if (($entry['result']??null)!=='success'||$suffix!=='final') throw new RuntimeException('Ledger Venture final inválido.');
            $candidate=self::ventureIssueEvidence($repository,['number'=>$entry['issue']??null,'html_url'=>$entry['evidence']??null]);
            if ($finalized!==null&&$finalized['issue']!==$candidate['issue']) throw new RuntimeException('Ledger Venture final ambiguo.');
            $finalized=$candidate;
        }
        if ($finalized!==null) return ['status'=>'finalized','evidence'=>$finalized];
        return $pending===null?null:['status'=>'pending','claim'=>$pending];
    }

    private function recordVenturePending(string $key, string $claim, int $now): void
    {
        if ($now<1||preg_match('/^[0-9a-f]{16}$/D',$claim)!==1) throw new RuntimeException('Claim Venture inválido.');
        $this->audit->record([
            'actor'=>self::VENTURE_LEDGER_ACTOR_PREFIX.$key.':'.$claim,'action'=>'comment',
            'repository'=>self::VENTURE_LEDGER_REPOSITORY,'issue'=>1,'category'=>'product-direction',
            'option'=>'B','sha'=>null,'result'=>'blocked','evidence'=>null,'at'=>$now,
        ]);
    }

    private function recordVentureFinalized(string $key, array $evidence, int $now): void
    {
        if ($now<1) throw new RuntimeException('Timestamp Venture inválido.');
        $this->audit->record([
            'actor'=>self::VENTURE_LEDGER_ACTOR_PREFIX.$key.':final','action'=>'comment',
            'repository'=>self::VENTURE_LEDGER_REPOSITORY,'issue'=>$evidence['issue'],'category'=>'product-direction',
            'option'=>'B','sha'=>null,'result'=>'success','evidence'=>$evidence['issue_url'],'at'=>$now,
        ]);
    }

    private static function ventureIssueEvidence(string $repository, array $issue): array
    {
        $number=$issue['number']??null; $url=$issue['html_url']??null;
        if (!is_int($number)||$number<1||!is_string($url)
            ||!str_starts_with($url,'https://github.com/'.$repository.'/issues/')||preg_match('/[\r\n]/',$url)===1)
            throw new RuntimeException('Evidencia GitHub de Owner Decision inválida.');
        return ['repository'=>$repository,'issue'=>$number,'issue_url'=>$url];
    }

    private function components(string $token): array
    {
        $components = ($this->githubFactory)($token);
        if (
            !is_array($components)
            || !($components['api'] ?? null) instanceof ApiClient
            || !($components['gateway'] ?? null) instanceof Gateway
            || !($components['source'] ?? null) instanceof HumanGateSource
        ) {
            throw new RuntimeException('Integración GitHub runtime inválida.');
        }
        return $components;
    }

    private static function optionalFilter(array $request, string $key): ?string
    {
        if (!array_key_exists($key, $request) || $request[$key] === '') {
            return null;
        }
        if (!is_string($request[$key]) || strlen($request[$key]) > 120 || preg_match('/[\r\n]/', $request[$key]) === 1) {
            throw new InvalidArgumentException('Filtro de historial inválido.');
        }
        return $request[$key];
    }

    private static function response(int $status, string $contentType, string $body): array
    {
        return ['status' => $status, 'content_type' => $contentType, 'body' => $body];
    }

    private static function jsonResponse(int $status, array $payload): array
    {
        return self::response(
            $status,
            'application/json; charset=utf-8',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }
}
