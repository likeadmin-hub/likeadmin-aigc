<?php
/** Private schema / loopback supplier only. Planning receipts are seeded, not an LLM certification. */
function runMarketStageHttpMatrix(MarketWorkerFixture $f,string $api,string $suffix,string $token,string $image,string $supplier,array &$report): void
{
    $tenant=$f->tenant;$user=$f->user;
    $seed=[];
    foreach(['seedsvc','lipsync'] as $app)foreach(['submit','query'] as $operation) {
        $product=app\common\model\power\PowerMarketProduct::create(['product_code'=>$f->prefix.$app.$operation,'name'=>'Private stage '.$app,
            'resource_type'=>'app_api','model_type'=>'audio','upstream_app_code'=>$app,'upstream_api_code'=>$operation,
            'source_code'=>'isolated-fixture','upstream_resource_key'=>$f->prefix.$app.$operation,'status'=>1]);
        if($operation==='submit') {
            $sku=app\common\model\power\PowerMarketSku::create(['product_id'=>$product['id'],'sku_key'=>$f->prefix.$app,'title'=>'Private stage',
                'locked_params'=>$app==='lipsync'?['model'=>'synthetic-lipsync']:[],'usage_unit'=>$app==='lipsync'?'second':'per_call',
                'usage_unit_size'=>1,'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1]);
            $seed[$app]=['market_product_id'=>(int)$product['id'],'market_sku_id'=>(int)$sku['id']];
        }
    }
    $file=dirname(__DIR__).'/market-generation-http.php';
    $finish=function(string $code,int $id,string $table)use($f,$file){
        $f->start($file,$code);
        MarketWorkerFixture::wait(fn()=>think\facade\Db::name($table)->where('id',$id)->value('status')==='success',$code.' worker did not write parent result',35);
        MarketWorkerFixture::wait(fn()=>think\facade\Db::name('ai_task_job')->where('status','<>','success')->count()===0,$code.' pending result job',30);
        $f->stop();
    };
    $path='/api/app.aigc_music_cover';
    $cover=$seed['seedsvc']+['title'=>'Synthetic cover','source_url'=>$supplier.'/fixture/input.wav','reference_url'=>$supplier.'/fixture/input.wav'];
    $quote=dataHttp($api,$path.'.generate/estimate'.$suffix,$cover,$token);
    $accepted=dataHttp($api,$path.'.generate/index'.$suffix,$cover,$token);
    MarketWorkerFixture::check(($accepted['generation_task']['schema_version']??0)===1,'Music cover missing canonical acceptance');
    think\facade\Db::name('power_market_sku')->where('id',$seed['seedsvc']['market_sku_id'])->update(['sale_points'=>99,'sale_status'=>0]);
    $finish('aigc_music_cover',$accepted['task_id'],'aigc_music_cover_task');
    $detail=dataHttp($api,$path.'.task/detail'.$suffix.'&id='.$accepted['task_id'],[],$token,'GET');
    MarketWorkerFixture::check(count($detail['results'])===1&&$detail['generation_task']['status']==='success','Music cover result not ready');
    MarketWorkerFixture::check((float)$detail['actual_user_price']===1.,'Cover reinterpreted accepted price after shelf change');
    think\facade\Db::name('power_market_sku')->where('id',$seed['seedsvc']['market_sku_id'])->update(['sale_points'=>1,'sale_status'=>1]);
    $before=think\facade\Db::name('aigc_music_cover_task')->where('id',$accepted['task_id'])->find();
    dataHttp($api,$path.'.task/detail'.$suffix.'&id='.$accepted['task_id'],[],$token,'GET');
    MarketWorkerFixture::check($before===think\facade\Db::name('aigc_music_cover_task')->where('id',$accepted['task_id'])->find(),'Music cover history read rewrote terminal task');
    $retry=dataHttp($api,$path.'.task/retry'.$suffix,['id'=>$accepted['task_id']],$token);
    $finish('aigc_music_cover',$retry['task_id'],'aigc_music_cover_task');
    $again=dataHttp($api,$path.'.task/detail'.$suffix.'&id='.$retry['task_id'],[],$token,'GET');
    foreach(['source_uri','source_url','reference_uri','reference_url','title','market_product_id','market_sku_id'] as $key)
        MarketWorkerFixture::check($detail[$key]===$again[$key],'Cover reuse changed '.$key);
    MarketWorkerFixture::check($detail['generation_task']['task_ref']!==$again['generation_task']['task_ref'],'Cover retry reused old task');
    $report['music_cover']=['http_submit_worker_writeback_history_retry'=>true,'canonical_result'=>true,'terminal_read_unchanged'=>true,'accepted_price_survives_private_shelf_change'=>true];

    $dir=$f->dir.'/public/uploads/stage';mkdir($dir,0700,true);copy($f->dir.'/synthetic.wav',$dir.'/driver.wav');copy($f->dir.'/synthetic.mp4',$dir.'/avatar.mp4');
    $avatar=think\facade\Db::name('aigc_digital_human_avatar')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'name'=>'Private synthetic blocks','source'=>'mine','media_type'=>'video','media_uri'=>'uploads/stage/avatar.mp4','status'=>1,'delete_time'=>0]);
    $options=app\common\service\app\aigc_digital_human\DigitalHumanMarketService::channels($tenant);
    MarketWorkerFixture::check(count($options)===1,'Private video lipsync selection missing');
    $driver=['title'=>'Synthetic avatar','avatar_id'=>$avatar,'driver_audio_uri'=>'uploads/stage/driver.wav','channel'=>$options[0]['code'],'quality'=>'source','ratio'=>'adaptive'];
    $path='/api/app.aigc_digital_human';
    $accepted=dataHttp($api,$path.'.generate/index'.$suffix,$driver,$token);
    MarketWorkerFixture::check(count($accepted['generation_tasks']??[])===1,'Avatar missing canonical stage');
    $finish('aigc_digital_human',$accepted['task_id'],'aigc_digital_human_task');
    $detail=dataHttp($api,$path.'.task/detail'.$suffix.'&id='.$accepted['task_id'],[],$token,'GET');
    MarketWorkerFixture::check(count($detail['results'])===1&&$detail['provider_payload_json']['drive_mode']==='audio','Avatar lost result or drive mode');
    $same=dataHttp($api,$path.'.generate/index'.$suffix,$driver,$token);
    MarketWorkerFixture::check($same['task_id']===$accepted['task_id'] && ($same['generation_task_refs']??[])===($detail['generation_task_refs']??[]),'Avatar duplicate reply lost canonical stage');
    // Age only the private synthetic history beyond its existing 60-second body deduplication window.
    think\facade\Db::name('aigc_digital_human_task')->where('id',$accepted['task_id'])->update(['create_time'=>time()-61]);
    $driver['driver_audio_uri']=$detail['tts_audio_uri'];
    $again=dataHttp($api,$path.'.generate/index'.$suffix,$driver,$token);
    MarketWorkerFixture::check($again['task_id']!==$accepted['task_id'],'Avatar history did not create a new attempt');
    $finish('aigc_digital_human',$again['task_id'],'aigc_digital_human_task');
    $report['digital_human_audio']=['normal_http_audio_driven_worker_result_and_reuse'=>true,'new_driver_intent_preserved'=>true,'text_tts_not_executed'=>true];

    $meta=['category_code'=>'video','supported_durations'=>[2],'supported_resolutions'=>['720P'],'supported_ratios'=>['1:1'],
        'params_schema'=>['properties'=>['prompt'=>['type'=>'string'],'duration'=>['type'=>'integer','enum'=>[2]],'resolution'=>['type'=>'string','enum'=>['720P']],'aspect_ratio'=>['type'=>'string','enum'=>['1:1']],'image_urls'=>['type'=>'array','items'=>['type'=>'string'],'minItems'=>1,'maxItems'=>1]],'required'=>['prompt','image_urls']],
        'material_capabilities'=>['modes'=>[['code'=>'image_to_video','method'=>'image_to_video','fields'=>[['field'=>'image_urls','kind'=>'image','role'=>'first','required'=>true,'minimum'=>1,'maximum'=>1]]]]]];
    $product=app\common\model\power\PowerMarketProduct::create(['product_code'=>$f->prefix.'micro','name'=>'Private micro','resource_type'=>'model','model_type'=>'video','source_code'=>'isolated-fixture','upstream_resource_key'=>$f->prefix.'micro','upstream_model_code'=>'synthetic-video','upstream_channel_code'=>'fixture','source_payload'=>['category_code'=>'video','market_metadata'=>$meta],'status'=>1]);
    app\common\model\power\PowerMarketSku::create(['product_id'=>$product['id'],'sku_key'=>$f->prefix.'micro','title'=>'Private micro','locked_params'=>['duration'=>2,'resolution'=>'720P'],'usage_unit'=>'per_call','usage_unit_size'=>1,'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1]);
    $model='market_video_model:'.$product['id'];
    $options=app\common\service\app\aigc_video\AigcVideoService::marketOptions($tenant);
    $chosen=array_values(array_filter($options['channels']??[],fn($row)=>$row['id']===$model));
    MarketWorkerFixture::check(count($chosen)===1,'Private micro model missing from shared options');
    try { app\common\service\app\aigc_micro_video\MicroVideoCatalog::quote($tenant,['quality'=>'720P','ratio'=>'1:1','duration'=>2,'generation_method'=>'image_to_video','resource_type'=>'model','market_product_id'=>(int)$product['id']],$model); }
    catch(Throwable $e){throw new RuntimeException('Private micro admission: '.$e->getMessage());}
    app\common\service\app\aigc_micro_video\MicroVideoSettings::save($tenant,['video_model_ids'=>[$model],'default_model_id'=>$model,'default_quality'=>'720P','default_ratio'=>'1:1','target_duration_options'=>[4],'frames_enabled'=>0]);
    $form=['model_id'=>$model,'quality'=>'720P','ratio'=>'1:1','target_duration'=>4,'mode'=>'theme','theme'=>'Synthetic colored blocks','images'=>[['uri'=>$image]],'direction_id'=>0,'idempotency_key'=>'private-micro-plan'];
    $path='/api/app.aigc_micro_video';$plan=dataHttp($api,$path.'.plan/create'.$suffix,$form,$token);$id=(int)$plan['id'];
    $segments=[];foreach([1,2] as $i)$segments[]=['title'=>'Synthetic scene '.$i,'duration'=>2,'prompt'=>"0-1秒：彩色色块移动。\n1-2秒：色块继续移动。",'image_indices'=>[0],'start_state'=>'色块在左','end_state'=>'色块在右','continuity'=>'保持同一色块与背景'];
    think\facade\Db::name('aigc_micro_video_task')->where('id',$id)->update(['analysis'=>'Synthetic block fixture']);
    think\facade\Db::name('aigc_micro_video_attempt')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'task_id'=>$id,'segment_id'=>0,'kind'=>'script','attempt_no'=>1,'status'=>'success','request_json'=>'{}','result_json'=>json_encode(['content'=>json_encode(['continuity'=>'同一色块','segments'=>$segments],JSON_UNESCAPED_UNICODE)],JSON_UNESCAPED_UNICODE),'create_time'=>time(),'update_time'=>time()]);
    $f->start($file,'fixture_micro_runner','micro');
    MarketWorkerFixture::wait(fn()=>think\facade\Db::name('aigc_micro_video_task')->where('id',$id)->value('status')==='awaiting_confirmation','Micro cached plan was not validated',25);$f->stop('micro');
    $plan=dataHttp($api,$path.'.task/detail'.$suffix.'&id='.$id,[],$token,'GET');
    MarketWorkerFixture::check(count($plan['segments'])===2,'Micro plan lost segment inputs');
    $quote=dataHttp($api,$path.'.generate/estimate'.$suffix,['id'=>$id,'plan_revision'=>$plan['plan_revision']],$token);
    $confirm=['id'=>$id,'plan_revision'=>$plan['plan_revision'],'quote_token'=>$quote['quote_token'],'idempotency_key'=>'private-micro-confirm'];
    dataHttp($api,$path.'.generate/index'.$suffix,$confirm,$token);dataHttp($api,$path.'.generate/index'.$suffix,$confirm,$token);
    file_put_contents($f->dir.'/hold-from-index',(string)(count(file($f->dir.'/submissions.jsonl'))+1));
    $f->start($file,'fixture_micro_runner','micro');$f->start($file,'aigc_micro_video');
    MarketWorkerFixture::wait(fn()=>think\facade\Db::name('aigc_micro_video_attempt')->where(['task_id'=>$id,'kind'=>'video'])->where('consumption_id','>',0)->count()===2,'Micro did not reserve two distinct attempts',25);
    think\facade\Db::name('power_market_sku')->where('product_id',$product['id'])->update(['sale_points'=>99,'sale_status'=>0]);
    unlink($f->dir.'/hold-from-index');
    MarketWorkerFixture::wait(fn()=>think\facade\Db::name('aigc_micro_video_task')->where('id',$id)->value('status')==='success','Micro two-segment result or merge failed',60);
    $f->stop('micro');MarketWorkerFixture::wait(fn()=>think\facade\Db::name('ai_task_job')->where('status','<>','success')->count()===0,'Micro result jobs pending',20);$f->stop();
    $result=dataHttp($api,$path.'.task/detail'.$suffix.'&id='.$id,[],$token,'GET');
    MarketWorkerFixture::check(count($result['generation_tasks'])===2&&$result['result_url']!==''&&$result['actual_duration']>0,'Micro missing composed result or child task identities');
    foreach(think\facade\Db::name('aigc_micro_video_attempt')->where(['task_id'=>$id,'kind'=>'video'])->select() as $attempt) {
        $saved=json_decode($attempt['request_json'],true);$request=$saved['request'];
        MarketWorkerFixture::check(!isset($saved['selection']['prompt'])&&$request['prompt']!==''&&!str_contains($request['prompt'],'Video segment pricing'),'Micro persisted or submitted pricing placeholder');
        MarketWorkerFixture::check($request['generation_method']==='image_to_video'&&(int)$request['duration']===2&&$request['ratio']==='1:1','Micro lost concrete single-frame specification');
        $bill=think\facade\Db::name('ai_consumption_log')->where('id',$attempt['consumption_id'])->find();
        MarketWorkerFixture::check((float)$bill['actual_user_price']===1.,'Micro changed an admitted segment price');
    }
    $report['micro_video']=['http_create_confirm_duplicate_worker_two_segments_merge_reopen'=>true,'planning_receipt_seeded_not_llm'=>true,'frame_generation_disabled'=>true,'actual_duration'=>$result['actual_duration'],'read_only_prompt_not_persisted'=>true,'admitted_prices_survive_private_shelf_change'=>true];
}
