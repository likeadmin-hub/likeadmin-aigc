#!/usr/bin/env node
import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { existsSync, readFileSync, readdirSync, statSync, lstatSync, mkdtempSync, rmSync } from 'node:fs'
import os from 'node:os'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const target = process.argv[2]
const delegated = process.argv.includes('--bootstrap-inner')

if (!target) {
    fail('Usage: node scripts/verify-system-update-package.mjs <package.zip|staging-dir>')
}

const requiredPaths = [
    'update.json',
    'files/',
    'sql/data/',
    'sql/structure/',
    'menus/',
    'rollback/',
    'signature.json'
]

const protectedPatterns = [
    /^files\/(?:public\/)?upgrade\/version\.json$/,
    /^files\/\.env$/,
    /(^|\/)\.env(\.|$)/,
    /^files\/config\/install\.lock$/,
    /^files\/runtime\//,
    /^files\/public\/uploads\//,
    /^files\/public\/storage\//,
    /^files\/public\/qrcode\//,
    /(^|\/)\.DS_Store$/,
    /\.log$/
]

const incrementalComposerPaths = [
    'files/composer.json',
    'files/composer.lock',
    'files/vendor/'
]

const fullReplaceAllowedDirs = new Set([
    'public/admin',
    'public/platform',
    'public/pc',
    'public/_nuxt',
    'public/media',
    'public/mobile',
    'public/mp-weixin',
    'public/static'
])

const deleteAllowedPrefixes = [
    'app/',
    'config/',
    'extend/',
    'public/admin/',
    'public/platform/',
    'public/pc/',
    'public/_nuxt/',
    'public/media/',
    'public/mobile/',
    'public/mp-weixin/',
    'public/static/',
    'route/',
    'upgrade/'
]

const bridgeAppCodes = [
    'aigc_image',
    'aigc_video',
    'aigc_digital_human',
    'aigc_canvas',
    'aigc_llm',
    'image_human'
]

const bridgeAppRequiredPaths = [
    'manifest.json',
    'api_schema.json',
    'menus/platform.json',
    'menus/tenant.json',
    'permissions/tenant.json',
    'migrations/',
    'frontend/'
]

const absoluteTarget = path.resolve(target)
if (!existsSync(absoluteTarget)) {
    fail(`Target not found: ${absoluteTarget}`)
}

const stat = statSync(absoluteTarget)
const entries = stat.isDirectory() ? listDirectoryEntries(absoluteTarget) : listZipEntries(absoluteTarget)
const normalized = new Set(entries.map(normalizeEntry))
for(const entry of normalized)if(entry.startsWith('/')||entry.includes('\\')||/^[A-Za-z]:/.test(entry)||entry.split('/').some(p=>p==='.'||p==='..'))fail('Unsafe package entry: '+entry)
if(!stat.isDirectory()&&execFileSync('unzip',['-Z','-l',absoluteTarget],{encoding:'utf8',maxBuffer:32*1024*1024}).split('\n').some(line=>/^l[rwx-]{9}/.test(line)))fail('Package symlinks are forbidden')


for (const required of requiredPaths) {
    if (!hasEntry(normalized, required)) {
        fail(`Missing required package path: ${required}`)
    }
}

for (const entry of normalized) {
    // Allow only the runtime parent entries and semver-scoped mini-program
    // artifact archive. Other runtime data remains protected.
    const isWechatArtifact = entry === 'files/runtime/'
        || entry === 'files/runtime/wechat-artifacts/'
        || /^files\/runtime\/wechat-artifacts\/\d+\.\d+\.\d+(?:\/|$)/.test(entry)
    if (protectedPatterns.some((pattern) => !(isWechatArtifact && pattern.source === '^files\\/runtime\\/') && pattern.test(entry))) {
        fail(`Protected path must not be packaged: ${entry}`)
    }
}

if (normalized.has('files/think/') || normalized.has('files/think/think')) {
    fail('The ThinkPHP CLI entry must be packaged as files/think, not as a directory')
}

