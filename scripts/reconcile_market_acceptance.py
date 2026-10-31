#!/usr/bin/env python3
"""Index immutable local evidence without upgrading partial checks to entry acceptance."""
import argparse
import collections
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
EVIDENCE = ROOT / 'docs/market-acceptance'
BASELINE = EVIDENCE / 'tenant-1-baseline-20261030.json'
ENTRIES = {
    'home': 'pc_home', 'home_image': 'pc_home', 'pc_home': 'pc_home',
    'pc_create': 'pc_studio', 'pc_studio': 'pc_studio', 'pc_video': 'pc_studio', 'pc_image': 'pc_studio',
    'pc_studio_video': 'pc_studio', 'pc_studio_image': 'pc_studio', '/ai/create?type=video': 'pc_studio',
    'standalone': 'standalone_app', 'standalone_video': 'standalone_app', 'standalone_image': 'standalone_app',
    '/app/aigc_image': 'standalone_app', '/app/aigc_video': 'standalone_app',
    'canvas': 'canvas', 'canvas_image_actual_source_projection': 'canvas',
    'short_drama': 'short_drama', 'short_drama_canvas': 'short_drama',
}
# This classification describes only the recorded observation. It is never a whole-entry result.
PASS = {
    'http_terminal_rows_preserved',
    'frozen_history_replay_equal',
    'http_graph_state_preserved',
    'presentation_checked_payload_unchanged', 'frontend_sku_quote_and_frozen_preparation_equal',
    'request_quote_admission_and_frozen_equal', 'quote_admission_frozen_slots_equal',
    'frontend_quote_native_and_frozen_preparation_equal', 'quote_preparation_and_slots_equal',
    'quote_preparation_frozen_equal', 'frontend_quote_frozen_preparation_and_meter_equal',
    'frontend_quote_native_preparation_meter_equal', 'shared_projection_quote_and_frozen_preparation_equal',
    'quote_preparation_equal', 'quote_frozen_native_and_image_slots_equal',
    'page_price_quote_preparation_frozen_equal', 'quote_and_submission_preparation_equal',
    'quoted_and_frozen_preparation_equal', 'quote_prepare_equal',
}
EXPECTED = {'expected_rejection', 'expected_contract_rejection', 'expected_local_rejection'}


def digest(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':')).encode()).hexdigest()


def classify(row):
    status = row.get('status', '')
    if status.startswith('blocked_missing_') or row.get('expected') == 'blocked_token_budget':
        return 'external_blocked'
    if status in EXPECTED or (status in {'local_rejection', 'frontend_rejection'} and str(row.get('expected', '')).startswith('rejected')):
        return 'expected_rejection'
    if status in PASS:
        return 'passed'
    if status in {'invariant_failure', 'reproduced_failure', 'failed_retention', 'wrong_fast_quote_reproduced'}:
        return 'failed'
    # Old unannotated rejection, generic "passed" and "quoted" need their original scope reviewed.
    return 'not_covered'


def walk(value, pointer=''):
    if isinstance(value, dict):
        if isinstance(value.get('status'), str):
            yield pointer, value
        for key, child in value.items():
            escaped = str(key).replace('~', '~0').replace('/', '~1')
            yield from walk(child, pointer + '/' + escaped)
    elif isinstance(value, list):
        for index, child in enumerate(value):
            yield from walk(child, pointer + '/' + str(index))


