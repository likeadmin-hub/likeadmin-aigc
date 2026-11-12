<?php
/** Private HTTP recovery checks. No real supplier tasks, prices or user projects. */
function runMarketRecoveryHttpMatrix($f,$api,$suffix,$token,$supplier,array &$report): void
{
    $t=$f->tenant;$u=$f->user;$file=dirname(__DIR__).'/market-generation-http.php';
    $check=fn($ok,$why)=>MarketWorkerFixture::check((bool)$ok,$why);
    $post=fn($route,$p)=>dataHttp($api,'/api/app.'.$route.$suffix,$p,$token);
    $get=fn($route,$id)=>dataHttp($api,'/api/app.'.$route.$suffix.'&id='.$id,[],$token,'GET');
    $report['phase']='smart_clip';
    require_once __DIR__.'/MarketClipWorkerHttpMatrix.php';runMarketClipWorkerHttp($f,$api,$suffix,$token,$supplier,$report);
    if(getenv('AIGC_HTTP_CLIP_WORKER_ONLY')==='1'){$report['phase']='complete';return;}
    $report['phase']='music_text';
    foreach(['lyrics','mashup_lyrics','style']as$op){$p=app\common\model\power\PowerMarketProduct::create(['product_code'=>$f->prefix.$op,'name'=>'Private '.$op,'resource_type'=>'app_api','model_type'=>'audio','upstream_app_code'=>'music_generation','upstream_api_code'=>$op,'source_code'=>'isolated-fixture','upstream_resource_key'=>$f->prefix.$op,'status'=>1]);app\common\model\power\PowerMarketSku::create(['product_id'=>$p['id'],'sku_key'=>$f->prefix.$op,'title'=>'Private text','locked_params'=>[],'usage_unit'=>'per_call','usage_unit_size'=>1,'sale_points'=>1,'upstream_price'=>1,'sale_status'=>1,'status'=>1]);}
    foreach(['lyrics'=>['prompt'=>'Synthetic colored blocks'],'mashup_lyrics'=>['lyrics_a'=>'彩色色块','lyrics_b'=>'随节奏移动']]as$op=>$params){$r=$post('aigc_music.'.$op.'/index',$params);$check($r['status']==='success'&&trim($r['lyrics']??'')!=='','Music '.$op.' did not complete');$id=(int)$r['task_id'];$get('aigc_music.task/detail',$id);$report['music_text'][$op]=['normal_login_http'=>true,'native_task_and_settlement'=>true];}
    // Style is an existing OpenAPI-only capability. Exercise its real bearer
    // gateway with a private published contract, never add a PC route for tests.
    foreach([0,$t]as$tenant){$settings=app\common\service\open_platform\OpenStore::settings($tenant);$settings['apps']=['aigc_music'];$settings['tenant_enabled']=true;$settings['features']['keys']=true;app\common\service\open_platform\OpenManagement::persistSettings($tenant,true,$settings);}
    $key=app\common\service\open_platform\OpenKeyService::create($t,$u,['name'=>'Private style','scopes'=>['aigc_music'],'ip_allowlist'=>['127.0.0.1/32']]);
    $path='/api/v1/tools/aigc_music/style/index';$contract=app\common\service\open_platform\OpenContracts::validate(['code'=>$f->prefix.'style','kind'=>'tool','method'=>'POST','path'=>$path,'config'=>['role'=>'submit','input'=>['type'=>'object','properties'=>['prompt'=>['type'=>'string']],'required'=>['prompt']],'example'=>['prompt'=>'Synthetic ambient'],'responses'=>['200'=>['description'=>'Private fixture']]]]);
    app\common\service\open_platform\OpenStore::table('contract')->insert($contract+['version'=>1,'status'=>'published']+app\common\service\open_platform\OpenStore::timestamp());
    $curl=curl_init($api.$path);curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key['key'],'Content-Type: application/json','Idempotency-Key: private-style-recovery'],CURLOPT_TIMEOUT=>20,CURLOPT_POSTFIELDS=>json_encode(['prompt'=>'Synthetic ambient'])]);$raw=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$r=json_decode($raw,true);$check($status===200&&($r['data']['status']??'')==='success','Style bearer HTTP failed: '.($r['error']['message']??'unknown'));$report['music_text']['style']=['existing_bearer_gateway'=>true,'native_task_and_settlement'=>true];

    $download=function(string $op)use($t,$u,$api,$suffix,$token,$check):void{
        $export=think\facade\Db::name('aigc_music_export')->where(['tenant_id'=>$t,'user_id'=>$u,'export_type'=>$op])->order('id','desc')->find();
        $check($export!==null,'Recovered export row missing');$meta=json_decode($export['result_json'],true);
        $curl=curl_init($api.'/api/app.aigc_music.export/download'.$suffix.'&export_id='.$export['id']);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['token: '.$token,'Host: private-media.example.test']]);
        $bytes=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        $check($status===200&&hash('sha256',$bytes)===$meta['sha256'],'Recovered export HTTP checksum differs');
    };
    $report['phase']='export_recovery';$source=(int)think\facade\Db::name('aigc_music_result')->where(['tenant_id'=>$t,'user_id'=>$u])->value('id');
    foreach(['wav','mp4']as$op){file_put_contents($f->dir.'/export-fail-'.$op,'1');$r=$post('aigc_music.export/'.$op,['result_id'=>$source]);$id=(int)$r['task_id'];
        $f->start($file,'aigc_music');MarketWorkerFixture::wait(fn()=>think\facade\Db::name('ai_task_job')->where('consumption_id',think\facade\Db::name('aigc_music_task')->where('id',$id)->value('consumption_id'))->where('last_error','<>','')->count()>0,'Export did not retain failed storage receipt',25);$f->stop();
        $cid=(int)think\facade\Db::name('aigc_music_task')->where('id',$id)->value('consumption_id');$bill=think\facade\Db::name('ai_consumption_log')->where('id',$cid)->find();$check($bill['billing_status']==='reserved','Temporary export failure released or settled reservation');
        unlink($f->dir.'/export-fail-'.$op);think\facade\Db::name('ai_task_job')->where('consumption_id',$cid)->update(['next_run_time'=>time()]);$f->start($file,'aigc_music');MarketWorkerFixture::wait(fn()=>think\facade\Db::name('aigc_music_task')->where('id',$id)->value('status')==='success','Export recovery did not finish original task',25);$f->stop();$download($op);$report['export_recovery'][$op]=['held_reserve_then_original_task_storage_recovery'=>true,'owned_download_checksum'=>true];
    }
    file_put_contents($f->dir.'/export-terminal-fail-midi','1');$r=$post('aigc_music.export/midi',['result_id'=>$source]);$id=(int)$r['task_id'];$f->start($file,'aigc_music');MarketWorkerFixture::wait(fn()=>think\facade\Db::name('aigc_music_task')->where('id',$id)->value('billing_status')==='refunded','Known export rejection not refunded',25);$f->stop();unlink($f->dir.'/export-terminal-fail-midi');
    $r=$post('aigc_music.export/midi',['result_id'=>$source]);$check((int)$r['task_id']!==$id,'Explicit retry reused failed export');$f->start($file,'aigc_music');MarketWorkerFixture::wait(fn()=>think\facade\Db::name('ai_task_job')->where('status','<>','success')->count()===0,'Recovery jobs not drained',25);$f->stop();$download('midi');$report['export_recovery']['midi']=['known_failure_refund_and_new_explicit_attempt'=>true,'owned_download_checksum'=>true];
    $bills=think\facade\Db::name('ai_consumption_log')->select()->toArray();
    $statuses=array_count_values(array_column($bills,'billing_status'));
    $check(($statuses['refunded']??0)===1&&($statuses['settled']??0)===count($bills)-1,'Recovery billing not terminal');
    $clips=think\facade\Db::name('smart_clip_billing')->select()->toArray();
    $check(count($clips)===4&&count(array_filter($clips,fn($c)=>$c['billing_status']==='deducted'))===4,'Native clip billing missing or duplicated');
    $userCost=array_sum(array_column($bills,'actual_user_price'))+array_sum(array_column($clips,'user_charge_points'));
    $tenantCost=array_sum(array_column($bills,'actual_tenant_cost'))+array_sum(array_column($clips,'tenant_cost_points'));
    $check(abs(100-(float)think\facade\Db::name('user')->where('id',$u)->value('user_money')-$userCost)<0.000001,'Recovery user ledger mismatch');
    $check(abs(100-(float)think\facade\Db::name('tenant')->where('id',$t)->value('point_balance')-$tenantCost)<0.000001,'Recovery tenant ledger mismatch');
    $report['final_accounting']=['consumptions'=>count($bills),'billing_statuses'=>$statuses,'native_clip_bills'=>count($clips),'actual_user'=>round($userCost,6),'actual_tenant'=>round($tenantCost,6),'native_clip_tasks'=>(int)think\facade\Db::name('smart_clip_task')->count(),'native_clip_results'=>(int)think\facade\Db::name('smart_clip_result')->count()];
    $calls=array_map(fn($line)=>json_decode($line,true),file($f->dir.'/deep-calls.jsonl'));$report['supplier_call_counts']=array_count_values(array_column($calls,'kind'));
    $report['phase']='complete';
}
