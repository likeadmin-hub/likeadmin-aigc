"""Regenerate source SQL registry rows from the application contract; never publishes anything."""
from pathlib import Path
import json
import sys
root = Path(__file__).resolve().parents[1]
code = sys.argv[1] if len(sys.argv)>1 else 'aigc_story_commerce'
assert code in ['aigc_story_commerce','aigc_motion_sync']
marker = 'story commerce' if code=='aigc_story_commerce' else 'motion sync'
upgrade = 'upgrade_20261111_aigc_story_commerce.sql' if code=='aigc_story_commerce' else 'upgrade_20261110_aigc_motion_sync.sql'
app = root / 'app/apps' / code
manifest = json.loads((app / 'manifest.json').read_text())
schema = json.loads((app / 'api_schema.json').read_text())
def q(value):
    if isinstance(value, (dict, list)):
        value = json.dumps(value, ensure_ascii=False, separators=(',', ':'))
    if isinstance(value, (int, float)):
        return str(value)
    return "'" + str(value).replace('\\', '\\\\').replace("'", "''") + "'"
def insert(table, data):
    return f'INSERT IGNORE INTO `la_{table}` (' + ','.join('`'+k+'`' for k in data) + ') VALUES (' + ','.join('UNIX_TIMESTAMP()' if v == '__NOW__' else q(v) for v in data.values()) + ');'
lines = [f'-- BEGIN {marker} application bootstrap (idempotent source registration)']
lines.append(insert('app', dict(code=code,name=manifest['name'],icon=manifest['icon'],description=manifest['description'],category='aigc',cover='',client_tags='platform,tenant,pc,uniapp',is_builtin=1,sort=manifest['sort'],current_version=manifest['version'],status='installed',expire_policy='allow',install_time='__NOW__',update_time='__NOW__')))
lines.append(insert('app_version', dict(app_code=code,version=manifest['version'],require_core=manifest['require_core'],package_path='local',manifest_json=manifest,changelog=manifest['changelog'],status=1,create_time='__NOW__')))
for entry in manifest['frontend_entries']:
    lines.append(insert('app_frontend_entry',dict(app_code=code,**{k:entry.get(k,'' if k not in ['sort','status'] else 1) for k in ['terminal','entry_key','name','path','icon','sort','status']},meta=entry.get('meta',{}),create_time='__NOW__',update_time='__NOW__')))
for api in schema['apis']:
    lines.append(insert('app_api',dict(app_code=code,**{k:api[k] for k in ['api_path','api_method','permission_key','scene','need_login','need_role_permission']},status=1,create_time='__NOW__',update_time='__NOW__')))
lines.append(f"INSERT IGNORE INTO `la_tenant_app` (`tenant_id`,`app_code`,`version`,`buy_status`,`shelf_status`,`enable_status`,`expire_time`,`create_time`,`update_time`) SELECT t.tenant_id,{q(code)},{q(manifest['version'])},'paid','on','enabled',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP() FROM (SELECT 0 AS tenant_id UNION SELECT id FROM `la_tenant`) t;")
for terminal, table in [('tenant','tenant_system_menu'),('platform','system_menu')]:
    def menu(item, parent=None):
        tenant = terminal == 'tenant'
        owner = ' AND m.tenant_id=t.tenant_id' if tenant else ''
        pidowner = ' AND p.tenant_id=t.tenant_id' if tenant else ''
        pid = f"(SELECT p.id FROM `la_{table}` p WHERE p.app_code={q(code)} AND p.source_menu_key={q(parent)}{pidowner} LIMIT 1)" if parent else '0'
        keys = ['type','name','icon','sort','perms','paths','component','selected','params','is_cache','is_show','is_disable']
        cols = (['tenant_id'] if tenant else [])+['pid']+keys+['app_code','source','source_menu_key','is_core','create_time','update_time']
        vals = (['t.tenant_id'] if tenant else [])+[pid]+[q(item.get(k,{'type':'C','sort':0,'is_cache':0,'is_show':1,'is_disable':0}.get(k,''))) for k in keys]+[q(code),q('app'),q(item['source_menu_key']),'0','UNIX_TIMESTAMP()','UNIX_TIMESTAMP()']
        src = ' FROM (SELECT 0 AS tenant_id UNION SELECT id FROM `la_tenant` WHERE tactics<>1) t' if tenant else ' FROM DUAL'
        lines.append(f'INSERT INTO `la_{table}` ('+','.join('`'+c+'`' for c in cols)+') SELECT '+','.join(vals)+src+f" WHERE NOT EXISTS (SELECT 1 FROM `la_{table}` m WHERE m.app_code={q(code)} AND m.source_menu_key={q(item['source_menu_key'])}{owner})"+(f' AND {pid} IS NOT NULL' if parent else '')+';')
        for child in item.get('children',[]):
            menu(child, item['source_menu_key'])
    for item in json.loads((app / 'menus' / f'{terminal}.json').read_text()):
        menu(item)
lines.append(f'-- END {marker} application bootstrap')
bootstrap = '\n'.join(lines)+'\n'
(app / 'bootstrap.sql').write_text(bootstrap)
for name in ['public/install/db/like.sql','upgrade/'+upgrade,'public/upgrade/'+upgrade]:
    path = root / name
    raw = path.read_text()
    start = raw.index(f'-- BEGIN {marker} application bootstrap')
    end = raw.index(f'-- END {marker} application bootstrap', start)+len(f'-- END {marker} application bootstrap')
    path.write_text(raw[:start]+bootstrap.rstrip('\n')+raw[end:])
