import test from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { execFileSync, spawnSync } from 'node:child_process'
import { copywritingRequiredFiles, copywritingDependencyFiles, assertCopywritingDependencies } from '../../scripts/system-update-app-dependencies.mjs'

const builder = new URL('../../scripts/prepare-system-update.mjs', import.meta.url)
const guard = new URL('../../scripts/system-update-app-dependencies.mjs', import.meta.url)
const updater = 'app/common/service/update/SystemPackageUpdateService.php'
const manifest = 'app/apps/aigc_copywriting/manifest.json'
function fixture() {
    const repo = fs.mkdtempSync(path.join(os.tmpdir(), 'copywriting-update-'))
    const write = (p, text) => { fs.mkdirSync(path.dirname(path.join(repo, p)), {recursive:true}); fs.writeFileSync(path.join(repo, p), text) }
    const git = (...args) => execFileSync('git', args, {cwd:repo, encoding:'utf8', stdio:'pipe'})
    git('init'); git('config', 'user.name', 'Fixture'); git('config', 'user.email', 'fixture@example.test')
    for (const p of copywritingRequiredFiles) write(p, p.endsWith('.php') ? '<?php // fixture' : '{}')
    write(manifest, JSON.stringify({code:'aigc_copywriting', is_builtin:1, version:'1.0.3'}))
    write(updater, '<?php CopywritingBootstrap::ensureSystem();')
    write('scripts/system-update-app-dependencies.mjs', fs.readFileSync(guard))
    write('config/project.php', "<?php return ['version'=>'1.0.439'];")
    git('add', 'app', 'config', 'scripts'); git('commit', '-m', 'predecessor with unchanged copywriting')
    const base = git('rev-parse', 'HEAD').trim()
    write('app/unrelated.php', '<?php // next release')
    git('add', 'app/unrelated.php'); git('commit', '-m', 'unrelated next step')
    return {repo, write, base}
}
test('unchanged completion dependency ships the whole app when the customer has no directory', () => {
    const f = fixture(), out = path.join(f.repo, 'stage')
    try {
        const result = spawnSync(process.execPath, [builder.pathname, '--repo', f.repo, '--base-ref', f.base,
            '--base-version', '1.0.439', '--version', '1.0.440', '--out-dir', out], {encoding:'utf8'})
        assert.equal(result.status, 0, result.stderr)
        const entries = new Set(copywritingDependencyFiles(f.repo).map(p => 'files/' + p))
        for (const entry of entries) assert.equal(fs.readFileSync(path.join(out, entry), 'utf8'), fs.readFileSync(path.join(f.repo, entry.slice(6)), 'utf8'), entry)
        assertCopywritingDependencies(entries, p => fs.readFileSync(path.join(out, p), 'utf8'))
        const coverage = JSON.parse(fs.readFileSync(path.join(out, 'release-change-manifest.json')))
        assert.ok(coverage.paths.some(p => p.path.endsWith('data/home-v3.json') && p.delivery === 'required-copywriting-runtime-dependency'))
        assert.deepEqual(JSON.parse(fs.readFileSync(path.join(out, 'update.json'))).sql_order, [], 'Unchanged historical app SQL must stay under the app runtime journal')
    } finally { fs.rmSync(f.repo, {recursive:true, force:true}) }
})
test('a manifest alone cannot satisfy the completion hook', () => {
    const files = new Map([['files/' + updater, '<?php CopywritingBootstrap::ensureSystem();'], ['files/' + manifest, '{}']])
    assert.throws(() => assertCopywritingDependencies(new Set(files.keys()), p => files.get(p)), /Required copywriting dependency missing/)
})
test('modern incremental packages require the app even when the caller is unchanged and absent', () => {
    for (const version of ['1.0.430', '1.0.434', '1.0.439', '1.0.440', '1.1.0', '2.0.0']) {
        assert.throws(() => assertCopywritingDependencies(new Set(), () => '', version), /Required copywriting dependency missing/, version)
    }
    assertCopywritingDependencies(new Set(), () => '', '1.0.392')
})
test('every runtime, contract and seed dependency is required even without a changed updater', () => {
    const files = new Map(copywritingRequiredFiles.map(p => ['files/' + p, '{}']))
    files.set('files/' + manifest, JSON.stringify({code:'aigc_copywriting', is_builtin:1}))
    for (const p of copywritingRequiredFiles) {
        const entries = new Set(files.keys()); entries.delete('files/' + p)
        // Removing the bootstrap still leaves the updater as an independent trigger.
        entries.add('files/' + updater); files.set('files/' + updater, '<?php CopywritingBootstrap::ensureSystem();')
        assert.throws(() => assertCopywritingDependencies(entries, p => files.get(p)), /Required copywriting dependency missing/, p)
    }
    assertCopywritingDependencies(new Set(files.keys()), p => files.get(p))
    files.set('files/' + manifest, JSON.stringify({code:'other_app', is_builtin:1}))
    assert.throws(() => assertCopywritingDependencies(new Set(files.keys()), p => files.get(p)), /identity differs/)
})
test('staging rejects a missing data file and includes new app files automatically', () => {
    const f = fixture()
    try {
        const extra = 'app/apps/aigc_copywriting/data/future.json'
        f.write(extra, '{"new":"dependency"}')
        assert.ok(copywritingDependencyFiles(f.repo).includes(extra))
        fs.unlinkSync(path.join(f.repo, 'app/apps/aigc_copywriting/data/home-v3.json'))
        const result = spawnSync(process.execPath, [builder.pathname, '--repo', f.repo, '--base-ref', f.base,
            '--base-version', '1.0.439', '--version', '1.0.440', '--out-dir', path.join(f.repo, 'stage')], {encoding:'utf8'})
        assert.notEqual(result.status, 0)
        assert.match(result.stderr, /Required copywriting dependency missing: .*home-v3.json/)
    } finally { fs.rmSync(f.repo, {recursive:true, force:true}) }
})