const signature = readSignature(absoluteTarget, stat.isDirectory())
const updateManifest = readPackageJson(absoluteTarget, stat.isDirectory(), 'update.json')
if(updateManifest.runtime_bootstrap && !stat.isDirectory()){
    const temporary=mkdtempSync(path.join(os.tmpdir(),'aigc-bootstrap-verify-'))
    try{execFileSync('unzip',['-oq',absoluteTarget,'-d',temporary]);console.log(execFileSync(process.execPath,[fileURLToPath(import.meta.url),temporary],{encoding:'utf8',maxBuffer:32*1024*1024}).trim())}
    finally{rmSync(temporary,{recursive:true,force:true})}
    process.exit(0)
}
verifyRuntimeBootstrap(updateManifest,normalized)

verifyUpdaterBridge(updateManifest, normalized)
verifyIncrementalManifest(updateManifest, normalized)
verifyIncrementalComposerRuntime(updateManifest, normalized)
verifyIncrementalSqlSafety(absoluteTarget, stat.isDirectory(), updateManifest, normalized)
verifyCumulativeUpgradeSql(updateManifest, normalized)
verifyMigrationSources(absoluteTarget,stat.isDirectory(),updateManifest,normalized)
verifyPlatformEntrypoints(absoluteTarget, stat.isDirectory(), normalized, updateManifest)
const signatureFiles = normalizeSignature(signature)
verifySubmitLockDependency(absoluteTarget, stat.isDirectory(), normalized)
if (!signatureFiles.length) {
    fail('signature.json has no file checksum entries')
}
if (!signature.sha256 || typeof signature.sha256 !== 'object' || Array.isArray(signature.sha256)) {
    fail('signature.json must include legacy sha256 map for installed updaters')
}
if (!Array.isArray(signature.files)) {
    fail('signature.json must include files array for package metadata inspection')
}
if (!signatureFiles.some((file) => file.path === 'update.json')) {
    fail('signature.json does not include update.json')
}
if (!hasEntry(normalized, 'sql/data/')) {
    fail('package does not include sql/data compatibility directory')
}

for (const file of signatureFiles) {
    if (!normalized.has(file.path)) {
        fail(`signature.json references missing package file: ${file.path}`)
    }
    if (stat.isDirectory()) {
        const filePath = path.join(absoluteTarget, file.path)
        if (!existsSync(filePath)) {
            fail(`signature.json references missing file: ${file.path}`)
        }
        const sha256 = createHash('sha256').update(readFileSync(filePath)).digest('hex')
        if (sha256 !== file.sha256) {
            fail(`Checksum mismatch: ${file.path}`)
        }
    }
}

console.log(JSON.stringify({
    ok: true,
    target: absoluteTarget,
    package_mode: updateManifest.package_mode || 'legacy',
    entries: normalized.size,
    signature_files: signatureFiles.length
}, null, 2))

function listZipEntries(zipPath) {
    try {
        return execFileSync('unzip', ['-Z1', zipPath], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 })
            .split('\n')
            .filter(Boolean)
    } catch (error) {
        fail(`Unable to list zip entries with unzip: ${error.message}`)
    }
}

function listDirectoryEntries(root) {
    const results = []
    walk(root, '')
    return results

    function walk(dir, prefix) {
        const names = execFileSync('find', [dir, '-maxdepth', '1', '-mindepth', '1'], { encoding: 'utf8' })
            .split('\n')
            .filter(Boolean)
        for (const item of names) {
            const name = path.basename(item)
            const relative = normalizeEntry(path.posix.join(prefix, name))
            const itemStat = lstatSync(item)
            if(itemStat.isSymbolicLink())fail(`Package symlink forbidden: ${relative}`)
            if (itemStat.isDirectory()) {
                results.push(`${relative}/`)
                walk(item, relative)
            } else if (itemStat.isFile()) {
                results.push(relative)
            }
        }
    }
}

function readSignature(targetPath, isDirectory) {
    return readPackageJson(targetPath, isDirectory, 'signature.json')
}

