"""Write source-only app contract SQL for portable studio inspirations. Never packages or publishes."""
from pathlib import Path
import json

root = Path(__file__).resolve().parents[1]
ddl = """CREATE TABLE IF NOT EXISTS `la_app_builtin_inspiration` (
 `id` int unsigned NOT NULL AUTO_INCREMENT, `app_code` varchar(64) NOT NULL,
 `builtin_key` varchar(64) NOT NULL, `tenant_id` int unsigned NOT NULL DEFAULT 0,
 `case_id` int unsigned DEFAULT NULL, `source_case_id` int unsigned DEFAULT NULL,
 `source_tenant_id` int unsigned NOT NULL DEFAULT 0, `source_job_id` int unsigned DEFAULT NULL,
 `version` int unsigned NOT NULL DEFAULT 0, `source_hash` varchar(64) NOT NULL DEFAULT '',
 `create_time` int unsigned NOT NULL DEFAULT 0, `update_time` int unsigned NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_builtin` (`app_code`,`builtin_key`),
 UNIQUE KEY `uk_case` (`case_id`), UNIQUE KEY `uk_source` (`app_code`,`source_case_id`),
 UNIQUE KEY `uk_job` (`app_code`,`source_tenant_id`,`source_job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台跨站点内置灵感模板映射';
"""
def q(v):
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"
def replace_block(path, marker, text):
    raw = path.read_text() if path.exists() else ''
    start, end = '-- BEGIN '+marker, '-- END '+marker
    block = start+'\n'+text.rstrip()+'\n'+end
    if start in raw:
        a, b = raw.index(start), raw.index(end)+len(end)
        raw = raw[:a]+block+raw[b:]
    else:
        raw = raw.rstrip()+'\n\n'+block+'\n'
    path.write_text(raw)

all_sql=[]
for code in ['aigc_product_video','aigc_motion_sync','aigc_story_commerce']:
    app=root/'app/apps'/code
    schema=json.loads((app/'api_schema.json').read_text())
    menus=json.loads((app/'menus/platform.json').read_text())
    routes=[a for a in schema['apis'] if a['scene']=='platform_admin' and a['api_path'] in [f'app.{code}.case/builtin',f'app.{code}.case/builtinFromJob']]
    lines=[] if code=='aigc_product_video' else [ddl]
    for a in routes:
        lines.append('INSERT IGNORE INTO `la_app_api` (`app_code`,`api_path`,`api_method`,`permission_key`,`scene`,`need_login`,`need_role_permission`,`status`,`create_time`,`update_time`) VALUES ('+','.join(q(v) for v in [code,a['api_path'],a['api_method'],a['permission_key'],a['scene']])+',1,1,1,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());')
    def visit(items,parent=None):
        for m in items:
            if m.get('perms') in [a['api_path'] for a in routes]:
                pid=f"(SELECT p.id FROM `la_system_menu` p WHERE p.app_code={q(code)} AND p.source_menu_key={q(parent)} LIMIT 1)"
                lines.append('INSERT INTO `la_system_menu` (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`selected`,`params`,`is_cache`,`is_show`,`is_disable`,`app_code`,`source`,`source_menu_key`,`is_core`,`create_time`,`update_time`) SELECT '+','.join([pid,q('A'),q(m['name']),q(''),'0',q(m['perms']),q(''),q(''),q(''),q(''),'0','1','0',q(code),q('app'),q(m['source_menu_key']),'0','UNIX_TIMESTAMP()','UNIX_TIMESTAMP()'])+f' FROM DUAL WHERE {pid} IS NOT NULL AND NOT EXISTS (SELECT 1 FROM `la_system_menu` m WHERE m.app_code={q(code)} AND m.source_menu_key={q(m["source_menu_key"])});')
            visit(m.get('children',[]),m.get('source_menu_key'))
    visit(menus)
    text='\n'.join(lines)+'\n'
    (app/'migrations/20261111_zz_portable_inspirations.sql').write_text(text)
    if code!='aigc_product_video':
        replace_block(app/'migrations/install.sql','portable inspiration mapping',ddl)
        directory=app/'data/inspirations'; directory.mkdir(parents=True,exist_ok=True)
        (directory/'README.md').write_text('''# 跨独立站点内置灵感模板

平台后台“设为内置模板”生成此目录中的 JSON 源定义。图片、动作参考视频和预览成片继续引用原公开 OSS/COS/七牛文件，不包含媒体二进制、存储密钥、临时签名或本站上传记录 ID。

新建及更新的 JSON 需纳入下一次源码交付；其他站点更新后自动同步为平台模板，已启用应用的租户可查看和同款。目标站点需同步、上架相同模型及规格，不会自动替换模型。普通租户模板不会自动公开。

平台可调整内置项名称、状态、排序；更改素材和脚本时编辑原模板后“更新内置模板”。更新有版本校验，不覆盖租户自建内容。下架与删除保留定义及历史媒体引用，请勿直接删除已经分发的 JSON 或共享 OSS 对象。
''')
    marker=f'{code} portable inspiration contract'
    for path in [app/'bootstrap.sql',root/'public/install/db/like.sql',root/'upgrade'/f'upgrade_2026111{1 if code=="aigc_story_commerce" else 0}_{code}.sql',root/'public/upgrade'/f'upgrade_2026111{1 if code=="aigc_story_commerce" else 0}_{code}.sql']:
        replace_block(path,marker,text)
    all_sql.append('-- '+code+'\n'+text)
text='\n'.join(all_sql)
for path in [root/'upgrade/upgrade_20261111_studio_builtin_inspirations.sql',root/'public/upgrade/upgrade_20261111_studio_builtin_inspirations.sql']:
    path.write_text(text)
