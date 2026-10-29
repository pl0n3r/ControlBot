#!/usr/bin/env python3
"""#1205: behavioral financial coverage, using real PHP and synthetic fixtures only.

No provider, payment, network, live identity, real grants, or production data.
Finance handoff readiness remains a hint, not Factory dispatch authority.
"""
from __future__ import annotations

import ast
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
FILE = Path(__file__)
ROWS = frozenset(f"CB-{n:04d}" for n in range(344, 363))
CLASSIFIED_C = {
    "CB-0349": "Scope boundary: absence of ledger, payments, provider, UI or queue "
               "cannot be accredited by an isolated FinanceCostCenter call.",
    "CB-0355": "Factory canonical readiness, ranking, claims and dispatch remain "
               "governance outside this isolated ControlBot PHP contract; "
               "the local ready_hint is exercised under CB-0356.",
}
EXECUTABLE = ROWS - CLASSIFIED_C.keys()

# The existing finance_runtime_scenarios.php bootstrap creates only synthetic
# identities/grants and requires the production PHP classes. No external I/O.
# We suppress the scenario's own JSON and invoke PHP domain classes directly.
PHP_PROBE = r"""
ob_start();
$argv = ['fixture', 'scope'];
require 'tests/finance_runtime_scenarios.php';
ob_end_clean();
require_once 'src/FinanceCostCenter.php';

use ControlBot\Business\FinanceCostCenter;
use ControlBot\Business\FinanceRuntime;
use ControlBot\Business\FinancialPlanning;

$entity = static fn(string $kind, string $id, string $scope, string $title): array => [
    'version'=>1, 'kind'=>$kind, 'id'=>$id, 'scope'=>$scope, 'title'=>$title,
    'source_ref'=>'controlbot:fixture/cost-center',
    'observed_at'=>'2026-09-28T15:30:00Z', 'freshness'=>'fresh',
];
$attribution = static fn(array $target): array => [
    'version'=>1, 'attribution_id'=>'shared-fixture', 'target'=>$target,
    'currency'=>'COP', 'amount_minor'=>1000,
    'provenance_ref'=>'controlbot:fixture/provenance',
    'source_ref'=>'controlbot:fixture/allocation',
    'observed_at'=>'2026-09-28T15:30:00Z', 'freshness'=>'fresh',
];
$reject = static function (callable $fn): bool {
    try { $fn(); return false; }
    catch (InvalidArgumentException $e) { return true; }
};

$venture = $entity('venture', 'condor', 'venture:condor', 'Fixture Venture');
$institution = $entity('institution_cost_center', 'factory', 'institution:factory', 'Factory');
$commercial = FinanceCostCenter::normalize($venture);
$institutional = FinanceCostCenter::normalize($institution);
$canonical = FinanceCostCenter::canonicalInstitutions();
$canonicalIds = array_column($canonical, 'scope', 'id');
$commercialAttribution = FinanceCostCenter::normalizeAttribution($attribution($venture));
$sharedAttribution = FinanceCostCenter::normalizeAttribution($attribution($institution));

$read = FinanceRuntime::read(readRequest(), NOW);
$cross = readRequest();
$cross['context']['scope'] = 'venture:grindflow';
$denied = FinanceRuntime::read($cross, NOW);
$badGrant = readRequest();
$badGrant['grant']['capability'] = 'finance.write';
$grantDenied = FinanceRuntime::read($badGrant, NOW);
$badPeriod = readRequest();
$badPeriod['planning']['period'] = '2026-10';
$periodDenied = FinanceRuntime::read($badPeriod, NOW);
$badCurrency = readRequest();
$badCurrency['planning']['currency'] = 'USD';
$currencyDenied = FinanceRuntime::read($badCurrency, NOW);
$missingRead = readRequest();
$missingRead['planning']['actual'] = null;
$unavailableRead = FinanceRuntime::read($missingRead, NOW);
$action = FinanceRuntime::routeAction(['scope'=>'venture:condor','capital'=>capitalInput(false)], NOW);
$badAction = capitalInput(false);
$badAction['decision_rights_input']['action']['capability'] = 'finance.delete';
$actionDenied = FinanceRuntime::routeAction(['scope'=>'venture:condor','capital'=>$badAction], NOW);

$levels = [
    'L0_AI_AUTONOMOUS'=>'autonomous', 'L1_OPERATOR'=>'operational',
    'L2_VENTURE_ADMIN'=>'venture_admin', 'L3_GROUP_INSTITUTION'=>'group_institution',
    'L4_OWNER'=>'owner',
];
$mapped = [];
foreach ($levels as $level=>$expected) {
    $work = workRequest();
    $work['authority_level'] = $level;
    $handoff = FinanceRuntime::factoryHandoff(['read'=>readRequest(),'work'=>$work], NOW);
    $mapped[$level] = $handoff['work_item']['authority_level'] ?? null;
}
$freshHandoff = FinanceRuntime::factoryHandoff(['read'=>readRequest(),'work'=>workRequest()], NOW);
$staleRead = readRequest();
$staleRead['snapshot']['freshness'] = 'stale';
$staleRead['snapshot']['confidence'] = 'estimated';
$staleHandoff = FinanceRuntime::factoryHandoff(['read'=>$staleRead,'work'=>workRequest()], NOW);
$unknownRead = readRequest();
$unknownRead['planning']['forecast']['freshness'] = 'unknown';
$unknownRead['planning']['forecast']['confidence'] = 'unknown';
$unknownHandoff = FinanceRuntime::factoryHandoff(['read'=>$unknownRead,'work'=>workRequest()], NOW);

$planning = FinancialPlanning::compare(planning());
$unknownPlan = planning();
$unknownPlan['forecast']['freshness'] = 'unknown';
$unknownPlan['forecast']['confidence'] = 'unknown';
$unknownComparison = FinancialPlanning::compare($unknownPlan);
$actualUnknownPlan = planning();
$actualUnknownPlan['actual']['freshness'] = 'unknown';
$actualUnknownPlan['actual']['confidence'] = 'unknown';
$actualUnknownComparison = FinancialPlanning::compare($actualUnknownPlan);
$stalePlan = planning();
$stalePlan['budget']['freshness'] = 'stale';
$stalePlan['budget']['confidence'] = 'estimated';
$staleComparison = FinancialPlanning::compare($stalePlan);
$missingPlan = planning();
$missingPlan['actual'] = null;
$missingComparison = FinancialPlanning::compare($missingPlan);
$unattributed = planning();
$unattributed['attributions'] = [[
    'attribution_id'=>'missing-provenance','kind'=>'shared_cost', 'currency'=>'COP',
    'amount_minor'=>1000, 'target_scope'=>'institution:factory',
    'rule_ref'=>null, 'provenance_ref'=>'controlbot:fixture/source',
]];
$allocation = FinancialPlanning::compare($unattributed)['attributions'][0];

$cases = [
    'CB-0344' => $commercial['scope'] === 'venture:condor',
    'CB-0345' => $institutional['scope'] === 'institution:factory'
        && ($canonicalIds['factory'] ?? null) === 'institution:factory'
        && count($canonicalIds) === 6,
    'CB-0346' => $commercialAttribution['target_kind'] === 'venture'
        && $commercialAttribution['target_scope'] === 'venture:condor'
        && $sharedAttribution['target_kind'] === 'institution_cost_center'
        && $sharedAttribution['target_scope'] === 'institution:factory',
    'CB-0347' => $reject(fn()=>FinanceCostCenter::normalize(
        $entity('institution_cost_center','unknown','institution:unknown','Unknown'))),
    'CB-0348' => $reject(fn()=>FinanceCostCenter::normalize(
        $entity('institution_cost_center','factory','institution:factory','Not Factory')))
        && $reject(fn()=>FinanceCostCenter::normalize(
        $entity('institution_cost_center','factory','venture:factory','Factory'))),
    'CB-0350' => $denied['status'] === 'deny'
        && $denied['snapshot'] === null && $denied['planning'] === null
        && $grantDenied['status'] === 'deny' && $grantDenied['snapshot'] === null,
    'CB-0351' => $periodDenied['status'] === 'deny'
        && $currencyDenied['status'] === 'deny'
        && $read['snapshot']['currency'] === $read['planning']['currency']
        && $read['snapshot']['period'] === $read['planning']['period'],
    'CB-0352' => $read['state'] === 'green'
        && $staleHandoff['freshness'] === 'stale'
        && $unknownHandoff['freshness'] === 'unknown'
        && $unavailableRead['state'] === 'unavailable'
        && $unavailableRead['status'] === 'allow',
    'CB-0353' => $action['authority_source'] === 'CapitalPolicy'
        && $action['decision_rights_source'] === 'DecisionRights'
        && array_key_exists('budget_guard',$action['capital']['authority_evidence'] ?? [])
        && array_key_exists('decision_rights',$action['capital']['authority_evidence'] ?? [])
        && $action['execution'] === false,
    'CB-0354' => $mapped === $levels,
    'CB-0356' => $staleHandoff['ready_hint'] === false
        && $unknownHandoff['ready_hint'] === false
        && $freshHandoff['queue'] === 'factory'
        && $freshHandoff['ready_hint'] === true
        && !array_key_exists('execution',$freshHandoff)
        && $unknownHandoff['reason'] === 'finance_evidence_not_green'
        && is_array($staleHandoff['work_item'])
        && is_array($unknownHandoff['work_item']),
    'CB-0357' => $grantDenied['status'] === 'deny'
        && $actionDenied['status'] === 'deny'
        && in_array('decision_rights_denied',$actionDenied['reasons'],true),
    'CB-0358' => $planning['variances']['budget']['status'] === 'available'
        && $unknownComparison['variances']['forecast']['status'] === 'unavailable'
        && $unknownComparison['variances']['forecast']['delta_minor'] === null
        && $actualUnknownComparison['variances']['budget']['status'] === 'unavailable'
        && $actualUnknownComparison['variances']['budget']['delta_minor'] === null,
    'CB-0359' => $staleComparison['series']['budget']['freshness'] === 'stale'
        && $staleComparison['series']['budget']['confidence'] === 'estimated',
    'CB-0360' => $missingComparison['status'] === 'unavailable'
        && $missingComparison['reason'] === 'actual_missing'
        && $missingComparison['series']['actual'] === null
        && $missingComparison['series']['forecast']['kind'] === 'forecast'
        && $missingComparison['variances']['forecast']['delta_minor'] === null,
    'CB-0361' => $allocation['status'] === 'unattributed'
        && $allocation['target_scope'] === null && $allocation['rule_ref'] === null
        && $allocation['provenance_ref'] === null,
    'CB-0362' => $reject(function() use ($venture) {
            $x=$venture; $x['unexpected']='extra'; FinanceCostCenter::normalize($x);
        }) && $reject(function() {
            $x=planning(); $x['budget']['source_ref']='controlbot:fixture/secret-value';
            FinancialPlanning::compare($x);
        }) && $reject(function() {
            $x=planning(); $x['unexpected']='extra'; FinancialPlanning::compare($x);
        }),
];
$negative = [
    'venture-mismatched-scope' => $reject(fn()=>FinanceCostCenter::normalize(
        $entity('venture','condor','institution:condor','Fixture Venture'))),
    'institution-unknown' => $cases['CB-0347'],
    'institution-wrong-title' => $cases['CB-0348'],
    'attribution-mismatched-target' => $reject(fn()=>FinanceCostCenter::normalizeAttribution(
        $attribution($entity('venture','factory','venture:factory','Factory')))),
    'unauthorized-read' => $grantDenied['snapshot'] === null && $grantDenied['planning'] === null,
    'mismatched-period' => $periodDenied['snapshot'] === null,
    'mismatched-currency' => $currencyDenied['snapshot'] === null,
    'capital-denied' => $actionDenied['execution'] === false,
    'stale-is-not-ready' => !$staleHandoff['ready_hint'],
    'unknown-is-not-ready' => !$unknownHandoff['ready_hint'],
    'unknown-variance' => $unknownComparison['variances']['forecast']['delta_minor'] === null,
    'missing-actual' => $missingComparison['variances']['target']['delta_minor'] === null,
    'missing-allocation' => $allocation['target_scope'] === null,
    'credentials-rejected' => $cases['CB-0362'],
];
echo json_encode(['cases'=>$cases,'negative'=>$negative, 'external_effects'=>false],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
"""


