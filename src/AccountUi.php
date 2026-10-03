<?php
declare(strict_types=1);

namespace ControlBot\Runtime;

require_once __DIR__ . '/AgentRuntime.php';
require_once __DIR__ . '/UiTheme.php';

use ControlBot\Ui\UiTheme;
use InvalidArgumentException;

final class AccountUi
{
    public static function render(array $input): string
    {
        try {
            $view = self::viewModel($input);
        } catch (InvalidArgumentException) {
            return self::document(
                self::stateBlock(
                    'error',
                    'ERROR · Configuración de cuentas inválida.',
                    null
                )
            );
        }

        if ($view['state'] !== 'ready') {
            $copy = [
                'loading' => 'LOADING · Cargando cuentas.',
                'empty' => 'EMPTY · Sin cuentas configuradas.',
                'error' => 'ERROR · No fue posible cargar cuentas.',
            ][$view['state']];
            return self::document(
                self::stateBlock(
                    $view['state'],
                    $copy,
                    $view['message']
                )
            );
        }

        $cards = '';
        foreach ($view['accounts'] as $account) {
            $provider = $view['providers'][$account['provider_id']];
            $cards .= '<article class="account-card" data-account="'
                . self::esc($account['account_id'])
                . '">'
                . '<p class="account-provider">'
                . self::esc($provider['provider_id'])
                . ' · '
                . self::esc($provider['adapter'])
                . '</p>'
                . '<h2>'
                . self::esc($account['account_alias'])
                . '</h2>'
                . '<dl>'
                . self::fact('Account ID', $account['account_id'])
                . self::fact('Plan/configuración', $account['plan'])
                . self::fact(
                    'Capacidad declarada',
                    (string) $account['capacity']
                )
                . self::fact('Estado de cuenta', $account['status'])
                . '</dl>'
                . '<p class="capacity-note">'
                . 'La capacidad declarada es configuración. '
                . 'La disponibilidad operativa se observa en AI Capacity.'
                . '</p>'
                . '</article>';
        }

        return self::document(
            '<header class="accounts-header">'
            . '<p class="section-id">CONTROLBOT / CUENTAS</p>'
            . '<h1>Cuentas</h1>'
            . '<p class="muted">'
            . 'Identidad y configuración Runtime · solo lectura'
            . '</p></header>'
            . '<section class="account-grid" aria-label="Cuentas">'
            . $cards
            . '</section>'
        );
    }

    private static function viewModel(array $input): array
    {
        $keys = array_keys($input);
        sort($keys);
        if (
            array_is_list($input)
            || $keys !== ['accounts', 'message', 'providers', 'state']
        ) {
            throw new InvalidArgumentException('AccountView invalid.');
        }

        $state = $input['state'];
        if (
            !is_string($state)
            || !in_array(
                $state,
                ['loading', 'empty', 'error', 'ready'],
                true
            )
        ) {
            throw new InvalidArgumentException('state invalid.');
        }
        $message = self::statusMessage($input['message']);
        if (
            !is_array($input['providers'])
            || !array_is_list($input['providers'])
            || !is_array($input['accounts'])
            || !array_is_list($input['accounts'])
        ) {
            throw new InvalidArgumentException('collections invalid.');
        }

        if ($state !== 'ready') {
            if ($input['providers'] !== [] || $input['accounts'] !== []) {
                throw new InvalidArgumentException(
                    'Non-ready view must not expose accounts.'
                );
            }
            return [
                'state' => $state,
                'message' => $message,
                'providers' => [],
                'accounts' => [],
            ];
        }

        $providers = [];
        foreach ($input['providers'] as $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('Provider invalid.');
            }
            $provider = AgentRuntime::provider($raw);
            if (isset($providers[$provider['provider_id']])) {
                throw new InvalidArgumentException('Provider duplicated.');
            }
            $providers[$provider['provider_id']] = $provider;
        }
        if ($input['accounts'] === []) {
            return [
                'state' => 'empty',
                'message' => $message,
                'providers' => [],
                'accounts' => [],
            ];
        }

