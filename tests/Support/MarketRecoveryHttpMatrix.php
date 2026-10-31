<?php
/** Private HTTP recovery checks. No real supplier tasks, prices or user projects. */
function runMarketFrameRecoveryHttp($f,$api,$suffix,$token,$id,$detail,$product,array &$report): array
{
    $post=fn($route,$p)=>dataHttp($api,'/api/app.aigc_micro_video.'.$route.$suffix,$p,$token);
    $get=fn()=>dataHttp($api,'/api/app.aigc_micro_video.task/detail'.$suffix.'&id='.$id,[],$token,'GET');
    $check=fn($ok,$why)=>MarketWorkerFixture::check((bool)$ok,$why);$old=$detail['frames'];
    $generate=function(array $current,?array $ids,string $key)use($f,$post,$get,$id){
        $p=['id'=>$id,'plan_revision'=>$current['plan_revision'],'frames_revision'=>$current['frames_revision']];if($ids!==null)$p['frame_ids']=$ids;
        $q=$post('frame/estimate',$p);$p+=['quote_token'=>$q['quote_token'],'idempotency_key'=>$key];$post('frame/generate',$p);$post('frame/generate',$p);
        $file=dirname(__DIR__).'/market-generation-http.php';$f->start($file,'fixture_micro_runner','micro');$f->start($file,'aigc_micro_video');
        MarketWorkerFixture::wait(function()use($id){$r=think\facade\Db::name('aigc_micro_video_task')->where('id',$id)->find();if($r['frame_status']==='waiting_continue')throw new RuntimeException('Replacement frame failed');return $r['frame_status']==='review';},'Replacement frames did not finish',65);
        $f->stop('micro');MarketWorkerFixture::wait(fn()=>think\facade\Db::name('ai_task_job')->where('status','<>','success')->count()===0,'Replacement result jobs pending',20);$f->stop();return $get();
    };
    $p=['id'=>$id,'plan_revision'=>$detail['plan_revision'],'frames_revision'=>$detail['frames_revision'],'frame_id'=>$old[1]['id'],'prompt'=>'同一彩色色块在中央，替换这一锚图。','reference_indices'=>[0]];
    $edited=$post('frame/save',$p);$check($edited['frames'][0]['id']===$old[0]['id']&&$edited['frames'][1]['version']===$old[1]['version']+1&&$edited['frames'][2]['version']===$old[2]['version']+1,'Frame replacement invalidated wrong boundaries');
    $stale=callHttp($api,'/api/app.aigc_micro_video.frame/generate'.$suffix,['id'=>$id,'plan_revision'=>$detail['plan_revision'],'frames_revision'=>$detail['frames_revision'],'quote_token'=>'expired-frame-quote','idempotency_key'=>'recovery-stale-frame'],$token);$check($stale['code']!==1,'Stale frame revision generated');
    $middle=$generate($edited,[$edited['frames'][1]['id']],'recovery-middle-frame');
    $check($middle['frames'][2]['status']==='stale','Dependent tail accepted without regeneration');
    $tail=$post('frame/save',['id'=>$id,'plan_revision'=>$middle['plan_revision'],'frames_revision'=>$middle['frames_revision'],'frame_id'=>$middle['frames'][2]['id'],'prompt'=>'最终尾帧的色块静止在右侧。','reference_indices'=>[0]]);
    $check(array_slice(array_column($tail['frames'],'id'),0,2)===array_slice(array_column($middle['frames'],'id'),0,2),'Tail edit invalidated independent prefix');
    $ready=$generate($tail,[$tail['frames'][2]['id']],'recovery-tail-frame');
    $check($ready['frames'][1]['image_url']!==$old[1]['image_url']&&$ready['frames'][0]['image_url']===$old[0]['image_url'],'Replacement lost independent anchor or reused old generated image');
    [$frame]=$product('replacement_image','image','synthetic-image',['max_reference_images'=>3,'params_schema'=>['prompt'=>['type'=>'string','required'=>true],'image_size'=>['type'=>'string','enum'=>['1K']],'aspect_ratio'=>['type'=>'string','enum'=>['1:1']],'image_urls'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>3]]],['image_size'=>'1K','aspect_ratio'=>'1:1']);
    $model='market_image_model:'.$frame;$cfg=app\common\service\app\aigc_micro_video\MicroVideoSettings::get($f->tenant);$cfg['frame_model_ids'][]=$model;app\common\service\app\aigc_micro_video\MicroVideoSettings::save($f->tenant,$cfg);
    $replaced=$post('frame/configure',['id'=>$id,'plan_revision'=>$ready['plan_revision'],'generation_mode'=>'start_end','frame_model_id'=>$model,'frame_quality'=>'1k']);
    $check($replaced['frames'][1]['prompt']===$ready['frames'][1]['prompt']&&$replaced['frames'][1]['reference_indices']===$ready['frames'][1]['reference_indices'],'Changing frame model discarded edited prompt/references');
    $check(array_column($replaced['frames'],'status')===['stale','stale','stale'],'Changing frame model kept stale images ready');
    $first=$post('frame/save',['id'=>$id,'plan_revision'=>$replaced['plan_revision'],'frames_revision'=>$replaced['frames_revision'],'frame_id'=>$replaced['frames'][0]['id'],'prompt'=>'首帧的色块静止在左侧。','reference_indices'=>[0]]);
    $check(array_column($first['frames'],'status')===['stale','stale','stale']&&min(array_column($first['frames'],'version'))>min(array_column($replaced['frames'],'version')),'First edit did not replace all dependent versions');
    $final=$generate($first,null,'recovery-model-frames');$check(array_column($final['frames'],'status')===['success','success','success'],'Replacement model results not ready');
    $report['frame_recovery']=['middle_edit_preserves_prefix_invalidates_tail'=>true,'tail_edit_preserves_prefix'=>true,'first_edit_invalidates_all_dependents'=>true,'model_change_preserves_edited_inputs'=>true,'stale_revision_rejected'=>true,'individual_regeneration_and_same_key_replay'=>true,'model_replacement_regenerates_three_versions'=>true,'manual_boundary_file_upload'=>'No existing upload/replace endpoint; not introduced by this test'];return $final;
}

