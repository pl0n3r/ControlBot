<?php
declare(strict_types=1);
require_once __DIR__.'/../src/BackblazeB2Preflight.php';

use ControlBot\Infrastructure\BackblazeB2Preflight;

// Every fixture is synthetic, with no provider account, bucket, key, or endpoint.
$fixture = [
    'bucketType' => 'allPrivate',
    'fileLockConfiguration' => [
        'isClientAuthorizedToRead' => true,
        'value' => [
            'isFileLockEnabled' => true,
            'defaultRetention' => [
                'mode' => 'compliance',
                'period' => ['duration' => 30, 'unit' => 'days'],
            ],
        ],
    ],
    'defaultServerSideEncryption' => [
        'isClientAuthorizedToRead' => true,
        'value' => ['mode' => 'SSE-B2'],
    ],
];
$scenario = $argv[1] ?? 'positive';
$payload = $fixture;
switch ($scenario) {
    case 'positive': break;
    case 'redacted':
        $payload['fileLockConfiguration'] = ['isClientAuthorizedToRead'=>false,'value'=>null]; break;
    case 'public': $payload['bucketType'] = 'allPublic'; break;
    case 'no-retention':
        $payload['fileLockConfiguration']['value']['defaultRetention'] = ['mode'=>null,'period'=>null]; break;
    case 'encryption-redacted':
        $payload['defaultServerSideEncryption'] = ['isClientAuthorizedToRead'=>false,'value'=>null]; break;
    case 'encryption-off':
        $payload['defaultServerSideEncryption']['value']['mode'] = null; break;
    case 'lock-disabled':
        $payload['fileLockConfiguration']['value']['isFileLockEnabled'] = false; break;
    case 'missing': $payload = []; break;
    case 'secret-injection': $payload['applicationKey'] = 'synthetic-not-a-key'; break;
    case 'id-injection': $payload['bucketId'] = 'synthetic-id'; break;
    case 'nested-injection':
        $payload['fileLockConfiguration']['value']['defaultRetention']['token'] = 'synthetic'; break;
    case 'malformed':
        $payload['fileLockConfiguration']['value']['defaultRetention']['period']['duration'] = '30'; break;
    case 'unreadable-with-value':
        $payload['fileLockConfiguration']['isClientAuthorizedToRead'] = false; break;
    default: throw new InvalidArgumentException('Unknown scenario.');
}
try {
    echo json_encode(BackblazeB2Preflight::assess($payload), JSON_THROW_ON_ERROR), "\n";
} catch (InvalidArgumentException) {
    // Safe allowlisted code: never expose input, tokens or provider exception messages.
    echo json_encode(['error_code'=>'invalid_shape','operational_status'=>'UNKNOWN'], JSON_THROW_ON_ERROR), "\n";
}
