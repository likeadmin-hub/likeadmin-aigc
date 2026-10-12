#!/usr/bin/env node
// Official staging builder. package_update.py owns archives/signatures/version bump.
import fs from 'node:fs'
import path from 'node:path'
import crypto from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
const args=process.argv.slice(2)
const option=(key,fallback='')=>{const i=args.indexOf(key);return i<0?fallback:args[i+1]}
const repo=path.resolve(option('--repo',path.join(path.dirname(fileURLToPath(import.meta.url)),'..')))
const baseRef=option('--base-ref'), baseVersion=option('--base-version'), version=option('--version')
const out=path.resolve(option('--out-dir',path.join(repo,'runtime/system_update_staging',version)))
const bootstrap=args.includes('--runtime-bootstrap'), delegated=args.includes('--bootstrap-inner')
const bridge=args.includes('--bridge'), runtime=args.includes('--composer-runtime')
const composerSource=path.resolve(option('--composer-source',repo))
const fullDirs=['public/admin','public/platform','public/pc','public/_nuxt','public/media','public/mobile','public/mp-weixin']
const bridgePaths=new Set(['runtime-loader.php','think','public/index.php','scripts/composer-runtime-runner.php',
    'scripts/verify-composer-runtime.php','app/common/service/update/ComposerRuntimeUpdateService.php',
    'app/common/service/update/SystemPackageUpdateService.php','config/project.php'])
