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
    $driver=['title'=>'Synthetic avatar','avatar_id'=>$avatar,'driver_audio_uri'=>'uploads/stage/driver.wav','channel'=>$options[0]['code'],'quality'=>'source','ratio'=>'adaptive','idempotency_key'=>'stage-avatar-original'];
    $path='/api/app.aigc_digital_human';
    $accepted=dataHttp($api,$path.'.generate/index'.$suffix,$driver,$token);
    MarketWorkerFixture::check(count($accepted['generation_tasks']??[])===1,'Avatar missing canonical stage');
    $finish('aigc_digital_human',$accepted['task_id'],'aigc_digital_human_task');
    $detail=dataHttp($api,$path.'.task/detail'.$suffix.'&id='.$accepted['task_id'],[],$token,'GET');
    MarketWorkerFixture::check(count($detail['results'])===1&&$detail['provider_payload_json']['drive_mode']==='audio','Avatar lost result or drive mode');
    $same=dataHttp($api,$path.'.generate/index'.$suffix,$driver,$token);
    MarketWorkerFixture::check($same['task_id']===$accepted['task_id'] && ($same['generation_task_refs']??[])===($detail['generation_task_refs']??[]),'Avatar duplicate reply lost canonical stage');
    // A confirmed new key creates a new attempt immediately; do not age or rewrite the old history.
    $oldAvatar=think\facade\Db::name('aigc_digital_human_task')->where('id',$accepted['task_id'])->find();
    $driver['driver_audio_uri']=$detail['tts_audio_uri'];
    $driver['idempotency_key']='stage-avatar-new-attempt';
    $again=dataHttp($api,$path.'.generate/index'.$suffix,$driver,$token);
    MarketWorkerFixture::check($again['task_id']!==$accepted['task_id'],'Avatar history did not create a new attempt');
    $finish('aigc_digital_human',$again['task_id'],'aigc_digital_human_task');
    MarketWorkerFixture::check($oldAvatar===think\facade\Db::name('aigc_digital_human_task')->where('id',$accepted['task_id'])->find(),'New avatar intention rewrote old history');
    $report['digital_human_audio']=['normal_http_audio_driven_worker_result_and_reuse'=>true,'new_driver_intent_preserved'=>true,'text_tts_not_executed'=>true];

}