function runMarketRecoveryHttpMatrix($f,$api,$suffix,$token,$supplier,array &$report): void
{
    $t=$f->tenant;$u=$f->user;$file=dirname(__DIR__).'/market-generation-http.php';
    $check=fn($ok,$why)=>MarketWorkerFixture::check((bool)$ok,$why);
    $post=fn($route,$p)=>dataHttp($api,'/api/app.'.$route.$suffix,$p,$token);
    $get=fn($route,$id)=>dataHttp($api,'/api/app.'.$route.$suffix.'&id='.$id,[],$token,'GET');
    $report['phase']='smart_clip';
    think\facade\Db::name('smart_clip_channel')->insert(['tenant_id'=>0,'code'=>'private-clip','name'=>'Private clip','provider'=>'xhadmin','model'=>'smart_clip','status'=>1,'config_json'=>'{}']);
    think\facade\Db::name('smart_clip_channel_spec')->insert(['tenant_id'=>0,'channel_code'=>'private-clip','quality'=>'1','quality_label'=>'1秒','ratio'=>'duration','platform_unit_cost'=>1,'tenant_unit_price'=>1,'status'=>1,'provider_params_json'=>'{}']);
    foreach(['realman_broadcast','broadcast_mixcut','news_mixcut']as$i=>$kind){
        $form=['api'=>$kind,'channel'=>'private-clip','styleId'=>'private-template','title'=>'Synthetic clip '.$kind,'videoUrl'=>$supplier.'/fixture/result.mp4','audioUrl'=>$supplier.'/fixture/input.wav','materials'=>[['type'=>'video','url'=>$supplier.'/fixture/result.mp4','duration'=>2]],'processRules'=>['videoDuration'=>2],'duration'=>2];
        $q=$post('smart_clip.generate/estimate',$form);$check((float)$q['user_charge_points']===2.,'Private clip quote not per second');
        $accepted=$post('smart_clip.generate/index',$form);$id=(int)$accepted['task_id'];$same=$post('smart_clip.generate/index',$form);$check($same['task_id']===$id,'Clip duplicate submitted twice');
        if($i===0){file_put_contents($f->dir.'/clip-download-fail','1');$pending=$get('smart_clip.task/detail',$id);$check($pending['status']==='running','Accepted clip became terminal on temporary result download failure');unlink($f->dir.'/clip-download-fail');}
        if($i===2)think\facade\Db::name('smart_clip_channel')->where('code','private-clip')->update(['status'=>0]);
        $done=$get('smart_clip.task/detail',$id);$check($done['status']==='success'&&$done['error']===''&&count($done['results'])===1,'Accepted clip did not survive storage/config recovery');
        $clipFile=$f->dir.'/public/'.$done['results'][0]['video_uri'];$check(is_file($clipFile)&&hash_file('sha256',$clipFile)===hash_file('sha256',$f->dir.'/synthetic.mp4'),'Native clip stored bytes differ');
        $bill=think\facade\Db::name('smart_clip_billing')->where('task_id',$id)->find();$check($bill!==null,'Clip native bill missing');
        $before=think\facade\Db::name('smart_clip_task')->where('id',$id)->find();$get('smart_clip.task/detail',$id);$check($before===think\facade\Db::name('smart_clip_task')->where('id',$id)->find(),'Clip terminal read changed row');
        $report['smart_clip'][$kind]=['normal_http_quote_submit_duplicate_result_native_bill_history'=>true,'stored_video_checksum'=>true,'temporary_download_recovery'=>$i===0,'accepted_channel_disabled_recovery'=>$i===2];
    }
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
    $check(count($clips)===3&&array_column($clips,'billing_status')===['deducted','deducted','deducted'],'Native clip billing missing or duplicated');
    $userCost=array_sum(array_column($bills,'actual_user_price'))+array_sum(array_column($clips,'user_charge_points'));
    $tenantCost=array_sum(array_column($bills,'actual_tenant_cost'))+array_sum(array_column($clips,'tenant_cost_points'));
    $check(abs(100-(float)think\facade\Db::name('user')->where('id',$u)->value('user_money')-$userCost)<0.000001,'Recovery user ledger mismatch');
    $check(abs(100-(float)think\facade\Db::name('tenant')->where('id',$t)->value('point_balance')-$tenantCost)<0.000001,'Recovery tenant ledger mismatch');
    $report['final_accounting']=['consumptions'=>count($bills),'billing_statuses'=>$statuses,'native_clip_bills'=>count($clips),'actual_user'=>round($userCost,6),'actual_tenant'=>round($tenantCost,6),'native_clip_tasks'=>(int)think\facade\Db::name('smart_clip_task')->count(),'native_clip_results'=>(int)think\facade\Db::name('smart_clip_result')->count()];
    $calls=array_map(fn($line)=>json_decode($line,true),file($f->dir.'/deep-calls.jsonl'));$report['supplier_call_counts']=array_count_values(array_column($calls,'kind'));
    $report['phase']='complete';
}
