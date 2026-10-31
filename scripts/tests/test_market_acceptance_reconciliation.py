import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('reconcile', Path(__file__).resolve().parents[1] / 'reconcile_market_acceptance.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class AcceptanceBoundaries(unittest.TestCase):
    def test_rejection_and_billing_block_never_count_as_generation_pass(self):
        # A named persistence checkpoint passes only its recorded source scope;
        # the reconciliation boundary below still keeps whole entries uncovered.
        self.assertEqual(module.classify({'status': 'http_graph_state_preserved'}), 'passed')
        self.assertEqual(module.classify({'status': 'frozen_history_replay_equal'}), 'passed')
        self.assertEqual(module.classify({'status': 'http_terminal_rows_preserved'}), 'passed')
        self.assertEqual(module.classify({'status': 'frozen_prepare_rejected'}), 'not_covered')
        self.assertEqual(module.classify({'status': 'local_rejection', 'expected': 'rejected_variant_combination'}), 'expected_rejection')
        self.assertEqual(module.classify({'status': 'local_rejection', 'expected': 'blocked_token_budget'}), 'external_blocked')
        self.assertEqual(module.classify({'status': 'local_rejection'}), 'not_covered')
        self.assertEqual(module.classify({'status': 'quoted'}), 'not_covered')
        self.assertEqual(module.classify({'status': 'passed'}), 'not_covered')

    def test_all_evidence_stays_scoped_and_exactly_bound(self):
        result = module.reconcile()
        resources = {r['identity']['id']: r for r in result['resources']}
        self.assertEqual(len(resources), 121)
        self.assertEqual(result['summary']['unique_skus'], 567)
        self.assertEqual(result['summary']['fully_accepted_entry_cells'], 0)
        self.assertFalse(result['summary']['fully_accepted_entry_cells_computed'])
        self.assertIn('placeholder', result['summary']['completion_metric_note'])
        self.assertGreater(len(result['observations']), 0)
        for row in result['observations']:
            self.assertIn(row['sku_id'], resources[row['identity']['id']]['sku_ids'])
            self.assertTrue(row['entry'] and row['material_mode'] and row['scenario'])
            self.assertEqual(row['full_entry_status'], 'not_covered')
        self.assertTrue(all(row['missing_bindings'] for row in result['unbound_observations']))
        self.assertEqual({r['full_entry_status'] for r in result['resources']}, {'not_covered'})

if __name__ == '__main__':
    unittest.main()
