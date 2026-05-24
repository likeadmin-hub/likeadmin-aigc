#!/usr/bin/env node
import { execFileSync } from 'node:child_process'
import { existsSync, statSync } from 'node:fs'
import path from 'node:path'

const root = path.resolve(process.argv[2] || 'server')

const required = [
    '.example.env',
    'composer.json',
    'vendor/autoload.php',
    'think',
    'app',
    'config',
    'public',
    'public/install/db/like.sql',
    'public/platform/index.html',
    'public/admin/index.html',
    'public/pc/index.html',
    'public/mobile/index.html',
    'public/mp-weixin/app.json',
    'runtime',
    'runtime/.gitignore',
    'runtime/index.html',
    'public/uploads',
    'public/uploads/index.html'
]

const forbiddenTop = ['server', 'platform', 'tenant', 'pc', 'uniapp']
const forbiddenFiles = ['.env', 'config/install.lock']
const forbiddenPatterns = [
    /(^|\/)\.DS_Store$/,
    /(^|\/)node_modules(\/|$)/,
    /\.log$/,
    /^runtime\/.+(?<!\.gitignore)(?<!index\.html)$/,
    /^public\/uploads\/.+(?<!index\.html)$/
]

if (!existsSync(root) || !statSync(root).isDirectory()) {
    fail(`Server release root not found: ${root}`)
}

const missing = required.filter((item) => !existsSync(path.join(root, item)))
if (missing.length) {
    fail(`Missing install-ready server paths: ${missing.join(', ')}`)
}

const forbiddenRootDirs = forbiddenTop.filter((item) => existsSync(path.join(root, item)))
if (forbiddenRootDirs.length) {
    fail(`Forbidden source/nested root directories in server release tree: ${forbiddenRootDirs.join(', ')}`)
}

const files = listFiles(root)
const forbidden = []
for (const rel of files) {
    if (forbiddenFiles.includes(rel)) {
        forbidden.push(rel)
        continue
    }
    if (forbiddenPatterns.some((pattern) => pattern.test(rel))) {
        forbidden.push(rel)
    }
}
if (forbidden.length) {
    fail(`Forbidden runtime/local files in server release tree: ${forbidden.slice(0, 40).join(', ')}`)
}

console.log(JSON.stringify({
    ok: true,
    root,
    files: files.length,
    has_runtime: true,
    has_uploads: true
}, null, 2))

function listFiles(base) {
    return execFileSync('find', [base, '-type', 'f'], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
        .split('\n')
        .filter(Boolean)
        .map((item) => path.relative(base, item).replace(/\\/g, '/'))
}

function fail(message) {
    console.error(message)
    process.exit(1)
}
