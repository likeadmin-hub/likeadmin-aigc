#!/usr/bin/env node
import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { existsSync, readFileSync, statSync } from 'node:fs'
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
    /^files\/config\/install\.lock$/,
    /^files\/runtime\//,
    /^files\/public\/uploads\//,
    /^files\/public\/storage\//,
    /^files\/public\/qrcode\//,
    /(^|\/)\.DS_Store$/,
    /\.log$/
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
const signatureFiles = normalizeSignature(signature)
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
if (!signatureFiles.some((file) => file.path.startsWith('sql/data/'))) {
    fail('signature.json does not include a sql/data compatibility marker')
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
    const raw = isDirectory
        ? readFileSync(path.join(targetPath, 'signature.json'), 'utf8')
        : execFileSync('unzip', ['-p', targetPath, 'signature.json'], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 })
    try {
        return JSON.parse(raw)
    } catch (error) {
        fail(`signature.json is not valid JSON: ${error.message}`)
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

function normalizeEntry(entry) {
    return String(entry).replace(/\\/g, '/').replace(/^\.?\//, '')
}

function fail(message) {
    console.error(message)
    process.exit(1)
}
