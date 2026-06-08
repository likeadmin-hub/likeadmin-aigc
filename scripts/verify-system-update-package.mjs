#!/usr/bin/env node
import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import path from 'node:path'

const target = process.argv[2]

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

for (const required of requiredPaths) {
    if (!hasEntry(normalized, required)) {
        fail(`Missing required package path: ${required}`)
    }
}

for (const entry of normalized) {
    if (protectedPatterns.some((pattern) => pattern.test(entry))) {
        fail(`Protected path must not be packaged: ${entry}`)
    }
}

const signature = readSignature(absoluteTarget, stat.isDirectory())
const updateManifest = readPackageJson(absoluteTarget, stat.isDirectory(), 'update.json')
verifyIncrementalManifest(updateManifest, normalized)
verifyCumulativeUpgradeSql(updateManifest, normalized)
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
            const itemStat = statSync(item)
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
    const raw = isDirectory
        ? readFileSync(path.join(targetPath, file), 'utf8')
        : execFileSync('unzip', ['-p', targetPath, file], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 })
    try {
        return JSON.parse(raw)
    } catch (error) {
        fail(`${file} is not valid JSON: ${error.message}`)
    }
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
    for (const appCode of bridgeAppCodes) {
        for (const rel of bridgeAppRequiredPaths) {
            const required = `files/app/apps/${appCode}/${rel}`
            if (!hasEntry(entries, required)) {
                fail(`incremental bridge package missing built-in app path: ${required}`)
            }
        }
    }
}

function verifyCumulativeUpgradeSql(manifest, entries) {
    if ((manifest.package_mode || '') !== 'incremental') return
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
    if (protectedPatterns.some((pattern) => pattern.test(`files/${entry}`) || pattern.test(entry))) {
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
