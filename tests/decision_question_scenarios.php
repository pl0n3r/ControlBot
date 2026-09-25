<?php
declare(strict_types=1);

require __DIR__ . '/../src/Approvals.php';
require __DIR__ . '/../src/OwnerSession.php';
require __DIR__ . '/../src/GitHub.php';
require __DIR__ . '/../src/ApprovalEndpoint.php';
require __DIR__ . '/../src/GateInbox.php';
require __DIR__ . '/../src/DecisionBatch.php';
require __DIR__ . '/../src/DecisionUi.php';
require __DIR__ . '/../src/DecisionHistory.php';
require __DIR__ . '/../src/DecisionQuestions.php';
require __DIR__ . '/../src/DecisionRuntime.php';

use ControlBot\Approvals\AppendOnlyAuditLog;
use ControlBot\Approvals\ApprovalEndpoint;
use ControlBot\Decisions\DecisionConversationProvider;
use ControlBot\Decisions\DecisionRuntime;
use ControlBot\GitHub\ApiClient;
use ControlBot\GitHub\ApiTransport;
use ControlBot\GitHub\Gateway;
use ControlBot\GitHub\GateSource;
use ControlBot\Security\OwnerSessionService;
use ControlBot\Security\TokenVault;
use ControlBot\Security\Totp;

final class RecordingConversationProvider implements DecisionConversationProvider
{
    public array $calls = [];

    public function __construct(private readonly string $mode) {}

    public function answer(array $context, string $question): string
    {
        $this->calls[] = ['context' => $context, 'question' => $question];
        if ($this->mode === 'failure') {
            throw new RuntimeException('provider unavailable');
        }
        if ($this->mode === 'html') {
            return '<img src=x onerror=alert(1)> respuesta';
        }
        return 'La opción recomendada aplica el cambio reversible de bajo riesgo.';
    }
}

function questionGateBody(): string
{
    $payload = [
        'category' => 'brand',
        'context' => 'Aplicar un cambio visual reversible.',
        'options' => [
            [
                'id' => 'A',
                'label' => 'Aplicar',
                'effect' => 'Actualiza la interfaz.',
                'pros' => ['Mejora claridad'],
                'cons' => ['Cambia presentación'],
                'risk' => 'low',
                'cost' => '',
                'reversible' => true,
            ],
            [
                'id' => 'B',
                'label' => 'Mantener',
                'effect' => 'No cambia la interfaz.',
                'pros' => ['Sin cambio'],
                'cons' => ['No mejora claridad'],
                'risk' => 'low',
                'cost' => '',
                'reversible' => true,
            ],
        ],
        'recommendation' => 'A',
        'safe_default' => 'B',
        'title_simple' => '¿Aplicamos el cambio visual?',
        'summary_simple' => 'Es un cambio reversible y de bajo riesgo.',
        'why_recommended' => 'La interfaz queda más clara.',
    ];
    return '<!-- factory-human-gate '
        . json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        . ' -->';
}

$scenario = $argv[1] ?? '';
$now = strtotime('2026-09-25T10:00:00Z');
$gate = [
    'number' => 66,
    'state' => 'open',
    'author_association' => 'OWNER',
    'body' => questionGateBody(),
    'title' => 'Cambio visual',
    'created_at' => '2026-09-25T09:00:00Z',
    'html_url' => 'https://github.com/pl0n3r/factory/issues/66',
];
$seen = [];

$sender = static function (string $method, string $url, array $headers, ?string $body) use (&$seen, $gate): array {
    $seen[] = [$method, $url];
    $base = 'https://api.github.com/repos/pl0n3r/factory';
    if ($method === 'GET' && $url === $base . '/issues?state=open&per_page=100&page=1') {
        return ['status' => 200, 'body' => json_encode([$gate], JSON_THROW_ON_ERROR)];
    }
    throw new RuntimeException("fixture ausente: {$method} {$url}");
};

$transport = new ApiTransport($sender);
$factory = static function (string $token) use ($transport): array {
    $api = new ApiClient($token, $transport);
    return ['api' => $api, 'gateway' => new Gateway($api), 'source' => new GateSource($api)];
};

$vault = new TokenVault(base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
$sessions = new OwnerSessionService('pl0n3r', $vault);
$session = [];
$sessions->establishTrustedOAuthSession($session, 'pl0n3r', 'fixture-server-secret');
$sessions->reauthenticateTotp(
    $session,
    Totp::code('JBSWY3DPEHPK3PXP', $now),
    'JBSWY3DPEHPK3PXP',
    $now,
);

$mode = match ($scenario) {
    'provider-failure' => 'failure',
    'provider-html' => 'html',
    default => 'success',
};
$provider = $scenario === 'no-provider' ? null : new RecordingConversationProvider($mode);
$auditPath = tempnam(sys_get_temp_dir(), 'controlbot-question-');
$audit = new AppendOnlyAuditLog($auditPath);
$runtime = new DecisionRuntime(
    $sessions,
    new ApprovalEndpoint($sessions, $audit, $factory),
    $audit,
    $factory,
    ['pl0n3r/factory'],
    $provider,
);

$request = [
    '_csrf' => $sessions->csrfToken($session),
    'repository' => 'pl0n3r/factory',
    'issue' => '66',
    'question' => $scenario === 'provider-html'
        ? '<script>alert(1)</script>'
        : '¿Qué cambia si apruebo?',
];
$at = $now;

if ($scenario === 'manipulated-context') {
    $request['issue'] = '999';
}
if ($scenario === 'no-csrf') {
    unset($request['_csrf']);
}
if ($scenario === 'stale-reauth') {
    $at = $now + 301;
}
if ($scenario === 'too-long') {
    $request['question'] = str_repeat('x', 501);
}

try {
    $response = $runtime->handle('POST', '/decisions/question', $session, $request, $at);
    $render = $runtime->handle('GET', '/decisions', $session, [], $at);
    echo json_encode([
        'blocked' => false,
        'response' => json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR),
        'stored' => $session['_controlbot_decision_questions'] ?? [],
        'provider_calls' => $provider instanceof RecordingConversationProvider ? $provider->calls : [],
        'seen' => $seen,
        'render' => $render['body'],
        'session_keys' => array_keys($session),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable) {
    echo json_encode([
        'blocked' => true,
        'stored' => $session['_controlbot_decision_questions'] ?? [],
        'provider_calls' => $provider instanceof RecordingConversationProvider ? $provider->calls : [],
        'seen' => $seen,
        'session_keys' => array_keys($session),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
} finally {
    @unlink($auditPath);
}