// Generate guards from the previous release, never from functional code.
// Unsupported or ambiguous base contexts fail before any staging is written.
const bridgeWorkerPatches=new Map([
    ['AiTaskWorker', [['            while ($running) {\n',16], ['                // Do not starve lightweight canvas previews while a tenant has\n',16,'before']]],
    ['CanvasSubAgentWorker', [['        while ($running && (!$once || $processed < $limit)) {\n',12]]],
    ['ShortDramaEpisodeWorker', [['        do {\n',12]]],
    ['ShortDramaPlanningWorker', [['        do {\n',12]]],
    ['ShortDramaCanvasAgentWorker', [['        do {\n',12]]],
    ['Crontab', [["        $lists = CrontabModel::where('status', CrontabEnum::START)->select()->toArray();\n",8,'before','return 0'], ['        foreach ($lists as $item) {\n',12]]],
].map(([name,patches])=>['app/common/command/'+name+'.php',patches]))
function bridgeWorker(relative,patches){
    let source=git('show',`${baseRef}:${relative}`)
    if(source.includes('aigc_runtime_generation_changed'))fail(`Bridge base already has an epoch guard: ${relative}`)
    for(const [context,spaces,position='after',action='break'] of patches){
        if(source.split(context).length!==2)fail(`Bridge worker context must occur exactly once: ${relative}`)
        const indent=' '.repeat(spaces)
        const guard=indent+'// Bridge epoch boundary: finish current work before retiring.\n'
            +indent+"if (\\function_exists('aigc_runtime_generation_changed') && \\aigc_runtime_generation_changed()) {\n"
            +indent+"    $output->writeln('Runtime generation changed; retiring for supervisor restart.');\n"
            +indent+'    '+action+';\n'+indent+'}\n'
        source=source.replace(context,position==='before'?guard+context:context+guard)
    }
    return source
}
function fail(message){throw new Error(message)}
function git(...command){return execFileSync('git',command,{cwd:repo,encoding:'utf8',maxBuffer:64*1024*1024})}
function safe(relative){
    if(!relative||relative.startsWith('/')||relative.includes('\\')||relative.split('/').some(p=>p==='..'||p==='.')||relative.includes('\0'))fail(`Unsafe release path: ${relative}`)
    return relative
}
function ignored(p){return /^(tests|docs|runtime)(\/|$)/.test(p)||/(^|\/)\.DS_Store$|\.log$/.test(p)||/^\.env(?:\.|$)|^config\/install\.lock$|^(?:public\/)?upgrade\/version\.json$|^public\/(uploads|storage|qrcode)(\/|$)/.test(p)||p==='AGENTS.md'||p==='.gitignore'}
function dependency(p){return p==='composer.json'||p==='composer.lock'||p.startsWith('vendor/')}
function copy(relative,destination){
    safe(relative)
    const source=path.join(repo,relative),target=path.join(out,destination)
    if(!fs.existsSync(source))fail(`Missing source ${relative}`)
    const visit=(p)=>{const s=fs.lstatSync(p);if(s.isSymbolicLink())fail(`Release source contains symlink: ${p}`);if(s.isDirectory())for(const name of fs.readdirSync(p))visit(path.join(p,name))}
    visit(source);fs.mkdirSync(path.dirname(target),{recursive:true});fs.cpSync(source,target,{recursive:true})
}
function json(p,value){fs.writeFileSync(path.join(out,p),JSON.stringify(value,null,2)+'\n')}
function compareVersion(value){const parts=value.split('.').map(Number);return parts[0]*1e9+parts[1]*1e5+parts[2]}
try {
    for(const v of [baseVersion,version])if(!/^\d+\.\d+\.\d+$/.test(v))fail('Use --base-version and --version x.y.z')
    const next=baseVersion.split('.');next[2]=String(Number(next[2])+1)
    if(next.join('.')!==version)fail('System staging requires the exact next step')
    if(!baseRef)fail('--base-ref is required')
    git('rev-parse','--verify',baseRef+'^{commit}')
    const baseProject=git('show',`${baseRef}:config/project.php`)
    if(baseProject.match(/['"]version['"]\s*=>\s*['"]([^'"]+)['"]/)?.[1]!==baseVersion)fail('Base Git tree version differs from --base-version')
    if(fs.existsSync(out)&&fs.readdirSync(out).length)fail('Staging directory must be new or empty; preserve prior artifacts')
    if(bootstrap){
        if(bridge||runtime||delegated||baseVersion!=='1.0.390'||version!=='1.0.391')fail('Runtime bootstrap is exclusively the complete 390 to 391 step')
        const inner=path.join(out,'bootstrap-payload')
        const childArgs=args.filter((a,i)=>a!=='--runtime-bootstrap' && a!=='--out-dir' && args[i-1]!=='--out-dir')
        execFileSync(process.execPath,[fileURLToPath(import.meta.url),...childArgs,'--out-dir',inner,'--composer-runtime','--bootstrap-inner'],{stdio:'inherit'})
        const pc='app/common/service/database/PcWechatMigration.php'
        const before=git('show',`${baseRef}:${pc}`),context="        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix))"
        const current=fs.readFileSync(path.join(repo,pc),'utf8'),start=current.indexOf("        $bootstrapPath='';"),end=current.indexOf(context)
        if(start<0||end<start||before.split(context).length!==2)fail('Legacy bootstrap hook context must match exactly')
        const patched=before.replace(context,current.slice(start,end)+context)
        const target=path.join(out,'files',pc);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,patched)
        for(const dir of ['sql/data','sql/structure','menus','rollback'])fs.mkdirSync(path.join(out,dir),{recursive:true})
        fs.writeFileSync(path.join(out,'sql/structure/000_runtime_bootstrap.sql'),'-- likeadmin:migrate-pc-wechat-bindings-v1\n-- likeadmin:system-runtime-bootstrap-v1\nSELECT 1;\n')
        fs.writeFileSync(path.join(out,'sql/structure/001_runtime_bootstrap_guard.sql'),'CALL la_system_runtime_bootstrap_not_completed_391();\n')
        const next=JSON.parse(fs.readFileSync(path.join(inner,'update.json')))
        json('update.json',{product_code:'likeadmin_aigc_saas',version:baseVersion,package_mode:'incremental',base_version:baseVersion,target_version:version,included_versions:[baseVersion,version],summary:next.summary,changelog:next.changelog,full_replace_dirs:[],delete_files:[],sql_order:['sql/structure/000_runtime_bootstrap.sql','sql/structure/001_runtime_bootstrap_guard.sql'],runtime_bootstrap:{schema:1,base_version:baseVersion,target_version:version,payload_dir:'bootstrap-payload',min_php:'8.2.0'}})
        fs.writeFileSync(path.join(out,'menus/README.txt'),'Application metadata is installed by the signed immutable payload.\n')
        fs.writeFileSync(path.join(out,'rollback/README.txt'),'The compatible legacy migration wrapper remains after failure; all feature code, dependency pointer, public assets and version markers are transactional.\n')
        console.log(JSON.stringify({ok:true,out,runtime_bootstrap:true,target_version:version}));process.exit(0)
    }
    if(delegated&&(baseVersion!=='1.0.390'||version!=='1.0.391'||!runtime))fail('Unsupported delegated runtime staging')

    if(out===repo||repo.startsWith(out+path.sep))fail('Staging directory must not contain the source repository')
    const changed=git('diff','--no-renames','--name-only',baseRef,'HEAD').trim().split('\n').filter(Boolean)
    const deleted=new Set(git('diff','--no-renames','--name-only','--diff-filter=D',baseRef,'HEAD').trim().split('\n').filter(Boolean))
    if(!bridge&&changed.some(dependency)&&!runtime)fail('Composer runtime changes require --composer-runtime')
    if(bridge&&runtime)fail('Bridge package must not contain new dependencies')
    if(bridge&&(baseVersion!=='1.0.390'||version!=='1.0.391'))fail('Worker bridge requires the supported 1.0.390 to 1.0.391 chain')
    const generatedWorkers=bridge?[...bridgeWorkerPatches].map(([p,patches])=>[p,bridgeWorker(p,patches)]):[]
    if(runtime&&!delegated&&compareVersion(baseVersion)<compareVersion('1.0.391'))fail('Install updater bridge 1.0.391 first')
    for(const dir of ['files','sql/data','sql/structure','menus','rollback'])fs.mkdirSync(path.join(out,dir),{recursive:true})
    const coverage=[], uncovered=[], sql=new Map(), remove=[], replaced=[]
    for(const p of changed){
        safe(p)
        if(ignored(p)){coverage.push({path:p,delivery:'source-only-or-protected'});continue}
        if(bridge&&bridgeWorkerPatches.has(p)){coverage.push({path:p,delivery:'bridge-base-worker-with-epoch-guard'});continue}
        if(bridge&&!bridgePaths.has(p)){coverage.push({path:p,delivery:'deferred-to-functional-step'});continue}
        if(dependency(p)){coverage.push({path:p,delivery:'composer-runtime'});continue}
        if(deleted.has(p)){
            if(/^(app|config|extend|route|upgrade)\//.test(p)||/^public\/(admin|platform|pc|_nuxt|media|mobile|mp-weixin|static)\//.test(p))remove.push(p)
            else fail(`Deletion needs release review: ${p}`)
            coverage.push({path:p,delivery:'delete_files'});continue
        }
        copy(p,'files/'+p)
        coverage.push({path:p,delivery:'files/'+p})
        if(/^(?:public\/)?upgrade\/.*\.sql$/.test(p)){
            const name=path.basename(p),contents=fs.readFileSync(path.join(repo,p),'utf8')
            if(sql.has(name)&&sql.get(name)!==contents)fail(`Upgrade SQL mirrors differ: ${name}`)
            sql.set(name,contents)
        }
    }
    if(bridge){
        for(const [p,contents] of generatedWorkers){
            const target=path.join(out,'files',p);fs.mkdirSync(path.dirname(target),{recursive:true});fs.writeFileSync(target,contents)
            if(!coverage.some(entry=>entry.path===p))coverage.push({path:p,delivery:'bridge-base-worker-with-epoch-guard'})
        }
        for(const p of bridgePaths)if(fs.existsSync(path.join(repo,p)))copy(p,'files/'+p)
        const projectPath=path.join(out,'files/config/project.php')
        fs.writeFileSync(projectPath,fs.readFileSync(projectPath,'utf8').replace(/(['"]version['"]\s*=>\s*['"])[^'"]+(['"])/,`$1${version}$2`))
    }
    else for(const dir of fullDirs){if(fs.existsSync(path.join(repo,dir))){copy(dir,'files/'+dir);replaced.push(dir)}}
    if(delegated)for(const relative of ['runtime-loader.php','think','public/index.php','scripts/composer-runtime-runner.php','scripts/verify-composer-runtime.php','app/common/service/update/ComposerRuntimeUpdateService.php','app/common/service/update/SystemRuntimeBootstrapService.php','app/common/service/update/ProjectUpdateProcessGate.php','app/common/service/update/SystemPackageUpdateService.php','app/common/service/update/AppPackageUpdateService.php','app/common/service/database/PcWechatMigration.php','app/common/service/app/AppRegistryService.php','app/common/service/app/AppMenuService.php','app/common/service/app/DefaultAppService.php','app/common/service/billing/PackageProvisionService.php','app/common/service/database/RuntimeMigrationPlan.php','app/common/service/database/RuntimeMetadataTransactionGate.php']){copy(relative,'files/'+relative);if(!coverage.some(entry=>entry.path===relative))coverage.push({path:relative,delivery:'bootstrap-required-runtime-file'})}

    if(delegated){
        const historical={}
        for(const relative of git('ls-tree','-r','--name-only',baseRef,'app/apps').trim().split('\n').filter(p=>/^app\/apps\/[^/]+\/migrations\/[^/]+\.sql$/.test(p))){
            const bytes=execFileSync('git',['show',`${baseRef}:${relative}`],{cwd:repo,maxBuffer:64*1024*1024})
            if(fs.existsSync(path.join(repo,relative))&&!bytes.equals(fs.readFileSync(path.join(repo,relative))))fail(`Historical migration changed; use a new filename: ${relative}`)
            historical[relative]=crypto.createHash('sha256').update(bytes).digest('hex')
        }
        const destination=path.join(out,'files/config/runtime_migration_baseline.php');fs.mkdirSync(path.dirname(destination),{recursive:true})
        fs.writeFileSync(destination,`<?php\n// Signed identity of genuine ${baseVersion} migrations; never replay customer data.\nreturn [\n${Object.entries(historical).map(([p,h])=>`    '${p}' => '${h}',`).join('\n')}\n];\n`)
        coverage.push({path:'config/runtime_migration_baseline.php',delivery:'generated-genuine-base-migration-identity'})
    }

    const notesPath=option('--notes')
    const changelog=notesPath?fs.readFileSync(notesPath,'utf8').split('\n').filter(line=>/^\d+\./.test(line)):['1. 更新后必须将网站及对应任务进程的 PHP 版本切换到 8.2。']
    const manifest={product_code:'likeadmin_aigc_saas',version:baseVersion,package_mode:'incremental',base_version:baseVersion,
        target_version:version,included_versions:[baseVersion,version],summary:changelog.join('\n'),changelog,
        full_replace_dirs:replaced,delete_files:remove.filter(p=>!replaced.some(d=>p.startsWith(d+'/'))).sort(),
        sql_order:[...sql.keys()].sort().map(n=>'sql/structure/'+n)}
    if(bridge)manifest.updater_bridge=true
    for(const [name,contents] of sql)fs.writeFileSync(path.join(out,'sql/structure',name),contents)
    if(runtime){
        for(const p of ['composer.json','composer.lock']){
            if(!fs.readFileSync(path.join(repo,p)).equals(fs.readFileSync(path.join(composerSource,p))))fail(`Production runtime differs from release ${p}`)
            copy(p,'composer-runtime/'+p)
        }
        const vendor=path.join(composerSource,'vendor')
        const checkedCopy=(directory,relative='vendor')=>{
            for(const name of fs.readdirSync(directory)){
                const source=path.join(directory,name),rel=relative+'/'+name,stat=fs.lstatSync(source)
                if(stat.isSymbolicLink())fail(`Runtime symlinks are forbidden: ${rel}`)
                if(name==='.DS_Store'||name.endsWith('.log')||name==='.phpunit.result.cache')continue
                if(stat.isDirectory())checkedCopy(source,rel)
                else {const dest=path.join(out,'composer-runtime',rel);fs.mkdirSync(path.dirname(dest),{recursive:true});fs.copyFileSync(source,dest)}
            }
        }
        checkedCopy(vendor)
        for(const p of ['vendor/autoload.php','vendor/composer/autoload_real.php','vendor/composer/installed.php'])if(!fs.existsSync(path.join(out,'composer-runtime',p)))fail(`Incomplete Composer runtime: ${p}`)
        manifest.composer_runtime={schema:1,min_updater_version:'1.0.391',min_php:'8.2.0',payload_dir:'composer-runtime',
            required_extensions:['ctype','curl','dom','fileinfo','filter','json','mbstring','openssl','pdo','pdo_mysql','tokenizer','xml','zip'],
            probe_classes:['think\\App','NeuronAI\\Agent\\Agent']}
    }
    json('update.json',manifest)
    json('release-change-manifest.json',{base_ref:baseRef,source_commit:git('rev-parse','HEAD').trim(),base_version:baseVersion,target_version:version,bridge,paths:coverage,uncovered})
    fs.writeFileSync(path.join(out,'menus/README.txt'),'Preserve tenant custom menus; packaged app metadata uses the existing synchronization service.\n')
    fs.writeFileSync(path.join(out,'rollback/README.txt'),'Back up the database and persistent data before upgrading. Runtime transaction restores code and its dependency pointer on failure. Additive SQL is journaled, never automatically reversed or replayed after an interrupted migration.\n')
    console.log(JSON.stringify({ok:true,out,target_version:version,changed_paths:changed.length,full_replace_dirs:replaced,sql_order:manifest.sql_order,composer_runtime:runtime,bridge},null,2))
}catch(error){console.error(error.message);process.exitCode=1}
