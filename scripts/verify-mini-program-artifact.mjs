import { createHash } from 'node:crypto'
import { existsSync, readFileSync, readdirSync } from 'node:fs'
import path from 'node:path'

const metadataFile = '.artifact.meta.json'
const sha256 = bytes => createHash('sha256').update(bytes).digest('hex')

export function verifyMiniProgramArtifact(directory, version) {
    const metadataPath = path.join(directory, metadataFile)
    if (!existsSync(metadataPath)) throw new Error('正式小程序缺少产物元数据')
    const metadata = JSON.parse(readFileSync(metadataPath, 'utf8'))
    if (metadata?.version !== version) throw new Error(`正式小程序版本元数据不匹配，目标版本为 ${version}`)

    const entries = []
    function walk(folder, prefix = '') {
        for (const entry of readdirSync(folder, { withFileTypes: true })) {
            const relative = prefix + entry.name
            const filename = path.join(folder, entry.name)
            if (entry.isDirectory()) walk(filename, relative + '/')
            else if (entry.isFile()) {
                if (entry.name !== metadataFile) entries.push([relative, sha256(readFileSync(filename))])
            } else throw new Error(`正式小程序包含无效文件：${relative}`)
        }
    }
    walk(directory)
    entries.sort(([left], [right]) => Buffer.compare(Buffer.from(left), Buffer.from(right)))
    const files = Object.fromEntries(entries)
    for (const required of ['app.json', 'project.config.json', 'config/runtime.js']) {
        if (!Object.hasOwn(files, required)) throw new Error(`正式小程序缺少关键文件：${required}`)
    }
    const runtime = readFileSync(path.join(directory, 'config/runtime.js'), 'utf8')
    if (!runtime.includes('__TENANT_API_BASE_URL__') || !runtime.includes('__TENANT_ID__')) {
        throw new Error('正式小程序缺少租户运行时占位符')
    }
    const manifest = JSON.stringify(files)
    if (metadata.file_count !== entries.length || metadata.sha256 !== sha256(manifest)
        || JSON.stringify(metadata.files) !== manifest) {
        throw new Error('正式小程序文件清单校验失败')
    }
}