function readPackageJson(targetPath, isDirectory, file) {
    const raw = readPackageText(targetPath, isDirectory, file)
    try {
        return JSON.parse(raw)
    } catch (error) {
        fail(`${file} is not valid JSON: ${error.message}`)
    }
}

function verifyPlatformEntrypoints(targetPath, isDirectory, entries, manifest) {
    const indexPath = 'files/public/platform/index.html'
    if (!entries.has(indexPath)) return

    const references = [...readPackageText(targetPath, isDirectory, indexPath)
        .matchAll(/(?:src|href)=["']\/platform\/([^"'?#]+)(?:[?#][^"']*)?["']/g)]
        .map((match) => `files/public/platform/${decodeURI(match[1])}`)
    const missing = references.filter((entry) => !entries.has(entry))
    if (missing.length) {
        fail(`platform index.html references missing static assets: ${missing.join(', ')}`)
    }
}

function verifyRuntimeBootstrap(manifest,entries){
    if(delegated && (manifest.base_version!=='1.0.390'||manifest.version!=='1.0.391'||manifest.target_version!=='1.0.391'||!manifest.composer_runtime||manifest.runtime_bootstrap))fail('Unsupported delegated bootstrap payload')
    if(!manifest.runtime_bootstrap)return
    const spec=manifest.runtime_bootstrap
    if(spec.schema!==1||spec.base_version!=='1.0.390'||spec.target_version!=='1.0.391'||spec.payload_dir!=='bootstrap-payload'||spec.min_php!=='8.2.0'||manifest.base_version!=='1.0.390'||manifest.version!=='1.0.391'||manifest.target_version!=='1.0.391'||manifest.package_mode!=='incremental'||manifest.composer_runtime||manifest.updater_bridge)fail('Invalid single-operation bootstrap schema')
    const actual=[...entries].filter(p=>p.startsWith('files/')&&!p.endsWith('/'))
    if(actual.length!==1||actual[0]!=='files/app/common/service/database/PcWechatMigration.php'||manifest.full_replace_dirs.length||manifest.delete_files.length)fail('Bootstrap outer files exceed compatible wrapper allowlist')
    const sql={'sql/structure/000_runtime_bootstrap.sql':'-- likeadmin:migrate-pc-wechat-bindings-v1\n-- likeadmin:system-runtime-bootstrap-v1\nSELECT 1;\n','sql/structure/001_runtime_bootstrap_guard.sql':'CALL la_system_runtime_bootstrap_not_completed_391();\n'}
    if(JSON.stringify(manifest.sql_order)!==JSON.stringify(Object.keys(sql)))fail('Bootstrap handoff gates differ')
    const actualSql=[...entries].filter(p=>p.startsWith('sql/')&&!p.endsWith('/'))
    if(actualSql.length!==2)fail('Bootstrap outer SQL is not exclusively control gates')
    for(const [relative,content] of Object.entries(sql))if(readPackageText(absoluteTarget,true,relative)!==content)fail('Bootstrap SQL gate changed')
    const files=new Set(normalizeSignature(signature).map(p=>p.path))
    for(const entry of entries)if(!entry.endsWith('/')&&entry!=='signature.json'&&!files.has(entry))fail('Unsigned bootstrap file: '+entry)
    execFileSync(process.execPath,[fileURLToPath(import.meta.url),path.join(absoluteTarget,'bootstrap-payload'),'--bootstrap-inner'],{stdio:'pipe',maxBuffer:32*1024*1024})
}

function verifyUpdaterBridge(manifest, entries) {
    if ('updater_bridge' in manifest && typeof manifest.updater_bridge !== 'boolean') fail('updater_bridge must be a boolean')
    if (manifest.updater_bridge !== true) return
    const reject = message => fail('Invalid updater_bridge: ' + message)
    if (manifest.package_mode !== 'incremental' || manifest.base_version !== '1.0.390' || manifest.target_version !== '1.0.391' || manifest.version !== '1.0.391') reject('only the exact 1.0.390 to 1.0.391 step is supported')
    if ('composer_runtime' in manifest || [...entries].some(entry => /^(?:composer-runtime|vendor)(?:\/|$)|^composer\.(?:json|lock)$/.test(entry))) reject('dependencies must wait for the functional release')
    for (const field of ['sql_order','delete_files','full_replace_dirs']) if (!Array.isArray(manifest[field]) || manifest[field].length) reject(field + ' must be empty')
    if (!Array.isArray(manifest.included_versions) || [...new Set(manifest.included_versions)].sort().join(',') !== '1.0.390,1.0.391') reject('included_versions must contain only supported endpoints')
    const allowed = new Set(['runtime-loader.php','think','public/index.php','scripts/composer-runtime-runner.php','scripts/verify-composer-runtime.php','app/common/service/update/ComposerRuntimeUpdateService.php','app/common/service/update/SystemPackageUpdateService.php','config/project.php',
        ...['AiTaskWorker','CanvasSubAgentWorker','ShortDramaEpisodeWorker','ShortDramaPlanningWorker','ShortDramaCanvasAgentWorker','Crontab'].map(name => 'app/common/command/' + name + '.php')])
    const actual = [...entries].filter(entry => entry.startsWith('files/') && !entry.endsWith('/')).map(entry => entry.slice(6))
    if ([...entries].some(entry => /\.sql$/i.test(entry))) reject('no SQL may be delivered')
    if (actual.length !== allowed.size || actual.some(entry => !allowed.has(entry))) reject('runtime files must match updater/worker allowlist exactly')
}

function verifyIncrementalManifest(manifest, entries) {
    if ((manifest.package_mode || '') !== 'incremental') return
    for (const field of ['base_version', 'target_version', 'included_versions', 'full_replace_dirs', 'delete_files', 'sql_order']) {
        if (!(field in manifest)) {
            fail(`incremental update.json missing field: ${field}`)
        }
    }
    if (String(manifest.target_version) !== String(manifest.version)) {
        fail('incremental update.json target_version must equal version')
    }
    if (!Array.isArray(manifest.included_versions) || manifest.included_versions.length === 0) {
        fail('incremental update.json included_versions must be a non-empty array')
    }
    if (!manifest.included_versions.map(String).includes(String(manifest.target_version))) {
        fail('incremental update.json included_versions must include target_version')
    }
    for (const field of ['full_replace_dirs', 'delete_files', 'sql_order']) {
        if (!Array.isArray(manifest[field])) {
            fail(`incremental update.json ${field} must be an array`)
        }
    }
    for (const rawDir of manifest.full_replace_dirs) {
        const dir = normalizeEntry(rawDir)
        assertSafeManifestPath(dir)
        if (!fullReplaceAllowedDirs.has(dir)) {
            fail(`full_replace_dirs contains forbidden directory: ${dir}`)
        }
        if (!hasEntry(entries, `files/${dir}/`)) {
            fail(`full_replace_dirs directory missing from files/: ${dir}`)
        }
    }
    for (const rawFile of manifest.delete_files) {
        const file = normalizeEntry(rawFile)
        assertSafeManifestPath(file)
        if (!deleteAllowedPrefixes.some((prefix) => file.startsWith(prefix))) {
            fail(`delete_files contains forbidden path: ${file}`)
        }
    }
    for (const rawSql of manifest.sql_order) {
        const sql = normalizeEntry(rawSql)
        assertSafeManifestPath(sql)
        if (!sql.startsWith('sql/data/') && !sql.startsWith('sql/structure/')) {
            fail(`sql_order contains non-SQL path: ${sql}`)
        }
        if (isReadmeSql(sql)) {
            fail(`sql_order must not execute README SQL marker: ${sql}`)
        }
        if (path.basename(sql).toLowerCase() === 'install.sql') {
            fail(`incremental package must not execute fresh-install SQL: ${sql}`)
        }
        if (!entries.has(sql)) {
            fail(`sql_order references missing file: ${sql}`)
        }
    }
    const declaredSql = new Set(manifest.sql_order.map((item) => normalizeEntry(item)))
    for (const entry of entries) {
        if ((entry.startsWith('sql/data/') || entry.startsWith('sql/structure/')) && entry.endsWith('.sql')) {
            if (isReadmeSql(entry)) {
                fail(`README marker must not use .sql extension: ${entry}`)
            }
            if (!declaredSql.has(entry)) {
                fail(`SQL file is packaged but missing from sql_order: ${entry}`)
            }
        }
    }
    if (manifest.updater_bridge === true || manifest.runtime_bootstrap) return
    for (const appCode of bridgeAppCodes) {
        for (const rel of bridgeAppRequiredPaths) {
            const required = `files/app/apps/${appCode}/${rel}`
            if (!hasEntry(entries, required)) {
                fail(`incremental bridge package missing built-in app path: ${required}`)
            }
        }
    }
}

function verifyIncrementalComposerRuntime(manifest, entries) {
    if ((manifest.package_mode || '') !== 'incremental') return

    const forbidden = incrementalComposerPaths.filter((entry) => hasEntry(entries, entry))
    if (forbidden.length) {
        fail(`incremental packages must not include Composer runtime files: ${forbidden.join(', ')}`)
    }
    const present = hasEntry(entries, 'composer-runtime/')
    const runtime = manifest.composer_runtime
    if (!present && runtime == null) return
    const reject = (message) => fail(`Invalid atomic Composer runtime: ${message}`)
    const version = (value) => {
        if (!/^\d+\.\d+\.\d+$/.test(String(value || ''))) reject('invalid version')
        return String(value).split('.').map(Number)
    }
    const less = (a, b) => { for (let i=0;i<3;i++) { if(a[i]!==b[i]) return a[i]<b[i] } return false }
    if (!present || !runtime || runtime.schema !== 1 || runtime.payload_dir !== 'composer-runtime') reject('payload/declaration mismatch')
    const minimum = version(runtime.min_updater_version)
    if (less(minimum,[1,0,391]) || (!delegated && less(version(manifest.base_version),minimum))) reject('unsupported base updater')
    if (less(version(runtime.min_php),[8,2,0])) reject('PHP 8.2 required')
    for (const key of ['required_extensions','probe_classes']) {
        if (!Array.isArray(runtime[key]) || !runtime[key].length || runtime[key].some(v=>typeof v!=='string'||!v.trim())) reject(`missing ${key}`)
    }
    for (const rel of ['composer.json','composer.lock','vendor/autoload.php','vendor/composer/autoload_real.php','vendor/composer/installed.json','vendor/composer/installed.php','vendor/services.php']) {
        if (!entries.has(`composer-runtime/${rel}`)) reject(`missing ${rel}`)
    }
    for (const entry of entries) {
        if (entry !== 'composer-runtime/' && entry.startsWith('composer-runtime/') && !/^composer-runtime\/(?:composer\.(?:json|lock)$|vendor(?:\/|$))/.test(entry)) reject(`unexpected payload entry ${entry}`)
    }
    const lock = readPackageJson(absoluteTarget,stat.isDirectory(),'composer-runtime/composer.lock')
    const installed = readPackageJson(absoluteTarget,stat.isDirectory(),'composer-runtime/vendor/composer/installed.json')
    const packageMap = (items) => {
        if (!Array.isArray(items)) reject('invalid package metadata')
        const map={}
        for (const item of items) {
            if (!item?.name || !item?.version || Object.hasOwn(map,item.name)) reject('invalid/duplicate package')
            map[item.name]=item.version
        }
        return JSON.stringify(Object.fromEntries(Object.entries(map).sort(([a],[b])=>a.localeCompare(b))))
    }
    if (packageMap(lock.packages)!==packageMap(Array.isArray(installed)?installed:installed.packages)) reject('installed runtime differs from production lock')
}

function verifyIncrementalSqlSafety(targetPath, isDirectory, manifest, entries) {
    if ((manifest.package_mode || '') !== 'incremental') return
    const declaredSql = new Set((manifest.sql_order || []).map((item) => normalizeEntry(item)))
    for (const entry of entries) {
        if (!entry.endsWith('.sql') || (!entry.startsWith('sql/data/') && !entry.startsWith('sql/structure/'))) {
            continue
        }
        if (isReadmeSql(entry)) continue
        if (path.basename(entry).toLowerCase() === 'install.sql') {
            fail(`incremental package must not contain fresh-install SQL: ${entry}`)
        }
        if (!declaredSql.has(entry)) {
            fail(`incremental package SQL file is missing from sql_order: ${entry}`)
        }
        assertIncrementalSqlContentSafe(entry, readPackageText(targetPath, isDirectory, entry))
    }
}

function assertIncrementalSqlContentSafe(entry, content) {
    const executableSql = String(content)
        .replace(/\/\*!\d*\s*([\s\S]*?)\*\//g, ' $1 ')
        .replace(/\/\*(?!\!)[\s\S]*?\*\//g, ' ')
        .replace(/--[ \t][^\r\n]*/g, ' ')
        .replace(/#[^\r\n]*/g, ' ')
    if (/\b(?:drop|truncate)\s+(?:table|database)\b/i.test(executableSql)) {
        fail(`incremental package contains destructive SQL: ${entry}`)
    }
}

// Official staging signs this source map alongside update.json. It is evidence,
// not authority to skip SQL; candidate runtime derives ownership from real bytes.
function verifyMigrationSources(targetPath,isDirectory,manifest,entries){
    if(!entries.has('release-change-manifest.json'))return
    const coverage=readPackageJson(targetPath,isDirectory,'release-change-manifest.json')
    if(!Array.isArray(coverage.migrations))return // legacy source manifests
    const declared=new Set((manifest.sql_order||[]).map(normalizeEntry)),seen=new Set()
    for(const migration of coverage.migrations){
        const sql=normalizeEntry(migration.package_path||'')
        if(!declared.has(sql)||seen.has(sql)||!Array.isArray(migration.source_paths)||!migration.source_paths.length)fail('Invalid migration source coverage: '+sql)
        seen.add(sql)
        const body=readPackageText(targetPath,isDirectory,sql),hash=createHash('sha256').update(body).digest('hex')
        if(hash!==migration.sha256)fail('Migration source digest differs: '+sql)
        const appSources=[]
        for(const raw of migration.source_paths){
            const source=normalizeEntry(raw),packaged='files/'+source
            if(!/^(?:(?:public\/)?upgrade\/[^/]+\.sql|app\/apps\/[a-z0-9_]+\/migrations\/[^/]+\.sql)$/.test(source)||!entries.has(packaged)||readPackageText(targetPath,isDirectory,packaged)!==body)fail('Migration source bytes missing/differ: '+source)
            if(source.startsWith('app/apps/'))appSources.push(source)
        }
        if(migration.execution_owner==='app/runtime'){
            if(appSources.length!==1||appSources[0].split('/')[2]!==migration.app_code)fail('Ambiguous app migration source: '+sql)
            const appManifest='files/app/apps/'+migration.app_code+'/manifest.json'
            if(!entries.has(appManifest))fail('App migration metadata missing: '+sql)
            const metadata=readPackageJson(targetPath,isDirectory,appManifest)
            if(metadata.code!==migration.app_code||!metadata.is_builtin)fail('App migration is not owned by packaged builtin metadata: '+sql)
            if(!entries.has('files/app/common/service/update/SystemPackageUpdateService.php'))fail('App migration runtime deferral service missing')
        }else if(migration.execution_owner!=='system'||appSources.length)fail('Migration execution owner differs: '+sql)
        if(/ALTER\s+TABLE\s+`?la_aigc_video_config`?\s+MODIFY\s+(?:COLUMN\s+)?`?config_json`?\s+MEDIUMTEXT\s*;/i.test(body)){
            for(const required of ['RuntimeVideoConfigCapacity.php','RuntimeMigrationPlan.php','SqlMigrationExecutor.php'])if(!entries.has('files/app/common/service/database/'+required))fail('Guarded capacity runtime file missing: '+required)
        }
    }
    if(seen.size!==declared.size)fail('SQL order is missing exact source coverage')
}

function verifyCumulativeUpgradeSql(manifest, entries) {
    if ((manifest.package_mode || '') !== 'incremental') return
    if (String(manifest.base_version || '') && String(manifest.base_version) !== '1.0.0') return
    const upgradeDir = path.resolve('server/upgrade')
    if (!existsSync(upgradeDir)) return

    const missing = []
    for (const name of readdirSync(upgradeDir).sort()) {
        if (!name.endsWith('.sql') || name.toUpperCase() === 'README.SQL') continue
        const inData = entries.has(`sql/data/${name}`)
        const inStructure = entries.has(`sql/structure/${name}`)
        if (!inData && !inStructure) {
            missing.push(name)
        }
    }
    if (missing.length) {
        fail(`incremental package is missing cumulative upgrade SQL from server/upgrade: ${missing.join(', ')}`)
    }
}

function normalizeSignature(signature) {
    const files = new Map()
    if (signature.sha256 && typeof signature.sha256 === 'object' && !Array.isArray(signature.sha256)) {
        for (const [filePath, sha256] of Object.entries(signature.sha256)) {
            if (filePath && sha256) {
                const normalized = normalizeEntry(filePath)
                files.set(normalized, { path: normalized, sha256: String(sha256) })
            }
        }
    }
    if (Array.isArray(signature.files)) {
        for (const file of signature.files) {
            if (file && file.path && file.sha256) {
                const normalized = normalizeEntry(file.path)
                files.set(normalized, { path: normalized, sha256: String(file.sha256) })
            }
        }
    }
    return [...files.values()]
}

function hasEntry(entries, required) {
    if (entries.has(required)) {
        return true
    }
    if (required.endsWith('/')) {
        return [...entries].some((entry) => entry.startsWith(required))
    }
    return false
}

function verifySubmitLockDependency(targetPath, isDirectory, entries) {
    const callers = [...entries].filter((entry) => (
        entry.startsWith('files/app/common/service/app/')
        && entry.endsWith('.php')
    ))
    let needsTryAcquire = false
    for (const entry of callers) {
        const content = readPackageText(targetPath, isDirectory, entry)
        if (content.includes('SubmitLockService::tryAcquire(')) {
            needsTryAcquire = true
            break
        }
    }
    if (needsTryAcquire && !entries.has('files/app/common/service/SubmitLockService.php')) {
        fail('Package contains app services that call SubmitLockService::tryAcquire() but is missing files/app/common/service/SubmitLockService.php')
    }
}

function readPackageText(targetPath, isDirectory, file) {
    return isDirectory
        ? readFileSync(path.join(targetPath, file), 'utf8')
        : execFileSync('unzip', ['-p', targetPath, file], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 })
}

function normalizeEntry(entry) {
    return String(entry).replace(/\\/g, '/').replace(/^\.?\//, '')
}

function assertSafeManifestPath(entry) {
    if (!entry || entry.startsWith('/') || /^[a-zA-Z]:\//.test(entry) || (`/${entry}/`).includes('/../')) {
        fail(`Manifest contains unsafe path: ${entry}`)
    }
    const packagePath = `files/${entry}`
    const isWechatArtifact = /^files\/runtime\/wechat-artifacts\/\d+\.\d+\.\d+(?:\/|$)/.test(packagePath)
    if (protectedPatterns.some((pattern) => !(isWechatArtifact && pattern.source === '^files\\/runtime\\/') && (pattern.test(packagePath) || pattern.test(entry)))) {
        fail(`Manifest path matches protected rule: ${entry}`)
    }
}

function isReadmeSql(entry) {
    return /^sql\/(?:data|structure)\/readme(?:\.|$)/i.test(normalizeEntry(entry))
}

function fail(message) {
    console.error(message)
    process.exit(1)
}
