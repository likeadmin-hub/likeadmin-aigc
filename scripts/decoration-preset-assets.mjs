import fs from 'node:fs'
import path from 'node:path'

// Preset resources are release-owned, unlike tenant uploads. Include their
// complete dependency set on every functional update, even if Git did not
// change a particular image since the predecessor release.
export function decorationPresetAssets(repo) {
    const folder = 'app/common/service/decorate/presets'
    const directory = path.join(repo, folder)
    if (!fs.existsSync(directory)) return { presets: [], assets: [] }
    const assets = new Set()
    const presets = fs.readdirSync(directory).filter(name => name.endsWith('.json')).sort().map(name => `${folder}/${name}`)
    function visit(value) {
        if (Array.isArray(value)) { value.forEach(visit); return }
        if (value && typeof value === 'object') { Object.values(value).forEach(visit); return }
        if (typeof value !== 'string' || !/^\/?resource\//.test(value)) return
        const relative = 'public/' + value.replace(/^\//, '').split(/[?#]/, 1)[0]
        if (relative.includes('\\') || relative.includes('\0') || relative.split('/').some(part => part === '..' || part === '.')) {
            throw new Error(`Unsafe decoration preset resource: ${relative}`)
        }
        let current = repo
        for (const part of relative.split('/')) {
            current = path.join(current, part)
            if (!fs.existsSync(current)) throw new Error(`Missing decoration preset resource: ${relative}`)
            if (fs.lstatSync(current).isSymbolicLink()) throw new Error(`Decoration preset resource contains symlink: ${relative}`)
        }
        if (!fs.statSync(current).isFile()) throw new Error(`Decoration preset resource is not a file: ${relative}`)
        assets.add(relative)
    }
    for (const relative of presets) {
        if (fs.lstatSync(path.join(repo, relative)).isSymbolicLink()) throw new Error(`Decoration preset contains symlink: ${relative}`)
        visit(JSON.parse(fs.readFileSync(path.join(repo, relative), 'utf8')))
    }
    return { presets, assets: [...assets].sort() }
}
