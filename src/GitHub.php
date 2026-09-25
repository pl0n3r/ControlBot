<?php
declare(strict_types=1);

namespace ControlBot\GitHub;

use ControlBot\Approvals\GitHubGateway;
use ControlBot\Approvals\HumanGate;
use InvalidArgumentException;
use RuntimeException;

interface HumanGateSource
{
    public function load(string $repository, int $issue): HumanGate;
}

final class ApiTransport
{
    private const ORIGIN = 'https://api.github.com';

    public function __construct(private readonly ?\Closure $sender = null) {}

    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') !== 'api.github.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['query']) && (strlen($parts['query']) > 2048 || preg_match('/[\\r\\n]/', $parts['query']) === 1))
            || !is_string($parts['path'] ?? null)
            || !str_starts_with($parts['path'], '/repos/')
        ) {
            throw new RuntimeException('Destino GitHub no permitido.');
        }

        if ($this->sender !== null) {
            $result = ($this->sender)($method, $url, $headers, $body);
            if (!is_array($result) || !isset($result['status'], $result['body'])) {
                throw new RuntimeException('Respuesta de transporte inválida.');
            }
            return $result;
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('Transporte HTTPS no disponible.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('No fue posible iniciar GitHub HTTPS.');
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => 5000,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL => true,
        ]);
        $response = @curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);
        if ($response === false) {
            throw new RuntimeException('Falló la conexión con GitHub.');
        }
        return ['status' => $status, 'body' => (string) $response];
    }

    public static function url(string $path, array $query = []): string
    {
        if (!str_starts_with($path, '/repos/') || str_contains($path, '..') || str_contains($path, '?')) {
            throw new InvalidArgumentException('Ruta GitHub no permitida.');
        }
        if (count($query) > 20) {
            throw new InvalidArgumentException('Query GitHub demasiado grande.');
        }

        $normalized = [];
        foreach ($query as $key => $value) {
            if (
                !is_string($key)
                || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1
                || (!is_string($value) && !is_int($value))
            ) {
                throw new InvalidArgumentException('Parámetro GitHub inválido.');
            }
            $text = (string) $value;
            if (strlen($text) > 200 || preg_match('/[\\r\\n]/', $text) === 1) {
                throw new InvalidArgumentException('Valor GitHub inválido.');
            }
            $normalized[$key] = $text;
        }

        $suffix = $normalized === []
            ? ''
            : '?' . http_build_query($normalized, '', '&', PHP_QUERY_RFC3986);
        return self::ORIGIN . $path . $suffix;
    }
}

final class ApiClient
{
    public function __construct(
        private readonly string $token,
        private readonly ApiTransport $transport,
    ) {
        if ($token === '' || strlen($token) > 4096 || preg_match('/[\r\n]/', $token) === 1) {
            throw new InvalidArgumentException('Token GitHub inválido.');
        }
    }

    public function json(string $method, string $path, ?array $payload, array $expected, array $query = []): array
    {
        $url = ApiTransport::url($path, $query);
        $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $this->token,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: ControlBot/0.1',
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $response = $this->transport->request($method, $url, $headers, $body);
        $status = (int) $response['status'];
        if (!in_array($status, $expected, true)) {
            throw new RuntimeException("GitHub API rechazó la operación (HTTP {$status}).");
        }
        if ($status === 204 || trim((string) $response['body']) === '') {
            return [];
        }
        $decoded = json_decode((string) $response['body'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Respuesta GitHub inválida.');
        }
        return $decoded;
    }
}

final class Gateway implements GitHubGateway
{
    public function __construct(private readonly ApiClient $api) {}

    public function mainSha(string $repository): string
    {
        $data = $this->api->json('GET', self::repoPath($repository) . '/branches/main', null, [200]);
        $sha = $data['commit']['sha'] ?? null;
        if (!is_string($sha) || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new RuntimeException('GitHub no devolvió un SHA main válido.');
        }
        return $sha;
    }

    public function commentIssue(string $repository, int $issue, string $body): string
    {
        self::issue($issue);
        $data = $this->api->json('POST', self::repoPath($repository) . "/issues/{$issue}/comments", ['body' => $body], [201]);
        return self::evidence($data);
    }

    public function closeIssue(string $repository, int $issue): string
    {
        self::issue($issue);
        $data = $this->api->json('PATCH', self::repoPath($repository) . "/issues/{$issue}", ['state' => 'closed'], [200]);
        return self::evidence($data);
    }

    public function moveTag(string $repository, string $tag, string $sha): string
    {
        if ($tag !== 'v1' || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            throw new InvalidArgumentException('Tag o SHA no permitido.');
        }
        $data = $this->api->json('PATCH', self::repoPath($repository) . '/git/refs/tags/v1', ['sha' => $sha, 'force' => true], [200]);
        return self::evidence($data, 'ref');
    }

    public function dispatchWorkflow(string $repository, string $workflow, array $inputs): string
    {
        if (preg_match('/^[A-Za-z0-9_.-]+\.ya?ml$/', $workflow) !== 1) {
            throw new InvalidArgumentException('Workflow no permitido.');
        }
        $this->api->json(
            'POST',
            self::repoPath($repository) . '/actions/workflows/' . rawurlencode($workflow) . '/dispatches',
            ['ref' => 'main', 'inputs' => $inputs],
            [204]
        );
        return 'https://github.com/' . self::repository($repository) . '/actions/workflows/' . rawurlencode($workflow);
    }

    public static function repoPath(string $repository): string
    {
        return '/repos/' . self::repository($repository);
    }

    private static function repository(string $repository): string
    {
        if (preg_match('/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/', $repository) !== 1 || str_contains($repository, '..')) {
            throw new InvalidArgumentException('Repositorio GitHub inválido.');
        }
        [$owner, $name] = explode('/', $repository, 2);
        return rawurlencode($owner) . '/' . rawurlencode($name);
    }

    private static function issue(int $issue): void
    {
        if ($issue < 1) {
            throw new InvalidArgumentException('Issue inválido.');
        }
    }

    private static function evidence(array $data, string $fallback = 'html_url'): string
    {
        $value = $data['html_url'] ?? $data[$fallback] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('GitHub no devolvió evidencia de la operación.');
        }
        return $value;
    }
}

final class GateSource implements HumanGateSource
{
    private const TRUSTED = ['OWNER', 'MEMBER', 'COLLABORATOR'];

    public function __construct(private readonly ApiClient $api) {}

    public function load(string $repository, int $issue): HumanGate
    {
        if ($issue < 1) {
            throw new InvalidArgumentException('Issue inválido.');
        }
        $data = $this->api->json('GET', Gateway::repoPath($repository) . "/issues/{$issue}", null, [200]);
        if (
            ($data['state'] ?? null) !== 'open'
            || !in_array($data['author_association'] ?? null, self::TRUSTED, true)
            || !is_string($data['body'] ?? null)
        ) {
            throw new RuntimeException('La puerta GitHub no es confiable o ya no está abierta.');
        }
        return HumanGate::fromIssueBody($data['body']);
    }
}
