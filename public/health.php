<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/SecretSource.php';
require_once __DIR__ . '/../src/HealthIdentityHttpEndpoint.php';

// No identity fields may be sourced from request parameters or client headers.
$response = \ControlBot\Health\HealthIdentityHttpEndpoint::handle($_SERVER, [
    'owner_login' => \ControlBot\Security\SecretSource::ownerLogin(dirname(__DIR__)),
    'release_manifest_path' => getenv('CONTROLBOT_RELEASE_MANIFEST_PATH') ?: null,
    'attestation_key' => getenv('CONTROLBOT_RELEASE_ATTESTATION_KEY') ?: null,
]);
http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'];
