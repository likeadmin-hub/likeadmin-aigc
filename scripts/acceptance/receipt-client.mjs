import {readFile,writeFile,rename,mkdir,open,unlink} from 'node:fs/promises'
import path from 'node:path'
import {randomUUID} from 'node:crypto'

export async function savePrivate(file,value) {
    await mkdir(path.dirname(file),{recursive:true,mode:0o700})
    const temp=file+'.'+randomUUID()+'.tmp'
    await writeFile(temp,JSON.stringify(value,null,2)+'\n',{mode:0o600})
    await rename(temp,file)
}
export async function sendOnce(file,payload,post) {
    const frozen=JSON.parse(JSON.stringify(payload))
    try {
        const saved=JSON.parse(await readFile(file,'utf8'))
        if(JSON.stringify(saved.payload)!==JSON.stringify(frozen))throw Error('Receipt payload changed; never reuse an identity for different content')
        if(saved.state==='received')return saved.response
        throw Error('Submission result is uncertain; reconcile the original request before continuing')
    }catch(error){if(error.code!=='ENOENT')throw error}
    await mkdir(path.dirname(file),{recursive:true,mode:0o700})
    const lock=await open(file+'.sending','wx',0o600)
    try {
        await savePrivate(file,{state:'submitting',payload:frozen,started_at:new Date().toISOString()})
        let response
        try {response=await post(frozen)}
        catch(error){await savePrivate(file,{state:'uncertain',payload:frozen,error:String(error.message),updated_at:new Date().toISOString()});throw error}
        await savePrivate(file,{state:'received',payload:frozen,response,updated_at:new Date().toISOString()})
        return response
    }finally{await lock.close();await unlink(file+'.sending')}
}
