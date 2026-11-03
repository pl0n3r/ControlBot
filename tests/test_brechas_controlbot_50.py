#!/usr/bin/env python3
"""ControlBot #1057: 15 brechas MOMENTUM/observabilidad, solo offline.

Se ejercitan contratos PHP públicos con fixtures sintéticas; la cobertura live
continúa UNKNOWN. No se cambia la matriz ni se interpreta CI como go-live.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from pathlib import Path

from test_brechas_controlbot_20 import credible_e3_coverage_row as e3_coverage

ROOT = Path(__file__).resolve().parents[1]
INDEX = ROOT / 'docs/matriz-cobertura.md'
PART = ROOT / 'docs/matriz-cobertura-parte-03.md'
IDS = {
    'CB-0757', 'CB-0758', 'CB-0759', 'CB-0760', 'CB-0761',
    'CB-0762', 'CB-0763', 'CB-0764', 'CB-0765', 'CB-0766',
    'CB-0768', 'CB-0769', 'CB-0771', 'CB-0772', 'CB-0773',
}
SOURCES = {
    'CB-0757': 'momentum-revenue-contract.md',
    'CB-0758': 'momentum-revenue-contract.md',
    'CB-0759': 'momentum-revenue-contract.md',
    'CB-0760': 'momentum-work-origin.md',
    'CB-0761': 'momentum-work-origin.md',
    'CB-0762': 'momentum-work-origin.md',
    'CB-0763': 'momentum-work-origin.md',
    'CB-0764': 'momentum-work-origin.md',
    'CB-0765': 'momentum-work-origin.md',
    'CB-0766': 'momentum-work-origin.md',
    'CB-0768': 'observability-event-v1.md',
    'CB-0769': 'observability-event-v1.md',
    'CB-0771': 'observability-incident-v1.md',
    'CB-0772': 'observability-incident-v1.md',
    'CB-0773': 'observability-incident-v1.md',
}
# Aserciones offline no sustituyen evidencia de componentes ajenos ni live.
UNVERIFIED = {
    'CB-0761': 'Integración live de seis APIs no probada',
    'CB-0763': 'Readiness y dispatch reales en Factory/Runner no observados',
    'CB-0768': 'Fixture existente no prueba explícitamente timestamp invertido',
    'CB-0771': 'Post-deploy real y correlación de release no observados',
}
SCENARIOS = {
    'revenue': 'momentum_revenue_scenarios.php',
    'work': 'momentum_work_origin_scenarios.php',
    'event': 'observability_event_scenarios.php',
    'incident': 'observability_incident_scenarios.php',
}


def scenario(domain: str, name: str) -> dict:
    run = subprocess.run(
        ['php', str(ROOT / 'tests' / SCENARIOS[domain]), name],
        cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
    )
    if run.returncode:
        raise AssertionError(f'{domain}/{name}: PHP terminó {run.returncode}: {run.stderr}')
    return json.loads(run.stdout)


def coverage_rows() -> dict[str, str]:
    rows = {}
    for filename in (INDEX, PART):
        for line in filename.read_text(encoding='utf-8').splitlines():
            match = re.match(r'^\| (CB-\d{4}) \|', line)
            if not match or match.group(1) not in IDS:
                continue
            identifier = match.group(1)
            if identifier in rows:
                raise AssertionError(f'{identifier}: fila duplicada')
            rows[identifier] = line
    return rows


class BrechasControlbot50Tests(unittest.TestCase):
    """Aserciones por cláusula y fail-closed sin autorización productiva."""

    def test_contrato_principal(self) -> None:
        attribution = scenario('revenue', 'attribution')
        funnel = scenario('revenue', 'funnel')
        forecast = scenario('revenue', 'forecast')['forecast']
        work = scenario('work', 'e2e')
        ready = scenario('work', 'valid')
        identity = scenario('work', 'identity')
        drift = scenario('work', 'drift')
        gates = scenario('work', 'gates')
        unsafe = scenario('work', 'unsafe')
        fresh = scenario('event', 'freshness')
        runner = scenario('event', 'incident78')
        deploy = scenario('incident', 'deploy')
        separated = scenario('incident', 'separate')['incidents']
        severity = scenario('incident', 'severity')
        recovery = scenario('incident', 'recovery')
        item = ready['work_item']
        origin = (ROOT / 'tests/momentum_work_origin_scenarios.php').read_text(encoding='utf-8')
        source = (ROOT / 'src/MomentumWorkOrigin.php').read_text(encoding='utf-8')
        docs = (ROOT / 'docs/momentum-work-origin.md').read_text(encoding='utf-8')
        required = {'work_id', 'origin_mode', 'origin_system', 'group_id', 'work_type',
                    'requested_capabilities', 'required_roles', 'authority_level',
                    'producer_ref', 'priority_class', 'depends_on', 'claims',
                    'policy_ref', 'evidence_refs', 'idempotency_key'}
        optional = {'venture_id', 'project_id', 'repository_ref', 'severity',
                    'budget_ref', 'approval_ref', 'observed_at'}
        upstream_calls = (
            'MomentumCampaign::campaign(', 'MomentumCreative::variant(',
            'MomentumEmail::audienceState(', 'MomentumExperiment::experiment(',
            'MomentumPaidMedia::plan(', 'MomentumPerformance::project(',
        )
        verdicts = {
            'CB-0757': (attribution['observed']['demonstrated_amount_minor'] == 12500000
                and attribution['inferred']['demonstrated_amount_minor'] is None
                and attribution['unknown']['demonstrated_amount_minor'] is None),
            'CB-0758': (funnel['lead']['stage'] == 'lead'
                and funnel['won']['stage'] == 'won'
                and funnel['won']['customer_success_handoff_ref'] is not None
                and funnel['bad_stage_rejected'] and funnel['bad_qualification_rejected']),
            'CB-0759': (forecast['classification'] == 'forecast'
                and forecast['demonstrated_revenue'] is False
                and forecast['confidence'] == 70 and forecast['freshness'] == 'current'
                and bool(forecast['source_ref']) and attribution['unknown']['amount_minor'] is None),
            'CB-0760': (work['out']['status'] == 'materialized'
                and work['creative']['status'] == 'approved'
                and work['email']['marketing_eligible'] is True
                and work['experiment']['status'] == 'completed'
                and work['experiment']['result']['freshness'] == 'current'
                and work['performance']['freshness'] == 'current'
                and work['paid']['campaign_id'] == work['campaign']['campaign_id']),
            'CB-0761': (work['out']['status'] == 'materialized'
                and all(call in origin for call in upstream_calls)
                and drift['upstream_rejected'] is True),
            'CB-0762': (all(v['status'] == 'blocked' and v['work_item'] is None
                               and v['execution'] is False and bool(v['gate_ref'])
                               for v in gates.values())
                and {'owner', 'budget', 'unknown', 'paid_stale', 'origin_unknown'} <= set(gates)),
            'CB-0763': (ready['execution'] is False
                and item['origin_mode'] == 'automatic'
                and item['origin_system'] == 'momentum'
                and identity['a']['work_item'] == identity['b']['work_item']
                and all(word in docs for word in ('Factory', 'FactoryRunner', 'AEGIS', 'CAPITAL', 'readiness', 'dispatch'))),
            'CB-0764': (item['authority_level'] == 'l2_venture_admin'
                and item['budget_ref'] == 'budget:' + 'd' * 32
                and item['policy_ref'] == 'controlbot:policy/business-os-v1'
                and item['producer_ref'] == 'momentum:work-origin'
                and "paid['authority']" in source and "paid['spend']['budget_ref']" in source
                and gates['owner']['status'] == 'blocked'),
            'CB-0765': (required <= set(item) <= required | optional
                and 'freshness' not in item and 'execution' not in item
                and 'repository_ref' not in required and 'no-code' in docs),
            'CB-0766': (all(v['reasons'] == ['invalid_input'] for v in unsafe.values())
                and ready['execution'] is False and item['origin_system'] == 'momentum'),
            'CB-0768': (fresh['unknown'] == 'unknown' and fresh['stale'] == 'stale'
                and fresh['delayed'] == 'stale' and fresh['future_rejected'] is True),
            'CB-0769': (runner['type'] == 'runner_capacity'
                and runner['payload_allowlisted']['application_status'] == 'unknown'
                and runner['payload_allowlisted']['runner_status'] == 'unavailable'
                and runner['payload_allowlisted']['startup_failure'] is True
                and runner['payload_allowlisted']['steps'] is None),
            'CB-0771': (deploy['linked']['incidents'][0]['correlation_reason'] == 'post_deploy_explicit'
                and len(deploy['linked']['incidents'][0]['event_fingerprints']) == 2
                and deploy['unlinked']['incidents'][0]['correlation_reason'] == 'new_incident'
                and deploy['too_late']['incidents'][0]['correlation_reason'] == 'new_incident'),
            'CB-0772': (len(separated) == 2
                and len({x['incident_id'] for x in separated}) == 2
                and severity['forward'] == severity['reverse']
                and severity['forward']['incidents'][0]['severity'] == 'critical'),
            'CB-0773': (recovery['resolved']['incidents'][0]['status'] == 'resolved'
                and recovery['stale_status'] == 'open'
                and recovery['unknown_status'] == 'open'
                and recovery['reopened_status'] == 'open'),
        }
        self.assertEqual(set(verdicts), IDS)
        for identifier, passed in sorted(verdicts.items()):
            with self.subTest(clause=identifier):
                self.assertTrue(passed, f'{identifier}: falta predicado o hay drift de contrato')

    def test_falla_cerrado(self) -> None:
        rev = scenario('revenue', 'attribution')
        funnel = scenario('revenue', 'funnel')
        gates = scenario('work', 'gates')
        unsafe = scenario('work', 'unsafe')
        obs = scenario('event', 'security')
        cases = {
            'CB-0757/evidence': rev['observed_without_evidence_rejected'],
            'CB-0758/stage': funnel['bad_stage_rejected'],
            'CB-0759/won': rev['pre_won_revenue_rejected'],
            'CB-0760/email': 'email_not_eligible' in gates['email_denied']['reasons'],
            'CB-0761/drift': scenario('work', 'drift')['upstream_rejected'],
            'CB-0762/budget': 'capital_denied' in gates['budget']['reasons'],
            'CB-0764/authority': 'authority_unknown' in gates['unknown']['reasons'],
            'CB-0766/pii': unsafe['pii']['reasons'] == ['invalid_input'],
            'CB-0766/secret': unsafe['secret']['reasons'] == ['invalid_input'],
            'CB-0768/future': scenario('event', 'freshness')['future_rejected'],
            'CB-0769/secret': obs['secret'],
            'CB-0771/deploy': scenario('incident', 'deploy')['unlinked']['incidents'][0]['correlation_reason'] == 'new_incident',
            'CB-0772/separation': len(scenario('incident', 'separate')['incidents']) == 2,
            'CB-0773/unknown': scenario('incident', 'recovery')['unknown_status'] == 'open',
        }
        for name, passed in cases.items():
            with self.subTest(case=name):
                self.assertTrue(passed, f'{name}: se permitió una transición no acreditada')

    def test_sin_datos_reales(self) -> None:
        registry = json.loads((ROOT / 'datos.yml').read_text(encoding='utf-8'))
        self.assertEqual(registry['phase'], 'construccion')
        self.assertTrue(registry['d063_attestation']['nothing_live'])
        self.assertTrue(registry['d063_attestation']['no_real_customer_data'])
        fixtures = {
            'pipeline': scenario('revenue', 'funnel')['lead'],
            'work': scenario('work', 'valid'),
            'runner': scenario('event', 'incident78'),
        }
        encoded = json.dumps(fixtures, sort_keys=True).lower()
        for forbidden in ('"password"', '"api_key"', '"access_token"', '"credentials"',
                          '"secret"', 'bearer ', '@', 'https://'):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, encoded)
        self.assertFalse(fixtures['work']['execution'])

    def test_estados_unknown_explicitos(self) -> None:
        rows = coverage_rows()
        self.assertEqual(set(rows), IDS)
        self.assertEqual(set(SOURCES), IDS)
        for identifier in sorted(IDS):
            with self.subTest(clause=identifier):
                row = rows[identifier]
                self.assertIn(SOURCES[identifier], row)
                self.assertTrue(e3_coverage([p.strip() for p in row.strip('|').split('|')]))
                for state in ('Offline=UNKNOWN', 'live=UNKNOWN', 'operando=UNKNOWN'):
                    if state != "Offline=UNKNOWN" or "| NO_VERIFICABLE |" in row:
                        self.assertIn(state.lower(), row.lower())
                self.assertTrue((ROOT / 'docs' / SOURCES[identifier]).is_file())
        self.assertEqual(set(UNVERIFIED), {'CB-0761', 'CB-0763', 'CB-0768', 'CB-0771'})
        for reason in UNVERIFIED.values():
            self.assertTrue(reason, 'brecha parcial sin límite explícito')


if __name__ == '__main__':
    unittest.main()