def php_observe() -> dict:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, capture_output=True, text=True, timeout=40, check=False,
    )
    if result.returncode:
        raise AssertionError("UNKNOWN: real PHP failed: " + result.stderr[-500:])
    try:
        payload = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: PHP response malformed") from exc
    if type(payload) is not dict or set(payload) != {"cases", "negative", "external_effects"}:
        raise AssertionError("UNKNOWN: PHP response incomplete")
    return payload


class CoberturaControlbot22Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(19, len(ROWS))
        self.assertEqual(17, len(EXECUTABLE))
        self.assertEqual(ROWS, EXECUTABLE | CLASSIFIED_C.keys())
        observed = php_observe()
        self.assertEqual(EXECUTABLE, set(observed["cases"]))
        for row, passed in observed["cases"].items():
            with self.subTest(requirement=row):
                self.assertIs(passed, True, row + " did not exercise its real PHP behavior")

    def test_falla_cerrado(self) -> None:
        observed = php_observe()
        self.assertTrue(observed["negative"])
        for case, denied in observed["negative"].items():
            with self.subTest(case=case):
                self.assertIs(denied, True, "unsafe input or authority was accepted")

    def test_sin_datos_reales(self) -> None:
        observed = php_observe()
        self.assertIs(observed["external_effects"], False)
        self.assertTrue(all(CLASSIFIED_C.values()))
        self.assertIn("FinanceRuntime::factoryHandoff", PHP_PROBE)
        self.assertIn("FinancialPlanning::compare", PHP_PROBE)
        self.assertIn("FinanceCostCenter::normalize", PHP_PROBE)
        imports = set()
        for node in ast.walk(ast.parse(FILE.read_text(encoding="utf-8"))):
            if isinstance(node, ast.Import):
                imports.update(alias.name.split(".")[0] for alias in node.names)
            if isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {"requests", "urllib", "socket", "httpx", "pymysql", "mysql"})
        self.assertNotIn("curl_", PHP_PROBE)
        self.assertNotIn("file_get_contents('http", PHP_PROBE)


    def test_ci_contract(self) -> None:
        # This static configuration check does not replace a real, terminal
        # Validar on the PR's exact HEAD (that is verified in GitHub).
        workflow = (ROOT / ".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("name: Validar", workflow)
        self.assertIn("needs: [factory, contrato]", workflow)
        self.assertIn("python3 scripts/generate_coverage.py", workflow)


if __name__ == "__main__":
    unittest.main()
