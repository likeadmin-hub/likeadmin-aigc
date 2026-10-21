import fs from 'node:fs'
import path from 'node:path'
import os from 'node:os'
import { execFileSync } from 'node:child_process'
import test from 'node:test'
import assert from 'node:assert/strict'
import { fileURLToPath } from 'node:url'
import { decorationPresetAssets } from '../../scripts/decoration-preset-assets.mjs'
const here=path.dirname(fileURLToPath(import.meta.url)), server=path.resolve(here,'../..')
function fixture(t){const root=fs.mkdtempSync(path.join(os.tmpdir(),'decoration-package-'));t.after(()=>fs.rmSync(root,{recursive:true,force:true}));return root}
function write(root,p,body){fs.mkdirSync(path.dirname(path.join(root,p)),{recursive:true});fs.writeFileSync(path.join(root,p),body)}
const preset='app/common/service/decorate/presets/test.json'
test('nested resources and query URLs resolve once; external and tenant uploads are excluded',t=>{
 const root=fixture(t);write(root,'public/resource/a.png','original');write(root,preset,JSON.stringify({pages:[{image:'/resource/a.png?v=2',nested:{image:'resource/a.png'}}],remote:'https://cdn.example/resource/x.png',private:'/uploads/private.png'}));
 assert.deepEqual(decorationPresetAssets(root),{presets:[preset],assets:['public/resource/a.png']})
})
test('missing asset blocks delivery, including an unchanged reference',t=>{
 const root=fixture(t);write(root,preset,JSON.stringify({image:'/resource/missing.png'}));assert.throws(()=>decorationPresetAssets(root),/Missing decoration/)
})
test('resource traversal and directory references fail closed',t=>{
 const root=fixture(t);write(root,preset,JSON.stringify({image:'/resource/../secret'}));assert.throws(()=>decorationPresetAssets(root),/Unsafe/)
 write(root,preset,JSON.stringify({image:'/resource/dir'}));fs.mkdirSync(path.join(root,'public/resource/dir'),{recursive:true});assert.throws(()=>decorationPresetAssets(root),/not a file/)
})
test('symlinks cannot escape the release source',t=>{
 const root=fixture(t);write(root,'secret','private');fs.mkdirSync(path.join(root,'public/resource'),{recursive:true});fs.symlinkSync(path.join(root,'secret'),path.join(root,'public/resource/link.png'));write(root,preset,JSON.stringify({image:'/resource/link.png'}));assert.throws(()=>decorationPresetAssets(root),/symlink/)
})
test('current product preset has a complete local dependency set and keeps tenant artwork untouched',()=>{
 const result=decorationPresetAssets(server);assert.ok(result.presets.some(p=>p.endsWith('/logic-ai.json')));assert.ok(result.assets.length>=15);assert.ok(result.assets.includes('public/resource/image/decoration/logic-ai/redeem-entry-v4.png'));assert.ok(result.assets.includes('public/resource/image/decoration/logic-ai/works-empty-simple-v5.png'));assert.ok(result.assets.includes('public/resource/image/decoration/logic-ai/tab-icons.png'));assert.ok(result.assets.includes('public/resource/image/decoration/logic-ai/user-background-v2.png'));assert.ok(!result.assets.some(p=>p.includes('tab-icons-v4')||p.includes('user-background-v4')))
})
test('official staging includes unchanged images on an unrelated next-step update',t=>{
 const root=fixture(t),repo=path.join(root,'repo'),out=path.join(root,'staged');fs.mkdirSync(repo)
 write(repo,'config/project.php',"<?php return ['version' => '1.0.409'];\n")
 write(repo,'public/resource/image/brand.png','original-image-bytes')
 write(repo,preset,JSON.stringify({image:'/resource/image/brand.png'}))
 write(repo,'scripts/decoration-preset-assets.mjs',fs.readFileSync(path.join(server,'scripts/decoration-preset-assets.mjs')))
 const git=(...args)=>execFileSync('git',args,{cwd:repo,stdio:'pipe',encoding:'utf8'})
 git('init','-q');git('config','user.email','fixture@example.invalid');git('config','user.name','Local fixture');git('add','config/project.php',preset,'public/resource/image/brand.png','scripts/decoration-preset-assets.mjs');git('commit','-qm','base');const base=git('rev-parse','HEAD').trim()
 write(repo,'app/unrelated.php','<?php return true;');git('add','app/unrelated.php');git('commit','-qm','next')
 assert.equal(git('diff','--name-only',base,'HEAD').trim(),'app/unrelated.php')
 execFileSync(process.execPath,[path.join(server,'scripts/prepare-system-update.mjs'),'--repo',repo,'--base-ref',base,'--base-version','1.0.409','--version','1.0.410','--out-dir',out],{stdio:'pipe'})
 assert.equal(fs.readFileSync(path.join(out,'files/public/resource/image/brand.png'),'utf8'),'original-image-bytes')
 assert.ok(fs.existsSync(path.join(out,'files',preset)))
 const coverage=JSON.parse(fs.readFileSync(path.join(out,'release-change-manifest.json')))
 assert.ok(coverage.paths.some(row=>row.path==='public/resource/image/brand.png'&&row.delivery==='decoration-preset-dependency'))
 const manifest=JSON.parse(fs.readFileSync(path.join(out,'update.json')));assert.ok(!manifest.full_replace_dirs.includes('public/resource'))
 // A signed/package exporter includes all files/ children; assert the actual
 // staged archive roundtrip too, rather than checking only source references.
 const zip=path.join(root,'next.zip');execFileSync('/usr/bin/zip',['-qr',zip,'files','update.json','release-change-manifest.json'],{cwd:out})
 const archived=execFileSync('/usr/bin/unzip',['-p',zip,'files/public/resource/image/brand.png'],{encoding:'utf8'});assert.equal(archived,'original-image-bytes')
})
