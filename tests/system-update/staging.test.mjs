import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { execFileSync, spawnSync } from 'node:child_process'
const script = new URL('../../scripts/prepare-system-update.mjs', import.meta.url)
function fixture() {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'aigc-update-staging-'))
    const repo = path.join(dir, 'server')
    fs.mkdirSync(repo)
    const write = (p, value) => { fs.mkdirSync(path.dirname(path.join(repo,p)), {recursive:true}); fs.writeFileSync(path.join(repo,p),value) }
    const git = (...args) => execFileSync('git',args,{cwd:repo,stdio:'pipe'})
    git('init');git('config','user.name','Fixture');git('config','user.email','fixture@example.test')
    write('config/project.php', "<?php return ['version'=>'1.0.391'];")
    write('app/example.php','<?php echo "old";')
    write('app/removed.php','<?php')
    write('app/common/service/SubmitLockService.php','<?php // baseline submit-lock dependency')
    write('composer.json','{}');write('composer.lock','{"packages":[]}')
    write('vendor/autoload.php','<?php');write('vendor/composer/autoload_real.php','<?php')
    write('vendor/composer/installed.php','<?php return [];')
    git('add','config','app','composer.json','composer.lock','vendor');git('commit','-m','base')
    const base = git('rev-parse','HEAD').toString().trim()
    write('config/project.php', "<?php return ['version'=>'1.0.392'];")
    write('app/example.php','<?php echo "new";');fs.unlinkSync(path.join(repo,'app/removed.php'))
    write('tests/irrelevant.php','test fixture only')
    write('public/admin/index.html','<script src="/admin/assets/current.js"></script>')
    write('public/admin/assets/current.js','console.log("current")')
    write('upgrade/20261011_repair.sql','CREATE TABLE IF NOT EXISTS la_fixture (id INT);')
    write('public/upgrade/20261011_repair.sql','CREATE TABLE IF NOT EXISTS la_fixture (id INT);')
    write('vendor/fixture/new.php','<?php')
    git('add','config','app','tests','public','upgrade','vendor');git('commit','-m','target')
    write('.env','secret');write('runtime/business.json','private state');write('upgrade/version.json','{"version":"1.0.392"}')
    return {dir,repo,base,write}
}
test('staging covers changes, complete frontend and standalone runtime without protected paths', () => {
    const f = fixture(); const out = path.join(f.dir,'stage')
    try {
        const result = spawnSync(process.execPath,[script.pathname,'--repo',f.repo,'--base-ref',f.base,'--base-version','1.0.391','--version','1.0.392','--out-dir',out,'--composer-runtime'],{encoding:'utf8'})
        assert.equal(result.status,0,result.stderr)
        const manifest = JSON.parse(fs.readFileSync(path.join(out,'update.json')))
        assert.equal(manifest.version,'1.0.391','packager bumps predecessor normally')
        assert.deepEqual(manifest.delete_files,['app/removed.php'])
        assert.deepEqual(manifest.sql_order,['sql/structure/20261011_repair.sql'])
        assert.ok(manifest.full_replace_dirs.includes('public/admin'))
        assert.equal(manifest.composer_runtime.min_updater_version,'1.0.391')
        for (const p of ['files/app/example.php','files/public/admin/assets/current.js','composer-runtime/composer.lock','composer-runtime/vendor/fixture/new.php']) assert.ok(fs.existsSync(path.join(out,p)),p)
        for (const p of ['files/.env','files/runtime','files/upgrade/version.json','files/vendor','files/composer.lock','files/tests']) assert.equal(fs.existsSync(path.join(out,p)),false,p)
        const coverage=JSON.parse(fs.readFileSync(path.join(out,'release-change-manifest.json')))
        assert.equal(coverage.uncovered.length,0)
    } finally { fs.rmSync(f.dir,{recursive:true,force:true}) }
})
test('dependency changes cannot silently ship without standalone runtime', () => {
    const f=fixture()
    try {
        const result=spawnSync(process.execPath,[script.pathname,'--repo',f.repo,'--base-ref',f.base,'--base-version','1.0.391','--version','1.0.392','--out-dir',path.join(f.dir,'bad')],{encoding:'utf8'})
        assert.notEqual(result.status,0)
        assert.match(result.stderr,/Composer runtime changes require --composer-runtime/)
    } finally { fs.rmSync(f.dir,{recursive:true,force:true}) }
})
test('changed app submitters include their unchanged submit-lock dependency', () => {
    const f=fixture(),out=path.join(f.dir,'submit-lock-stage')
    try {
        f.write('app/common/service/app/FixtureService.php','<?php SubmitLockService::tryAcquire($key);')
        execFileSync('git',['add','app/common/service/app/FixtureService.php'],{cwd:f.repo})
        execFileSync('git',['commit','-m','new submitter'],{cwd:f.repo,stdio:'pipe'})
        const result=spawnSync(process.execPath,[script.pathname,'--repo',f.repo,'--base-ref',f.base,'--base-version','1.0.391','--version','1.0.392','--out-dir',out,'--composer-runtime'],{encoding:'utf8'})
        assert.equal(result.status,0,result.stderr)
        const relative='app/common/service/SubmitLockService.php'
        assert.equal(fs.readFileSync(path.join(out,'files',relative),'utf8'),fs.readFileSync(path.join(f.repo,relative),'utf8'))
        const coverage=JSON.parse(fs.readFileSync(path.join(out,'release-change-manifest.json')))
        assert.ok(coverage.paths.some(row=>row.path===relative&&row.delivery==='required-runtime-dependency'))
    } finally {fs.rmSync(f.dir,{recursive:true,force:true})}
})

