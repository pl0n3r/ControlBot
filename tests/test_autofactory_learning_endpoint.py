import json,subprocess,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def run(name):
 p=subprocess.run(['php',str(ROOT/'tests/autofactory_learning_endpoint_scenarios.php'),name],cwd=ROOT,text=True,capture_output=True,check=True);return json.loads(p.stdout)
class EndpointTests(unittest.TestCase):
 def test_is_disabled_until_legal_security_gate(self):
  d=run('disabled');self.assertTrue(d['blocked']);self.assertFalse(d['contract']['enabled'])
 def test_requires_authenticated_profile(self): self.assertTrue(run('auth')['blocked'])
 def test_contract_is_scoped_and_can_return_conditional_policy(self):
  d=run('enabled');self.assertEqual(d['schemaVersion'],1);self.assertFalse(d['enabled'])
  c=run('disabled')['contract'];self.assertEqual(c['writeScope'],'autofactory.learning.write');self.assertEqual(c['readScope'],'autofactory.learning.read')
if __name__=='__main__':unittest.main()