        $accounts = [];
        $seen = [];
        foreach ($input['accounts'] as $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('Account invalid.');
            }
            $account = AgentRuntime::account($raw);
            if (!isset($providers[$account['provider_id']])) {
                throw new InvalidArgumentException(
                    'Account provider missing.'
                );
            }
            if (isset($seen[$account['account_id']])) {
                throw new InvalidArgumentException('Account duplicated.');
            }
            $seen[$account['account_id']] = true;
            $accounts[] = $account;
        }
        usort(
            $accounts,
            static fn(array $a, array $b): int =>
                $a['account_id'] <=> $b['account_id']
        );
        ksort($providers, SORT_STRING);

        return [
            'state' => 'ready',
            'message' => $message,
            'providers' => $providers,
            'accounts' => $accounts,
        ];
    }

    private static function statusMessage(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (
            !is_string($value)
            || strlen($value) > 240
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
        ) {
            throw new InvalidArgumentException('message invalid.');
        }

        $credentialPattern = '/\b(?:bearer\s+[A-Za-z0-9._~+\/-]{8,}|'
            . '(?:password|passwd|token|secret|cookie|authorization|'
            . 'api[_ -]?key|private[_ -]?key|dsn|session[_ -]?token)'
            . '\s*[:=])/i';
        $tokenPattern = '/\b(?:ghp_|gho_|github_pat_|sk-|rk-|pk-)'
            . '[A-Za-z0-9_-]{8,}/i';
        $privateKeyPattern = '/-----BEGIN [^-]*PRIVATE KEY-----/i';

        if (
            preg_match($credentialPattern, $value) === 1
            || preg_match($tokenPattern, $value) === 1
            || preg_match($privateKeyPattern, $value) === 1
        ) {
            throw new InvalidArgumentException('message invalid.');
        }
        return $value;
    }

    private static function fact(string $label, string $value): string
    {
        return '<div><dt>'
            . self::esc($label)
            . '</dt><dd>'
            . self::esc($value)
            . '</dd></div>';
    }

    private static function stateBlock(
        string $state,
        string $copy,
        ?string $message
    ): string {
        $detail = $message === null
            ? ''
            : '<p class="muted">' . self::esc($message) . '</p>';

        return '<section class="account-state" data-state="'
            . self::esc($state)
            . '" role="status"><h1>Cuentas</h1><p>'
            . self::esc($copy)
            . '</p>'
            . $detail
            . '</section>';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }

    private static function document(string $body): string
    {
        return '<!doctype html><html lang="es"><head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" '
            . 'content="width=device-width,initial-scale=1,viewport-fit=cover">'
            . '<title>ControlBot · Cuentas</title><style>'
            . UiTheme::tokensCss()
            . self::css()
            . '</style></head><body><main class="accounts-page">'
            . $body
            . '</main></body></html>';
    }

    private static function css(): string
    {
        return <<<'CSS'
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: Inter, system-ui, sans-serif;
}

.accounts-page {
    width: min(100%, 1100px);
    margin: auto;
    padding: 24px 16px 48px;
}

.accounts-header {
    margin-bottom: 18px;
}

.section-id {
    color: var(--cyan);
    font: 700 .72rem/1.3 ui-monospace, monospace;
    letter-spacing: .07em;
}

.muted,
dt {
    color: var(--muted);
}

h1,
h2,
dt,
dd,
.capacity-note {
    overflow-wrap: anywhere;
}

.account-grid {
    display: grid;
    grid-template-columns:1fr;
    gap: 16px;
}

.account-card,
.account-state {
    min-width: 0;
    padding: 18px;
    border: 1px solid var(--line);
    border-radius: 10px;
    background: var(--panel);
}

.account-provider {
    font: 700 .78rem/1.3 "JetBrains Mono", ui-monospace, monospace;
    color: var(--cyan);
}

dl {
    display: grid;
    gap: 8px;
    margin: 14px 0;
}

dl div {
    display: grid;
    grid-template-columns: minmax(125px, .8fr) minmax(0, 1.2fr);
    gap: 10px;
    padding-top: 8px;
    border-top: 1px solid var(--line);
}

dt,
dd {
    margin: 0;
}

.capacity-note {
    margin-top: 16px;
    padding: 10px;
    border-left: 3px solid var(--amber);
    color: var(--muted);
}

a,
button,
[tabindex]:not([tabindex="-1"]) {
    outline-offset: 3px;
}

a:focus-visible,
button:focus-visible,
[tabindex]:focus-visible {
    outline: 3px solid var(--amber);
}

@media(min-width:760px) {
    .accounts-page {
        padding: 36px 28px 60px;
    }

    .account-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(prefers-reduced-motion:reduce) {
    *,
    *::before,
    *::after {
        animation: none !important;
        transition: none !important;
        scroll-behavior: auto !important;
    }
}
CSS;
    }
}
