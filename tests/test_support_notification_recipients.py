"""#1428: PHP real, almacenamiento privado, autorización owner, CSRF y simulación."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]

PHP = r"""
require_once 'src/SupportNotificationRecipients.php';
require_once 'src/OwnerLocalAuthStore.php';
use ControlBot\Support\SupportNotificationRecipients as Module;
use ControlBot\Security\OwnerLocalAuthStore;

function check1428(bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
}
function decoded1428(array $response): array {
    return json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
}
$dir = getenv('CB1428_PRIVATE');
$now = 1800000000;
$config = [
    'owner_login'=>'pl0n3r',
    'password_hash'=>password_hash('fixture-only', PASSWORD_ARGON2ID,
        ['memory_cost'=>4096,'time_cost'=>1,'threads'=>1]),
    'rate_limit_dir'=>$dir,
    'totp_key'=>base64_encode(str_repeat('K', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)),
];
$auth = new OwnerLocalAuthStore($dir, 'pl0n3r', $config['totp_key']);
$session = [
    '_cb_owner_web'=>true,
    '_cb_owner_last_seen'=>$now,
    '_cb_owner_revision'=>$auth->status()['version'],
];
$get = ['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/support-notifications'];
$post = ['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/support-notifications'];
$types = ['incident','availability_down','backup_failure','owner_decision','usage_limit'];
$settings = ['recipients'=>array_fill_keys($types, []),'shifts'=>[]];
$settings['recipients']['incident'] = [
    ['email'=>'secondary@example.invalid','order'=>1],
    ['email'=>'third@example.invalid','order'=>2],
];
$settings['recipients']['backup_failure'] = [
    ['email'=>'backups@example.invalid','order'=>1],
];
$settings['shifts'] = [
    ['days'=>[2],'start'=>'09:00','end'=>'17:00','email'=>'guard@example.invalid'],
];
$initial = Module::handle($get, [], $session, $config, $dir, $now);
check1428($initial['status'] === 200, 'owner GET failed');
$initialBody = decoded1428($initial);
check1428($initialBody['revision'] === 0, 'initial revision');
$csrf = $initialBody['csrf'];
$payload = ['csrf'=>$csrf,'expected_revision'=>0,'settings'=>$settings];
$saved = Module::handle($post, $payload, $session, $config, $dir, $now + 1);
check1428($saved['status'] === 200, 'valid save denied');
$rotated = decoded1428($saved)['csrf'];
check1428(!hash_equals($csrf, $rotated), 'CSRF not rotated');
$read = Module::handle($get, [], $session, $config, $dir, $now + 2);
$state = decoded1428($read);
check1428($state['revision'] === 1, 'revision missing');
check1428($state['settings'] === $settings, 'configuration not persistent');
check1428(count($state['audit']) === 1, 'audit not committed');
check1428($state['audit'][0]['actor'] === 'pl0n3r', 'untrusted audit actor');
check1428(in_array('shifts', $state['audit'][0]['changed_fields'], true),
    'change diff missing');
check1428(!str_contains(json_encode($state['audit'], JSON_THROW_ON_ERROR), 'guard@example.invalid'),
    'PII repeated in audit');
