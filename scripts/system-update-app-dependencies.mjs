// Required by the updater's unconditional copywriting bootstrap, including
// sites whose earlier installation never contained this built-in application.
import fs from 'node:fs'
import path from 'node:path'

export const copywritingRoots = [
    'app/apps/aigc_copywriting',
    'app/common/service/app/aigc_copywriting',
    'app/common/model/app/aigc_copywriting',
    ...['api', 'tenantapi', 'platformapi'].map(scene => `app/${scene}/controller/app/aigc_copywriting`),
    'app/common/command/CopywritingWorker.php',
    'app/common/command/CopywritingRepair.php',
]
export const copywritingRequiredFiles = [
    ...['manifest.json', 'api_schema.json', 'menus/tenant.json', 'permissions/tenant.json',
        'migrations/install.sql', 'migrations/upgrade_20261103_history_delete.sql',
        'contracts/catalog.json', 'data/builtin-tools.json', 'data/home-v1.json',
        'data/home-v2.json', 'data/home-v3.json'].map(p => `${copywritingRoots[0]}/${p}`),
    ...['Bootstrap', 'CategoryService', 'Document', 'EventService', 'ModelService', 'PageService',
        'PromptService', 'Receipt', 'Recovery', 'Seeder', 'Store', 'StyleCompiler', 'TaskService',
        'TextRuntime', 'ToolService', 'WorkerService'].map(p => `${copywritingRoots[1]}/Copywriting${p}.php`),
    ...['Audit', 'Category', 'Page', 'Task', 'TaskEvent', 'Tool', 'Version']
        .map(p => `${copywritingRoots[2]}/Copywriting${p}.php`),
    ...['Config', 'Controller', 'Model', 'Task', 'Tool'].map(p =>
        `${copywritingRoots[3]}/${p === 'Controller' ? p : p + 'Controller'}.php`),
    ...['Category', 'Config', 'Controller', 'Page', 'Prompt', 'Schema', 'Task', 'Template', 'Tool'].map(p =>
        `${copywritingRoots[4]}/${p === 'Controller' ? p : p + 'Controller'}.php`),
    `${copywritingRoots[5]}/TaskController.php`,
    ...copywritingRoots.slice(6),
]

export function requiresCopywriting(source) {
    return /CopywritingBootstrap::ensureSystem\s*\(/.test(source)
}

export function copywritingDependencyFiles(repo) {
    for (const relative of copywritingRequiredFiles) {
        if (!fs.existsSync(path.join(repo, relative)) || !fs.lstatSync(path.join(repo, relative)).isFile()) {
            throw new Error(`Required copywriting dependency missing: ${relative}`)
        }
    }
    const manifest = JSON.parse(fs.readFileSync(path.join(repo, copywritingRoots[0], 'manifest.json'), 'utf8'))
    if (manifest.code !== 'aigc_copywriting' || manifest.is_builtin !== 1) {
        throw new Error('Required copywriting app manifest identity differs')
    }
    const files = []
    function visit(relative) {
        const stat = fs.lstatSync(path.join(repo, relative))
        if (stat.isSymbolicLink()) throw new Error(`Required copywriting dependency is a symlink: ${relative}`)
        if (stat.isDirectory()) {
            for (const name of fs.readdirSync(path.join(repo, relative)).sort()) {
                if (name !== '.DS_Store') visit(`${relative}/${name}`)
            }
        } else if (stat.isFile()) files.push(relative)
        else throw new Error(`Unsupported copywriting dependency: ${relative}`)
    }
    copywritingRoots.forEach(visit)
    return files
}

export function assertCopywritingDependencies(entries, read, version = '') {
    const updater = 'files/app/common/service/update/SystemPackageUpdateService.php'
    const bootstrap = 'files/app/common/service/app/aigc_copywriting/CopywritingBootstrap.php'
    // Incremental packages can omit the unchanged updater too. Since 430 its
    // completion dependency is required even when neither caller is packaged.
    const [major, minor, patch] = version.split('.').map(Number)
    const current = major > 1 || (major === 1 && (minor > 0 || (minor === 0 && patch >= 430)))
    if (!current && !entries.has(bootstrap) && !(entries.has(updater) && requiresCopywriting(read(updater)))) return
    for (const relative of copywritingRequiredFiles) {
        if (!entries.has('files/' + relative)) throw new Error(`Required copywriting dependency missing: ${relative}`)
    }
    const manifest = JSON.parse(read('files/app/apps/aigc_copywriting/manifest.json'))
    if (manifest.code !== 'aigc_copywriting' || manifest.is_builtin !== 1) {
        throw new Error('Required copywriting app manifest identity differs')
    }
}
