#!/usr/bin/env python3
"""Keep pre-framework installation SQL aligned with the built-in API manifest.

Run with --check in release validation; without it, refresh the full install
block and both idempotent upgrade copies. Never seed callable API settings.
"""
import json
import pathlib
from pathlib import Path
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
APP = ROOT / 'app/apps/open_platform'
BEGIN = '-- BEGIN open_platform install bootstrap (generated from app manifest)'
END = '-- END open_platform install bootstrap'
UPGRADE = '20261014_open_platform_install_bootstrap.sql'
NOW = 'UNIX_TIMESTAMP()'


def quote(value):
    if isinstance(value, (int, bool)):
        return str(int(value))
    return "'" + str(value).replace('\\', '\\\\').replace("'", "''") + "'"


def encoded(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':'))


def insert(table, values):
    columns = ','.join('`' + key + '`' for key in values)
    items = ','.join(NOW if key.endswith('_time') else quote(value) for key, value in values.items())
    return f'INSERT IGNORE INTO `la_{table}` ({columns}) VALUES ({items});'


def generate(app_path=None, begin=None, end=None):
    app_path = Path(app_path) if app_path else APP
    begin, end = begin or BEGIN, end or END
    manifest = json.loads((app_path / 'manifest.json').read_text())
    code = manifest['code']
    lines = [begin, '-- Missing records only; preserve tenant-owned menus and API authorization/settings.']
    lines.append(insert('app', {
        'code': code, 'name': manifest['name'], 'icon': manifest.get('icon', ''),
        'description': manifest['description'], 'category': manifest['category'],
        'cover': manifest.get('cover', ''), 'client_tags': ','.join(manifest['frontends']),
        'is_builtin': 1, 'sort': manifest.get('sort', 0), 'current_version': manifest['version'],
        'status': 'installed', 'expire_policy': 'allow', 'install_time': 0, 'update_time': 0,
    }))
    lines.append(insert('app_version', {
        'app_code': code, 'version': manifest['version'], 'require_core': manifest['require_core'],
        'package_path': 'local', 'manifest_json': encoded(manifest),
        'changelog': manifest['changelog'], 'status': 1, 'create_time': 0,
    }))
    for entry in manifest['frontend_entries']:
        lines.append(insert('app_frontend_entry', {
            'app_code': code, 'terminal': entry['terminal'], 'entry_key': entry['entry_key'],
            'name': entry.get('name', manifest['name']), 'path': entry.get('path', ''),
            'icon': entry.get('icon', ''), 'sort': entry.get('sort', 0),
            'status': entry.get('status', 1), 'meta': encoded(entry.get('meta', [])),
            'create_time': 0, 'update_time': 0,
        }))
    for api in json.loads((app_path / 'api_schema.json').read_text())['apis']:
        lines.append(insert('app_api', {
            'app_code': code, 'api_path': api['api_path'], 'api_method': api.get('api_method', 'GET').upper(),
            'permission_key': api.get('permission_key', ''), 'scene': api.get('scene', 'tenant_admin'),
            'need_login': api.get('need_login', 1), 'need_role_permission': api.get('need_role_permission', 1),
            'status': api.get('status', 1), 'create_time': 0, 'update_time': 0,
        }))
    lines.extend([
        "INSERT IGNORE INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`)",
        f"SELECT t.tenant_id,{quote(code)},{quote(manifest['version'])},'paid','on','enabled',0,{NOW},{NOW}",
        'FROM (SELECT 0 AS tenant_id UNION SELECT id FROM `la_tenant`) t;',
        '-- Shared tenant menus are seeded here; split tables are synced by DefaultAppService on creation/update.',
    ])
    for terminal, table in [('platform', 'system_menu'), ('tenant', 'tenant_system_menu')]:
        def menu_rows(menus, parent=None):
            for menu in menus:
                key = menu['source_menu_key']
                tenant = terminal == 'tenant'
                parent_id = '0' if parent is None else (
                    f"(SELECT p.id FROM `la_{table}` p WHERE p.app_code={quote(code)} AND p.source_menu_key={quote(parent)}"
                    + (' AND p.tenant_id=t.tenant_id' if tenant else '') + ' LIMIT 1)'
                )
                values = {'pid': parent_id, 'type': menu.get('type', 'C'), 'name': menu.get('name', ''),
                          'icon': menu.get('icon', ''), 'sort': menu.get('sort', 0), 'perms': menu.get('perms', ''),
                          'paths': menu.get('paths', ''), 'component': menu.get('component', ''),
                          'selected': menu.get('selected', ''), 'params': menu.get('params', ''),
                          'is_cache': menu.get('is_cache', 0), 'is_show': menu.get('is_show', 1),
                          'is_disable': menu.get('is_disable', 0), 'app_code': code, 'source': 'app',
                          'source_menu_key': key, 'is_core': 0, 'create_time': 0, 'update_time': 0}
                if tenant:
                    values = {'tenant_id': 't.tenant_id', **values}
                columns = ','.join('`' + col + '`' for col in values)
                items = ','.join(value if col in ['pid', 'tenant_id'] else NOW if col.endswith('_time') else quote(value)
                                 for col, value in values.items())
                lines.append(f'INSERT INTO `la_{table}` ({columns})')
                lines.append(f'SELECT {items}')
                if tenant:
                    lines.append('FROM (SELECT 0 AS tenant_id UNION SELECT id FROM `la_tenant` WHERE tactics<>1) t')
                condition = f"m.app_code={quote(code)} AND m.source_menu_key={quote(key)}"
                if tenant:
                    condition += ' AND m.tenant_id=t.tenant_id'
                lines.append(f'WHERE NOT EXISTS (SELECT 1 FROM `la_{table}` m WHERE {condition})'
                             + (f' AND {parent_id} IS NOT NULL' if parent else '') + ';')
                menu_rows(menu.get('children', []), key)
        menu_path = app_path / 'menus' / (terminal + '.json')
        if menu_path.exists():
            menu_rows(json.loads(menu_path.read_text()))
    lines.append(end)
    return '\n'.join(lines) + '\n'


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
            raise SystemExit('Open-platform install seed drift: ' + ', '.join(mismatches))
        print('PASS manifest/menu/API parity across full install and both upgrade paths')
    else:
        for path, content in files.items():
            path.write_text(content)
        print('Updated open-platform full-install and upgrade bootstrap SQL')


if __name__ == '__main__':
    main()
