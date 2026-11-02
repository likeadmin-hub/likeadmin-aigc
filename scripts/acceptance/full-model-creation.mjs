import { readFile, writeFile, mkdir } from 'node:fs/promises'
import path from 'node:path'
import { buildManifest, buildCaseMatrix, summarizeCoverage } from './manifest.mjs'

const [mode, ...args] = process.argv.slice(2)
const option = (key) => { const i=args.indexOf('--'+key); return i<0 ? undefined : args[i+1] }
const output = option('output')
if (!['inventory','report'].includes(mode) || !output) {
    console.error('Usage: node scripts/acceptance/full-model-creation.mjs inventory --catalog FILE --output DIR\n       node scripts/acceptance/full-model-creation.mjs report --output DIR')
    process.exitCode=2
} else {
    await mkdir(output,{recursive:true,mode:0o700})
    const load = async (name) => JSON.parse(await readFile(path.join(output,name),'utf8'))
    const save = async (name,data) => writeFile(path.join(output,name),JSON.stringify(data,null,2)+'\n',{mode:0o600})
    if (mode === 'inventory') {
        if (!option('catalog')) throw new Error('Missing --catalog')
        const source=JSON.parse(await readFile(option('catalog'),'utf8'))
        const manifest={...buildManifest(source),tenant_id:source.tenant_id,database:source.database}
        await save('manifest.json',manifest)
        await save('cases.json',buildCaseMatrix(manifest))
        try { await load('results.json') } catch(e) { if(e.code!=='ENOENT')throw e; await save('results.json',[]) }
    }
    const manifest=await load('manifest.json')
    const report=summarizeCoverage(manifest,await load('results.json'))
    await save('report.json',report)
    console.log(JSON.stringify({models:manifest.models.length,eligible:manifest.models.filter(m=>m.state!=='BLOCKED').length,complete:report.complete,total:report.total,counts:report.counts}))
}
