import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import {execFileSync,spawnSync} from 'node:child_process'
import {fileURLToPath} from 'node:url'
const source=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..')
const names=[
'20261011_open_platform.sql','20261011_open_platform_delivery.sql','20261011_open_platform_docs.sql',
'20261011_open_platform_guides.sql','20261011_open_platform_music_guide.sql','20261011_open_platform_music_guide_v2.sql',
'20261011_open_platform_rate.sql','20261012_open_platform_music_guide_v3.sql',
'upgrade_20261012_effect_config_capacity.sql','upgrade_20261012_effect_configuration_menu.sql',
'20261012_workspace_asset_labels.sql','20261012_workspace_asset_state.sql','20261012_workspace_local_uploads.sql']
function fixture(){
 const dir=fs.mkdtempSync(path.join(os.tmpdir(),'release-migrations-'))
 const write=(p,v)=>{const full=path.join(dir,p);fs.mkdirSync(path.dirname(full),{recursive:true});fs.writeFileSync(full,v)}
 const git=(...args)=>execFileSync('git',['-c','user.name=Fixture','-c','user.email=fixture@example.invalid',...args],{cwd:dir,encoding:'utf8'})
 git('init','-q');write('config/project.php',"<?php return ['version'=>'1.0.391'];\n")
 const updater='app/common/service/update/SystemPackageUpdateService.php';write(updater,fs.readFileSync(path.join(source,updater)))
 const historical='app/apps/aigc_video/migrations/install.sql';write(historical,'CREATE TABLE IF NOT EXISTS la_aigc_video_config (config_json TEXT);\n')
 git('add','config/project.php',historical,updater);git('commit','-qm','Fixture391');const base=git('rev-parse','HEAD').trim()
 for(const name of names){
  let input='upgrade/'+name
  if(name.includes('open_platform'))input='app/apps/open_platform/migrations/'+name
  if(name.startsWith('upgrade_'))input='app/apps/aigc_video/migrations/'+name
  write(input,fs.readFileSync(path.join(source,input)))
  if(fs.existsSync(path.join(source,'upgrade',name))){write('upgrade/'+name,fs.readFileSync(path.join(source,'upgrade',name)));write('public/upgrade/'+name,fs.readFileSync(path.join(source,'public/upgrade',name)))}
 }
 for(const code of ['aigc_video','open_platform']){
  write('app/apps/'+code+'/manifest.json',JSON.stringify({code,is_builtin:1}))
  write('app/apps/'+code+'/api_schema.json','{}')
  for(const kind of ['menus','permissions','frontend'])write('app/apps/'+code+'/'+kind+'/fixture.json','{}')
 }
 git('add','app','upgrade','public');git('commit','-qm','FeatureSQL')
 const run=()=>spawnSync(process.execPath,[path.join(source,'scripts/prepare-system-update.mjs'),'--repo',dir,'--base-ref',base,'--base-version','1.0.391','--version','1.0.392','--out-dir',path.join(dir,'stage')],{encoding:'utf8'})
 return{dir,write,git,run,historical,cleanup:()=>fs.rmSync(dir,{recursive:true,force:true})}
}
test('all 13 post391 migrations are executable and mapped to their exact source bytes',()=>{
 const f=fixture();try{
  const result=f.run();assert.equal(result.status,0,result.stderr)
  const manifest=JSON.parse(fs.readFileSync(path.join(f.dir,'stage/update.json')))
  assert.deepEqual(manifest.sql_order,[...names].sort().map(n=>'sql/structure/'+n))
  const coverage=JSON.parse(fs.readFileSync(path.join(f.dir,'stage/release-change-manifest.json')))
  assert.equal(coverage.migrations.length,13)
  for(const code of ['aigc_video','open_platform'])assert.ok(fs.existsSync(path.join(f.dir,'stage/files/app/apps',code,'manifest.json')))
  assert.equal(coverage.migrations.filter(m=>m.execution_owner==='app/runtime').length,10)
  assert.equal(coverage.migrations.filter(m=>m.execution_owner==='system').length,3)
  for(const migration of coverage.migrations){assert.ok(migration.source_paths.length);for(const input of migration.source_paths)assert.deepEqual(fs.readFileSync(path.join(f.dir,input)),fs.readFileSync(path.join(f.dir,'stage',migration.package_path)))}
  assert.ok(manifest.sql_order.indexOf('sql/structure/20261011_open_platform.sql')<manifest.sql_order.indexOf('sql/structure/20261011_open_platform_docs.sql'))
  assert.ok(manifest.sql_order.indexOf('sql/structure/20261011_open_platform_music_guide_v2.sql')<manifest.sql_order.indexOf('sql/structure/20261012_open_platform_music_guide_v3.sql'))
 }finally{f.cleanup()}
})
test('changed historical app migration bytes are rejected in ordinary391 next update',()=>{
 const f=fixture();try{f.write(f.historical,'CREATE TABLE IF NOT EXISTS la_aigc_video_config (config_json MEDIUMTEXT);\n');f.git('add',f.historical);f.git('commit','-qm','BadRewrite');const result=f.run();assert.notEqual(result.status,0);assert.match(result.stderr,/Historical migration changed/)}finally{f.cleanup()}
})
test('root and app migration mirrors must match rather than silently winning basename collision',()=>{
 const f=fixture();try{f.write('upgrade/20261011_open_platform_docs.sql','INSERT INTO la_wrong VALUES(1);\n');f.git('add','upgrade/20261011_open_platform_docs.sql');f.git('commit','-qm','BadMirror');const result=f.run();assert.notEqual(result.status,0);assert.match(result.stderr,/SQL mirrors differ/)}finally{f.cleanup()}
})