def reconcile():
    baseline = json.loads(BASELINE.read_text())
    resources = {r['identity']['id']: r for r in baseline['resources']}
    sources, observations, unbound = [], [], []
    for path in sorted(EVIDENCE.glob('*batch-*.json')):
        # Reproduction snapshots remain evidence sources, but cannot become post-fix observations.
        data = json.loads(path.read_text())
        relative = str(path.relative_to(ROOT))
        sources.append({'file': relative, 'sha256': hashlib.sha256(path.read_bytes()).hexdigest(),
                        'scope': data.get('scope'), 'source_commits': {k: v for k, v in data.items() if 'commit' in k},
                        'historical_reproduction': 'before' in path.name})
        for pointer, row in walk(data):
            identity = row.get('identity') if isinstance(row.get('identity'), dict) else {}
            ids = [row.get('channel'), row.get('channel_code'), identity.get('id'), identity.get('value')]
            matches = {value for value in ids if isinstance(value, str) and value in resources}
            resource = resources[next(iter(matches))] if len(matches) == 1 else None
            params = row.get('requested_parameters') or row.get('controls') or {}
            if not isinstance(params, dict):
                params = {}
            sku = row.get('sku_id') or row.get('selected_sku') or row.get('requested_sku') or params.get('market_sku_id')
            entry_raw = row.get('entry')
            entry = ENTRIES.get(entry_raw)
            material_mode = row.get('material_mode') or row.get('requested_method') or params.get('generation_method') or row.get('method')
            scenario = row.get('scenario')
            record = {'source': relative, 'pointer': pointer, 'source_status': row['status'],
                      'observation_status': classify(row), 'entry_original': entry_raw,
                      'parameters': params, 'material_mode': material_mode,
                      'sku_id': sku, 'entry': entry, 'scenario': scenario,
                      'identity': resource['identity'] if resource else None,
                      'historical_reproduction': 'before' in path.name,
                      'coverage_level': 'original_source_scope_only', 'full_entry_status': 'not_covered'}
            missing = []
            if resource is None:
                missing.append('exact_resource_identity')
            if not isinstance(sku, int) or not resource or sku not in {c['sku_id'] for c in resource['cases']}:
                missing.append('baseline_sku_identity')
            for name, value in [('entry', entry), ('material_mode', material_mode), ('parameter_scenario', scenario)]:
                if not value:
                    missing.append(name)
            if missing:
                record['missing_bindings'] = missing
                unbound.append(record)
            else:
                record['key'] = digest([resource['identity'], sku, material_mode, entry, scenario])
                observations.append(record)
    # Preserve every quote/submit observation and historical failure; do not let a later
    # partial observation erase an earlier unresolved checkpoint or certify another SKU.
    by_resource = collections.defaultdict(list)
    unbound_by_resource = collections.defaultdict(list)
    for index, observation in enumerate(unbound):
        if observation['identity']:
            unbound_by_resource[observation['identity']['id']].append(index)
    for index, observation in enumerate(observations):
        by_resource[observation['identity']['id']].append(index)
    current = []
    for resource_id, resource in resources.items():
        current.append({'identity': resource['identity'], 'name': resource['name'],
                        'contract_observation': resource['native_contract_observation'],
                        'sku_ids': sorted({case['sku_id'] for case in resource['cases']}),
                        'full_entry_status': 'not_covered',
                        'required_checkpoints': {key: 'not_fully_covered' for key in baseline['required_checkpoints']},
                        'bound_observation_indices': by_resource[resource_id],
                        'unbound_observation_indices': unbound_by_resource[resource_id]})
    return {'version': 1, 'baseline': str(BASELINE.relative_to(ROOT)), 'baseline_sha256': hashlib.sha256(BASELINE.read_bytes()).hexdigest(),
            'scope': 'Historical incremental evidence index; no automatic full-cell acceptance or completion percentage. Source statuses are scoped observations, not current unresolved defect counts.',
            'key': 'SHA256(canonical JSON [exact resource identity, SKU, material mode, canonical entry, explicit scenario]); multiple source observations retained per key',
            'status_values': ['passed', 'expected_rejection', 'failed', 'not_covered', 'not_applicable', 'external_blocked'],
            'not_applicable_policy': 'Requires an explicit reviewed reason; this importer never infers it.',
            'summary': {'resources': len(current), 'unique_skus': len({sku for r in current for sku in r['sku_ids']}),
                        'baseline_entry_cells': baseline['summary']['entry_cells'], 'fully_accepted_entry_cells': 0,
                        'evidence_files': len(sources), 'bound_observations': len(observations), 'unbound_observations': len(unbound),
                        'bound_unique_keys': len({r['key'] for r in observations}),
                        'historical_bound_observation_statuses': dict(collections.Counter(r['observation_status'] for r in observations))},
            'resources': current, 'sources': sources, 'observations': observations, 'unbound_observations': unbound,
            'upstream_generation': 'not_tested_no_paid_authorization'}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, help='Write an explicit artifact path; default prints only the summary')
    args = parser.parse_args()
    result = reconcile()
    if args.output:
        args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps(result['summary'], ensure_ascii=False))
