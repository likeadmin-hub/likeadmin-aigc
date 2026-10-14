#!/usr/bin/env python3
"""Generate Agent registry metadata for fresh installs and additive upgrades.

Tables and menus are owned by the existing Agent migrations. This seed never
changes tenant authorization, settings, wallets, preferences or task data.
"""
import importlib.util
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('install_seed', ROOT / 'scripts/generate-open-platform-install-seed.py')
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)
APP = ROOT / 'app/apps/aigc_llm'
BEGIN = '-- BEGIN home Agent registry bootstrap (generated from app manifest)'
END = '-- END home Agent registry bootstrap'
UPGRADE = '20261014_home_agent_registry.sql'


def generate():
    manifest = json.loads((APP / 'manifest.json').read_text())
    code = manifest['code']
    q, encode, insert = helper.quote, helper.encoded, helper.insert
    lines = [BEGIN, '-- Add missing declarations only; retain tenant-owned lifecycle and configuration.']
    # Known shipped registry versions; avoid downgrading a newer installed app.
    lines.append(f"UPDATE `la_app` SET current_version={q(manifest['version'])},description={q(manifest['description'])} WHERE code={q(code)} AND current_version IN ('1.1.4','1.1.9','1.2.0');")
    lines.append(f"UPDATE `la_tenant_app` SET version={q(manifest['version'])} WHERE app_code={q(code)} AND version IN ('1.1.4','1.1.9','1.2.0');")
    lines.append(insert('app_version', {
        'app_code': code, 'version': manifest['version'], 'require_core': manifest['require_core'],
        'package_path': 'local', 'manifest_json': encode(manifest),
        'changelog': manifest['changelog'], 'status': 1, 'create_time': 0,
    }))
    for entry in manifest['frontend_entries']:
        lines.append(insert('app_frontend_entry', {
            'app_code': code, 'terminal': entry['terminal'], 'entry_key': entry['entry_key'],
            'name': entry.get('name', manifest['name']), 'path': entry.get('path', ''),
            'icon': entry.get('icon', ''), 'sort': entry.get('sort', 0),
            'status': entry.get('status', 1), 'meta': encode(entry.get('meta', [])),
            'create_time': 0, 'update_time': 0,
        }))
    for api in json.loads((APP / 'api_schema.json').read_text())['apis']:
        lines.append(insert('app_api', {
            'app_code': code, 'api_path': api['api_path'], 'api_method': api.get('api_method', 'GET').upper(),
            'permission_key': api.get('permission_key', ''), 'scene': api.get('scene', 'tenant_admin'),
            'need_login': api.get('need_login', 1), 'need_role_permission': api.get('need_role_permission', 1),
            'status': api.get('status', 1), 'create_time': 0, 'update_time': 0,
        }))
    return '\n'.join(lines + [END]) + '\n'


def main():
    seed = generate()
    install = ROOT / 'public/install/db/like.sql'
    original = install.read_text()
    if BEGIN in original:
        start = original.index(BEGIN)
        finish = original.index(END, start) + len(END)
        updated = original[:start] + seed.rstrip('\n') + original[finish:]
    else:
        updated = original.rstrip() + '\n\n' + seed
    files = {install: updated, ROOT / 'upgrade' / UPGRADE: seed, ROOT / 'public/upgrade' / UPGRADE: seed}
    if '--check' in sys.argv:
        mismatches = [str(path.relative_to(ROOT)) for path, content in files.items()
                      if not path.exists() or path.read_text() != content]
        if mismatches:
            raise SystemExit('Home Agent registry seed drift: ' + ', '.join(mismatches))
        print('PASS Home Agent manifest/API registry parity across install and upgrade paths')
    else:
        for path, content in files.items():
            path.write_text(content)
        print('Updated Home Agent install and upgrade registry metadata')


if __name__ == '__main__':
    main()