check1428(is_file($dir . '/cb-support-notifications.json'), 'private file missing');
check1428(!file_exists('cb-support-notifications.json'), 'wrote state into git');
$scenario = $argv[1] ?? '';
if ($scenario === 'roundtrip') {
    $stale = Module::handle($post, [
        'csrf'=>$rotated,'expected_revision'=>0,'settings'=>$settings,
    ], $session, $config, $dir, $now + 3);
    check1428($stale['status'] === 409, 'stale expected_revision accepted');
    foreach (['unknown','bad-email','bad-hours','overlap','extra','duplicate'] as $variant) {
        $bad = $settings;
        if ($variant === 'unknown') $bad['recipients']['injected'] = [];
        if ($variant === 'bad-email') $bad['recipients']['incident'][0]['email'] = "bad\n@a.invalid";
        if ($variant === 'bad-hours') $bad['shifts'][0]['end'] = '07:00';
        if ($variant === 'overlap') $bad['shifts'][] = [
            'days'=>[2],'start'=>'16:00','end'=>'18:00','email'=>'alternate@example.invalid'];
        if ($variant === 'extra') $bad['unexpected'] = 'not allowed';
        if ($variant === 'duplicate') $bad['recipients']['incident'][1]['email'] =
            $bad['recipients']['incident'][0]['email'];
        $out = Module::handle($post, [
            'csrf'=>$rotated,'expected_revision'=>1,'settings'=>$bad,
        ], $session, $config, $dir, $now + 3);
        check1428($out['status'] === 422, 'invalid update accepted ' . $variant);
    }
    $unchanged = decoded1428(Module::handle($get, [], $session, $config, $dir, $now+4));
    check1428($unchanged['revision'] === 1 && count($unchanged['audit']) === 1,
        'invalid write changed private state');
} elseif ($scenario === 'auth') {
    $anonymous = [];
    check1428(Module::handle($get, [], $anonymous, $config, $dir, $now+3)['status'] === 403,
        'anonymous read exposed settings');
    check1428(Module::handle($post, $payload, $anonymous, $config, $dir, $now+3)['status'] === 403,
        'anonymous write allowed');
    $csrfFailed = Module::handle($post, [
        'csrf'=>$csrf,'expected_revision'=>1,'settings'=>$settings,
    ], $session, $config, $dir, $now + 3);
    check1428($csrfFailed['status'] === 403, 'replayed csrf accepted');
    $wrongMethod = Module::handle([
        'REQUEST_METHOD'=>'DELETE','REQUEST_URI'=>'/support-notifications',
    ], [], $session, $config, $dir, $now + 3);
    check1428($wrongMethod['status'] === 405, 'DELETE not rejected');
    $auth->revokeAll($now+4);
    check1428(Module::handle($get, [], $session, $config, $dir, $now+5)['status'] === 403,
        'revoked session allowed');
    $persisted = json_decode(file_get_contents($dir . '/cb-support-notifications.json'),
        true, 32, JSON_THROW_ON_ERROR);
    check1428($persisted['revision'] === 1 && count($persisted['audit']) === 1,
        'unauthorized mutation audited');
} elseif ($scenario === 'routing') {
    $active = (string) strtotime('2026-11-03T15:00:00+00:00');
    $simulateGet = $get;
    $simulateGet['REQUEST_URI'] =
        '/support-notifications?action=simulate&type=incident&at_utc=' . $active;
    $preview = Module::handle($simulateGet, [
        'action'=>'simulate','type'=>'incident','at_utc'=>$active,
    ], $session, $config, $dir, $now+3);
    check1428($preview['status'] === 200, 'simulation rejected');
    $p = decoded1428($preview);
    check1428($p['sent'] === false && count($p['recipients']) === 3, 'routing/delivery');
    check1428($p['recipients'][0]['role'] === 'on_call'
        && $p['recipients'][0]['email'] === 'guard@example.invalid', 'on-call not first');
    check1428($p['recipients'][1]['order'] === 2
        && $p['recipients'][1]['email'] === 'secondary@example.invalid'
        && $p['recipients'][2]['email'] === 'third@example.invalid',
        'escalation order');
    $off = decoded1428(Module::handle($get, [
        'action'=>'simulate','type'=>'incident',
        'at_utc'=>(string) strtotime('2026-11-03T23:00:00+00:00'),
    ], $session, $config, $dir, $now+3));
    check1428(count($off['recipients']) === 2
        && $off['recipients'][0]['email'] === 'secondary@example.invalid',
        'off-shift routing');
    $unconfigured = decoded1428(Module::handle($get, [
        'action'=>'simulate','type'=>'usage_limit','at_utc'=>$active,
    ], $session, $config, $dir, $now+3));
    check1428($unconfigured['recipients'][0]['role'] === 'on_call', 'on-call availability');
    check1428(Module::handle($get, [
        'action'=>'simulate','type'=>'unknown','at_utc'=>$active,
    ], $session, $config, $dir, $now+3)['status'] === 422, 'unknown type routed');
    $final = decoded1428(Module::handle($get, [], $session, $config, $dir, $now+4));
    check1428(count($final['audit']) === 1 && $final['revision'] === 1,
        'preview caused mutation');
} else {
    throw new RuntimeException('Unknown test scenario.');
}
echo json_encode(['ok'=>true,'scenario'=>$scenario], JSON_THROW_ON_ERROR);
"""


def exercise(name: str) -> dict:
    with tempfile.TemporaryDirectory(prefix="cb-notifications-") as directory:
        completed = subprocess.run(
            ["php", "-r", PHP, name], cwd=ROOT, text=True, capture_output=True,
            env={**os.environ, "CB1428_PRIVATE": directory}, check=True,
        )
        return json.loads(completed.stdout)


class SupportNotificationRecipientsTests(unittest.TestCase):
    def test_config_roundtrip_and_validation(self):
        self.assertTrue(exercise("roundtrip")["ok"])

    def test_owner_only_csrf_and_audit(self):
        self.assertTrue(exercise("auth")["ok"])

    def test_routing_simulation_sends_nothing(self):
        self.assertTrue(exercise("routing")["ok"])


if __name__ == "__main__":
    unittest.main()
