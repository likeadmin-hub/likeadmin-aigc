#!/usr/bin/env python3
"""Compare real 390 install and additive upgrade schemas with a real fresh install.
No application migration replay or business DML: every database is disposable.
"""
import argparse, pathlib, re, subprocess, time, uuid
root=pathlib.Path(__file__).resolve().parents[2]
p=argparse.ArgumentParser();p.add_argument('--baseline',type=pathlib.Path,default=root.parent/'fixtures/legacy-390/server');args=p.parse_args()
name='aigc-schema-fixture-'+uuid.uuid4().hex[:10]
def run(*command,data=None):
 r=subprocess.run(command,input=data,text=True,capture_output=True)
 if r.returncode:raise RuntimeError(r.stderr or r.stdout)
 return r.stdout
def sql(database,content):return run('docker','exec','-i',name,'mysql','-h127.0.0.1','-uroot','--default-character-set=utf8mb4','-N',database,data=content)
def shape(database):
 cols=sql(database,"SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION;")
 indexes=sql(database,"SELECT TABLE_NAME,INDEX_NAME,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() GROUP BY TABLE_NAME,INDEX_NAME ORDER BY TABLE_NAME,INDEX_NAME;")
 return {tuple(row.split('\t')) for row in cols.splitlines()}, {tuple(row.split('\t')) for row in indexes.splitlines()}
try:
 run('docker','run','-d','--name',name,'-e','MYSQL_ALLOW_EMPTY_PASSWORD=yes','mysql:8.0','--character-set-server=utf8mb4','--collation-server=utf8mb4_unicode_ci')
 for _ in range(60):
  if subprocess.run(['docker','exec',name,'mysqladmin','-h127.0.0.1','ping','--silent'],capture_output=True).returncode==0:break
  time.sleep(1)
 else:raise RuntimeError('Private MySQL startup timeout')
 for db,source in [('fixture_legacy',args.baseline/'public/install/db/like.sql'),('fixture_fresh',root/'public/install/db/like.sql')]:
  sql('mysql','CREATE DATABASE '+db+';');sql(db,source.read_text())
 before=shape('fixture_legacy')
 expected_fresh=shape('fixture_fresh');initial_missing=sorted(expected_fresh[0]-before[0]);print('Pure390 missing fresh columns before repairs: '+str(initial_missing),flush=True)
 assert ('la_aigc_short_drama_generation_task','canvas_id') in initial_missing and ('la_aigc_short_drama_asset','episode_id') in initial_missing,'Baseline was artificially pre-repaired'
 for prefix in ['la_','qa_']:
  db='fixture_legacy' if prefix=='la_' else 'fixture_custom';
  if prefix=='qa_':sql('mysql','CREATE DATABASE '+db+';');sql(db,(args.baseline/'public/install/db/like.sql').read_text().replace('la_',prefix))
  for repeat in range(2):
   for source in sorted((root/'upgrade').glob('20261011_*_schema.sql')):
    sql(db,source.read_text().replace('la_',prefix))
  actual=shape(db);actual=tuple({tuple(v.replace(prefix,'la_',1) if i==0 else v for i,v in enumerate(row)) for row in group} for group in actual)
  expected=shape('fixture_fresh');missing_cols=sorted(expected[0]-actual[0]);missing_indexes=sorted(expected[1]-actual[1])
  print(prefix+' fresh columns missing after pure390 + explicit repairs: '+str(missing_cols),flush=True)
  print(prefix+' fresh indexes missing after pure390 + explicit repairs: '+str(missing_indexes),flush=True)
  # Historical conditional ADDs reveal dependencies omitted from a first CREATE snapshot.
  dependencies=set();index_dependencies=set()
  for source in (root/'app/apps').glob('*/migrations/*.sql'):
   text=source.read_text()
   for match in re.finditer(r'ALTER TABLE\s+`([^`]+)`\s+ADD(?: COLUMN)?\s+`([^`]+)`',text,re.I):
    table,column=match.groups()
    if not re.fullmatch(r'la_\w+',table):
     variable=re.search(r'@(\w+)',table);assert variable,'Cannot resolve historical table: '+table
     assignments=list(re.finditer(r'SET\s+@'+variable[1]+r'\s*=\s*([^;]+);',text[:match.start()],re.I));assert assignments,'Historical table assignment missing'
     expression=assignments[-1][1];literal=re.search(r'la_\w+',expression)
     if literal:table=literal[0]
     else:
      concat=re.search(r"CONCAT\('la_',\s*'(\w+)'\)",expression,re.I);assert concat,'Unsupported historical table expression: '+expression
      table='la_'+concat[1]
    dependencies.add((table,column))
   for match in re.finditer(r'CREATE TABLE(?: IF NOT EXISTS)?\s+`(la_\w+)`\s*\((.*?)\)\s*ENGINE',text,re.S|re.I):
    dependencies.update((match[1],column) for column in re.findall(r'(?:^|,)\s*`([^`]+)`\s+\w+',match[2],re.M))
    index_dependencies.update((match[1],index) for index in re.findall(r'(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`([^`]+)`',match[2],re.I))
   index_dependencies.update(re.findall(r'ALTER TABLE\s+`(la_\w+)`\s+ADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`([^`]+)`',text,re.I))
  absent=sorted(dependencies-actual[0]);print(prefix+' historical ADD columns still absent: '+str(absent),flush=True)
  absent_indexes=sorted(index_dependencies-{(table,index) for table,index,columns in actual[1]});print(prefix+' historical CREATE/ADD indexes still absent: '+str(absent_indexes),flush=True)
  assert not missing_cols and not missing_indexes and not absent and not absent_indexes,'Real schema parity failed; do not replay historical DML to hide missing columns/indexes'
  print('PASS '+prefix+' pure390 + repairs twice: '+str(len(actual[0]))+' columns, '+str(len(actual[1]))+' indexes; no missing fresh/historical required columns or indexes',flush=True)
finally:
 subprocess.run(['docker','rm','-f','-v',name],capture_output=True)
 print('Cleaned private schema MySQL; no published ports or shared database.',flush=True)
