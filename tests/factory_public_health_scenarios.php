<?php
declare(strict_types=1);

require __DIR__ . '/../src/FactoryPublicHealthObserver.php';
require __DIR__ . '/../src/FactoryPublicHealthUi.php';

use ControlBot\Business\FactoryPublicHealthObserver as Observer;
use ControlBot\Business\FactoryPublicHealthUi as Ui;

$mode = $argv[1] ?? 'normal';
$approved = [
    'Condor' => 'https://condor.example.org/health',
    'brvtal' => 'https://brvtal.example.org/health',
    'GrindFlow' => 'https://grindflow.example.org/health',
    'ControlBot' => 'https://controlbot.example.org/health',
];
$configured = $approved;
$requests = 0;
$dnsCalls = 0;

if ($mode === 'unconfigured') {
    $configured = [];
}
if ($mode === 'hostile') {
    $configured = ['Condor' => 'https://127.0.0.1/latest/meta-data'];
}
if ($mode === 'private_dns' || $mode === 'redirect' || $mode === 'timeout'
    || $mode === 'oversize' || $mode === 'no_body_storage') {
    $configured = ['Condor' => $approved['Condor']];
}

$resolver = static function (string $host) use (&$dnsCalls, $mode): array {
    $dnsCalls++;
    return $mode === 'private_dns' ? ['127.0.0.1'] : ['8.8.8.8'];
};
$request = static function (string $url, string $ip, array $limits) use (&$requests, $mode): array {
    $requests++;
    if ($ip !== '8.8.8.8' || $limits !== [
        'method' => 'GET', 'timeout_ms' => 2000, 'max_bytes' => 4096,
        'follow_redirects' => false, 'send_credentials' => false,
    ]) {
        throw new RuntimeException('unsafe request');
    }
    if ($mode === 'timeout') {
        throw new RuntimeException('CREDENTIAL_SECRET_NEVER_PERSIST');
    }
    if ($mode === 'oversize') {
        return ['status'=>200, 'latency_ms'=>20, 'bytes'=>4097, 'redirected'=>false];
    }
    if ($mode === 'redirect') {
        return ['status'=>302, 'latency_ms'=>20, 'bytes'=>0, 'redirected'=>true];
    }
    // The fake explicitly returns metadata, never an HTTP body or headers.
    $status = str_contains($url, 'brvtal') ? 403
        : (str_contains($url, 'grindflow') ? 500 : 200);
    return ['status'=>$status, 'latency_ms'=>25, 'bytes'=>0, 'redirected'=>false];
};

$rows = Observer::collect($approved, $configured, $resolver, $request, 1800000000);
if ($mode === 'stale') {
    $rows = Observer::age($rows, 1800000500);
} elseif ($mode === 'future_invalid') {
    try {
        Observer::age($rows, 1700000000);
        throw new RuntimeException('future timestamp accepted');
    } catch (InvalidArgumentException) {
        // Expected fail closed.
    }
}
if ($mode === 'ui_escape') {
    $rows[0]['endpoint'] = 'https://condor.example.org/<script>alert(1)</script>';
}
$html = Ui::renderSection($rows);
$answer = [
    'rows' => $rows,
    'requests' => $requests,
    'dns_calls' => $dnsCalls,
    'has_cards' => substr_count($html, 'class="front public-health"'),
    'has_unknown_gate' => str_contains($html, 'NO significa producción VERDE'),
    'is_escaped' => !str_contains($html, '<script>alert(1)</script>'),
    'has_script_text' => str_contains($html, '&lt;script&gt;'),
    'has_no_actions' => !str_contains($html, '<form')
        && !str_contains($html, '<button') && !str_contains($html, '<script'),
    'raw_html' => $mode === 'ui_escape' ? $html : null,
];
echo json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
