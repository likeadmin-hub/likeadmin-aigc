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
