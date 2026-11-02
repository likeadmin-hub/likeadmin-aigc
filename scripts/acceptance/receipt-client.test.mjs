import {test} from 'node:test'
import assert from 'node:assert/strict'
import {mkdtemp,rm,readFile} from 'node:fs/promises'
import os from 'node:os'
import path from 'node:path'
import {sendOnce} from './receipt-client.mjs'
test('a durable receipt prevents duplicate submit and lost responses remain uncertain',async()=>{
 const dir=await mkdtemp(path.join(os.tmpdir(),'creation-receipt-'))
 try {
  let calls=0;const file=path.join(dir,'create.json')
  const post=async()=>{calls++;return {code:1,data:{task_id:'original'}}}
  assert.equal((await sendOnce(file,{prompt:'frozen'},post)).data.task_id,'original')
  assert.equal((await sendOnce(file,{prompt:'frozen'},post)).data.task_id,'original');assert.equal(calls,1)
  await assert.rejects(sendOnce(file,{prompt:'changed'},post),/changed/)
  const lost=path.join(dir,'lost.json');await assert.rejects(sendOnce(lost,{},async()=>{calls++;throw Error('lost')}),/lost/)
  await assert.rejects(sendOnce(lost,{},post),/uncertain/);assert.equal(calls,2)
  assert.equal(JSON.parse(await readFile(lost,'utf8')).state,'uncertain')
 } finally {await rm(dir,{recursive:true,force:true})}
})