const bridgeWorkers=['AiTaskWorker','CanvasSubAgentWorker','ShortDramaEpisodeWorker','ShortDramaPlanningWorker','ShortDramaCanvasAgentWorker','Crontab']
function bridgeFixture(changeBase=source=>source) {
    const f=fixture()
    f.git=(...args)=>execFileSync('git',args,{cwd:f.repo,encoding:'utf8',stdio:'pipe'})
    f.write('config/project.php',"<?php return ['version'=>'1.0.390'];")
    f.original={}
    for(const name of bridgeWorkers){
        const source=fs.readFileSync(new URL('./fixtures/bridge-1.0.390/'+name+'.php',import.meta.url),'utf8')
        const relative='app/common/command/'+name+'.php'
        f.original[name]=source;f.write(relative,changeBase(source,name))
    }
    f.git('add','.');f.git('commit','-m','real old worker fixture')
    f.base=f.git('rev-parse','HEAD').trim()
    f.write('config/project.php',"<?php return ['version'=>'1.0.392'];")
    for(const name of bridgeWorkers)f.write('app/common/command/'+name+'.php',f.original[name]+"\n// NEW_BUSINESS_NEURON_SENTINEL\n")
    f.git('add','.');f.git('commit','-m','functional source')
    f.run=()=>spawnSync(process.execPath,[script.pathname,'--repo',f.repo,'--base-ref',f.base,'--base-version','1.0.390','--version','1.0.391','--out-dir',path.join(f.dir,'bridge'),'--bridge'],{encoding:'utf8'})
    return f
}
test('bridge workers derive solely from base source plus safe epoch boundaries',()=>{
    const f=bridgeFixture()
    try {
        const result=f.run();assert.equal(result.status,0,result.stderr)
        const manifest=JSON.parse(fs.readFileSync(path.join(f.dir,'bridge/update.json')))
        assert.deepEqual(manifest.sql_order,[]);assert.equal(manifest.composer_runtime,undefined);assert.equal(manifest.updater_bridge,true)
        for(const name of bridgeWorkers){
            const output=fs.readFileSync(path.join(f.dir,'bridge/files/app/common/command/'+name+'.php'),'utf8')
            assert.match(output,/aigc_runtime_generation_changed/,name)
            assert.doesNotMatch(output,/NEW_BUSINESS_NEURON_SENTINEL/,name)
            // Removing only the inserted guard must recover every original byte.
            assert.equal(output.replace(/^[ \t]*\/\/ Bridge epoch boundary: finish current work before retiring\.\n[\s\S]*?^[ \t]*\}\n/gm,''),f.original[name],name)
        }
    } finally {fs.rmSync(f.dir,{recursive:true,force:true})}
})
for(const mode of ['missing','ambiguous'])test('bridge fails closed on '+mode+' source context',()=>{
    const f=bridgeFixture((source,name)=>name!=='AiTaskWorker'?source:mode==='missing'?source.replace('            while ($running) {','            while (true) {'):source+'\n            while ($running) {\n')
    try {const result=f.run();assert.notEqual(result.status,0);assert.match(result.stderr,/Bridge worker context must occur exactly once/)}
    finally {fs.rmSync(f.dir,{recursive:true,force:true})}
})

function baselineFixture() {
    const f=fixture();const git=(...args)=>execFileSync('git',args,{cwd:f.repo,encoding:'utf8',stdio:'pipe'})
    f.write('config/project.php',"<?php return ['version'=>'1.0.390'];")
    f.write('app/apps/fixture/migrations/history.sql','UPDATE la_fixture SET customer_price=0;')
    git('add','.');git('commit','-m','genuine predecessor SQL identity');f.base=git('rev-parse','HEAD').trim()
    f.write('config/project.php',"<?php return ['version'=>'1.0.391'];")
    f.write('app/apps/fixture/migrations/new.sql','CREATE TABLE IF NOT EXISTS la_new(id int);')
    const source=fs.readFileSync(script,'utf8')
    const helpers=source.match(/if\(delegated\)for\(const relative of \[(.*?)\]\)/s)[1].match(/'([^']+)'/g).map(v=>v.slice(1,-1))
    for(const helper of helpers)f.write(helper,'<?php // fixture helper')
    git('add','.');git('commit','-m','target')
    f.run=out=>spawnSync(process.execPath,[script.pathname,'--repo',f.repo,'--base-ref',f.base,'--base-version','1.0.390','--version','1.0.391','--out-dir',path.join(f.dir,out),'--composer-runtime','--bootstrap-inner'],{encoding:'utf8'})
    return f
}
test('signed runtime baseline derives migration identity solely from predecessor Git bytes',()=>{
    const f=baselineFixture()
    try {
        const result=f.run('stage');assert.equal(result.status,0,result.stderr)
        const baseline=fs.readFileSync(path.join(f.dir,'stage/files/config/runtime_migration_baseline.php'),'utf8')
        assert.match(baseline,/app\/apps\/fixture\/migrations\/history.sql/)
        assert.doesNotMatch(baseline,/migrations\/new.sql/)
        assert.doesNotMatch(baseline,/UPDATE la_fixture/)
        f.write('app/apps/fixture/migrations/history.sql','UPDATE la_fixture SET customer_price=100;')
        const changed=f.run('changed');assert.notEqual(changed.status,0);assert.match(changed.stderr,/Historical migration changed; use a new filename/)
    } finally {fs.rmSync(f.dir,{recursive:true,force:true})}
})
