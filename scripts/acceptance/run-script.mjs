// Drives existing app APIs and scoped original workers. No supplier endpoints or hidden budget limits.
import {readFile,open,unlink} from 'node:fs/promises'
import path from 'node:path'
import {execFile} from 'node:child_process'
import {promisify} from 'node:util'
import {randomUUID} from 'node:crypto'
import {sendOnce,savePrivate} from './receipt-client.mjs'
const exec=promisify(execFile),args=process.argv.slice(2)
const option=(name,fallback='')=>{const i=args.indexOf('--'+name);return i<0?fallback:args[i+1]}
const directory=option('output'),authFile=option('auth'),model=option('model'),episodes=Number(option('episodes','1'))
if(!directory||!authFile||!model||!Number.isInteger(episodes)||episodes<1)throw Error('Required: --output DIR --auth PRIVATE_JSON --model SELECTION --episodes N; optional --task ID or --project ID')
const auth=JSON.parse(await readFile(authFile,'utf8')),tenant=Number(auth.tenant_id)
if(!Number.isInteger(tenant)||tenant<=0||!auth.token)throw Error('Invalid authenticated tenant')
const base=auth.base_url.replace(/\/$/,''),runtime=option('runtime','/www/wwwroot/likeadmin_aigc_saas/server')
const prompt=option('prompt','创作完整温情短剧《旧球新约》。人物林峰和陈宇，地点社区篮球场和杂货铺门口。陈宇误以为林峰弄坏了旧篮球，争执后得知林峰一直为他修补。保留台词“球旧了，朋友不能丢。”结尾两人和好并约定明天继续练球。保持目标集数，每集有完整剧本和人物场景及分镜，不能仅输出梗概。')
const api=async(route,payload,method='GET')=>{
 const url=new URL(base+'/api/app.aigc_short_drama.'+route);url.searchParams.set('tenant_id',String(tenant))
 if(method==='GET')for(const [key,value]of Object.entries(payload))url.searchParams.set(key,String(value))
 const response=await fetch(url,{method,headers:{token:auth.token,'Content-Type':'application/json'},...(method==='POST'?{body:JSON.stringify(payload)}:{}),signal:AbortSignal.timeout(60000)})
 return response.json()
}
const checked=b=>{if(b.code!==1)throw Error(b.msg||'Business request rejected');return b.data}
const post=async(name,route,payload)=>checked(await sendOnce(path.join(directory,name+'.json'),payload,p=>api(route,p,'POST')))
const read=async(name,route,payload)=>{
 for(;;){
  try {const b=await api(route,payload);await savePrivate(path.join(directory,name+'.json'),b);return checked(b)}
  catch(error){
   if(!(error instanceof TypeError)&&!(error instanceof SyntaxError)&&!['TimeoutError','AbortError'].includes(error.name))throw error
   console.log(JSON.stringify({reading:route,state:'reconnecting',error:error.message}));await wait()
  }
 }
}
const wait=()=>new Promise(resolve=>setTimeout(resolve,3000))
const worker=async(kind,scope)=>{
 const r=await exec('docker',['exec','-w',runtime,'bt','/www/server/php/82/bin/php','think','short-drama:'+kind+'-worker','--once',...scope],{maxBuffer:1024*1024})
 if(r.stdout.trim())console.log(r.stdout.trim())
}
async function plan(task,label){
 if(!/^sd_[a-zA-Z0-9_]+$/.test(task))throw Error('Invalid task identity')
 for(;;){
  const d=await read(label,'script_plan/detail',{task_id:task})
  console.log(JSON.stringify({task_id:task,status:d.status,step:d.current_step}))
  if(d.status==='success')return d
  if(['failed','canceled','needs_review'].includes(d.status))throw Error(d.error||'Task stopped: '+d.status)
  if(['queued','pending'].includes(d.status))await worker('planning',['--task='+task])
  else await wait()
 }
}
await savePrivate(path.join(directory,'runner-start.json'),{starting_at:new Date().toISOString()})
const lockFile=path.join(directory,'runner.lock'),lock=await open(lockFile,'wx',0o600);await lock.writeFile(String(process.pid))
try{
 await savePrivate(path.join(directory,'runner-config.json'),{tenant_id:tenant,user_id:auth.user_id,model,episodes,runtime,prompt})
 let project=Number(option('project','0')),task=option('task')
 if(!project){
  if(!task){
   let identity
   try{identity=JSON.parse(await readFile(path.join(directory,'intent.json'),'utf8'))}catch(e){if(e.code!=='ENOENT')throw e;identity={submission_key:randomUUID()};await savePrivate(path.join(directory,'intent.json'),identity)}
   const payload={prompt,model_selections:{script_plan:model},multi_episode:episodes>1,episode_count:episodes,quality_review_enabled:false,source:'home',...identity,...(episodes>1?{workflow_variant:'story_outline_v2',multi_episode_stage:'story'}:{})}
   const created=await post('create','script_plan/create',payload);task=created.task_id
  }
  let d=await plan(task,'plan-detail');project=Number(d.project_id)
  if(String(d.model_selections?.script_plan?.id)!==model)throw Error('Actual selected model mismatch')
  if(episodes>1){
   if(d.story_workspace?.stage==='story'){
    if(!d.story_workspace.can_confirm)throw Error('Story cannot confirm: '+JSON.stringify(d.story_workspace.issues))
    const advanced=await post('advance','script_plan/message',{task_id:d.task_id,project_id:project,draft_version:d.story_workspace.draft_version,mode:'advance_stage'})
    d=await plan(advanced.task_id,'outline-detail')
   }
   if(!d.story_workspace?.can_confirm)throw Error('Outline cannot confirm')
   await post('start','episode/start',{project_id:project,task_id:d.task_id,draft_version:d.story_workspace.draft_version,generation_mode:'staged'})
  }else{
   if(!d.result?.scene_screenplays?.length||!d.result?.storyboard?.length||!d.result?.subjects?.length||!d.result?.locations?.length)throw Error('Incomplete single script')
   await savePrivate(path.join(directory,'generation-result.json'),{state:'GENERATION_COMPLETE_UNVERIFIED',task_id:d.task_id,project_id:project,model,episodes:1,billing_verified:false,artifact_checked:false});process.exitCode=0
  }
 }
 if(episodes>1){
  for(;;){
   const list=await read('episodes','episode/list',{project_id:project})
   console.log(JSON.stringify({project_id:project,completed:list.completed_count,target:episodes,states:list.lists.map(e=>({n:e.episode_number,status:e.status,error:e.error}))}))
   if(list.episode_count!==episodes||list.lists.length!==episodes)throw Error('Target episode count changed')
   if(list.lists.every(e=>e.status==='success'&&!e.needs_review)){
    for(const episode of list.lists){const d=await read('episode-'+episode.episode_number,'episode/detail',{episode_id:episode.id});await savePrivate(path.join(directory,'episode-'+episode.episode_number+'-evidence.json'),d)}
    await savePrivate(path.join(directory,'generation-result.json'),{state:'GENERATION_COMPLETE_UNVERIFIED',project_id:project,model,episodes,billing_verified:false,artifact_checked:false});break
   }
   if(list.lists.some(e=>['failed','canceled'].includes(e.status)||e.needs_review))throw Error('An episode requires recovery; original receipts retained')
   const control=list.generation_control
   if(control?.can_confirm&&!control.active){
    const name='confirm-range-'+control.next_start+'-'+control.max_end
    let key
    try{key=JSON.parse(await readFile(path.join(directory,name+'-intent.json'),'utf8')).key}catch(e){if(e.code!=='ENOENT')throw e;key=randomUUID();await savePrivate(path.join(directory,name+'-intent.json'),{key})}
    await post(name,'episode/start',{project_id:project,action:'confirm_range',control_revision:control.revision,confirmation_key:key,range_start:control.next_start,range_end:control.max_end,outlines:control.outline})
   }
   await worker('episode',['--tenant='+tenant,'--project='+project]);await wait()
  }
 }
}catch(error){await savePrivate(path.join(directory,'runner-error.json'),{state:'NEEDS_REVIEW',message:error.message,at:new Date().toISOString()});throw error}
finally{await lock.close();await unlink(lockFile)}
