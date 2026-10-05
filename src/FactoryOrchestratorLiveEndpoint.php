<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;
use Throwable;

require_once __DIR__ . '/FactoryOrchestratorLiveCache.php';
require_once __DIR__ . '/FactoryOrchestratorLiveUi.php';

final class FactoryOrchestratorLiveEndpoint
{
    public static function handle(array $request, array $config, int $now, callable $refresh): array
    {
        try {
            self::validConfig($config);
            $method = $request['method'] ?? null;
            $path = $request['path'] ?? null;
            $user = $request['remote_user'] ?? null;
            if (!is_string($method) || !is_string($path) || !is_string($user)) {
                throw new InvalidArgumentException('request invalid');
            }
        } catch (Throwable) {
            return self::response(503, "Service unavailable.\n");
        }
        if (!hash_equals($config['owner_login'], $user)) return self::response(403, "Forbidden.\n");
        if ($method !== 'GET') return self::response(405, "Method not allowed.\n", ['Allow' => 'GET']);
        if (!in_array($path, ['/', '/api/orchestrator-live'], true)) return self::response(404, "Not found.\n");
        try {
            $cached = FactoryOrchestratorLiveCache::remember(
                $config['cache_path'], $now, $config['ttl_seconds'],
                $config['stale_seconds'], $config['refresh_budget_seconds'], $refresh,
            );
        } catch (Throwable) {
            return self::response(503, "Service unavailable.\n");
        }
        if ($path === '/api/orchestrator-live') {
            return self::response(200, json_encode([
                'version' => 1, 'read_only' => true, 'cache_status' => $cached['status'],
                'cache_age_seconds' => $cached['age_seconds'], 'snapshot' => $cached['payload'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n", [], 'application/json; charset=utf-8');
        }
        try {
            $html = FactoryOrchestratorLiveUi::render($cached['payload']);
        } catch (Throwable) {
            return self::response(503, "Service unavailable.\n");
        }
        $fp = json_encode($cached['payload']['fingerprint'], JSON_THROW_ON_ERROR);
        $script = '<script>(()=>{const current=' . $fp
            . ';const endpoint="/api/orchestrator-live";'
            . 'document.addEventListener("click",async(event)=>{'
            . 'const target=event.target instanceof Element?event.target:null;'
            . 'const button=target?.closest("[data-copy-command]");if(!button)return;'
            . 'const command=button.getAttribute("data-copy-command");'
            . 'if(typeof command!=="string"||command==="")return;'
            . 'try{await navigator.clipboard.writeText(command);button.textContent="Copiado"}'
            . 'catch(_){button.textContent="Copia manual"}});'
            . 'setInterval(async()=>{try{'
            . 'const r=await fetch(endpoint,{credentials:"same-origin",cache:"no-store"});'
            . 'if(!r.ok)return;const p=await r.json();'
            . 'if(p.snapshot?.fingerprint&&p.snapshot.fingerprint!==current)location.reload()'
            . '}catch(_){}},5000)})();</script>';
        return self::response(200, str_replace('</body>', $script . '</body>', $html), [], 'text/html; charset=utf-8');
    }

    private static function validConfig(array $config): void
    {
        foreach (['owner_login','cache_path'] as $key) {
            if (!is_string($config[$key] ?? null) || $config[$key] === '') throw new InvalidArgumentException('config invalid');
        }
        foreach (['ttl_seconds','stale_seconds','refresh_budget_seconds'] as $key) {
            if (!is_int($config[$key] ?? null)) throw new InvalidArgumentException('config invalid');
        }
    }

    private static function response(int $status, string $body, array $extra = [], string $type = 'text/plain; charset=utf-8'): array
    {
        return ['status' => $status, 'headers' => $extra + [
            'Content-Type' => $type, 'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
        ], 'body' => $body];
    }
}
