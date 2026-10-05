<?php
declare(strict_types=1);

require_once __DIR__.'/../src/ControlBotWebEntrypoint.php';

use ControlBot\Web\ControlBotWebEntrypoint;

// Compatibility contract: non-GitHub routes still reach FactoryOrchestratorWebEntrypoint::handle
// through ControlBotWebEntrypoint.
$response=ControlBotWebEntrypoint::handle(
    $_SERVER,
    ['CONTROLBOT_OWNER_LOGIN'=>getenv('CONTROLBOT_OWNER_LOGIN')?:null],
    time(),
);
http_response_code($response['status']);
foreach($response['headers'] as $name=>$value) header($name.': '.$value);
echo $response['body'];
