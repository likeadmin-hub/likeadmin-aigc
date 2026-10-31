<?php

/** Runs only inside the private authenticated HTTP fixture, never against real tenant tables or suppliers. */
function runMarketToolHttpMatrix(MarketWorkerFixture $fixture, string $api, string $suffix, string $token, string $channel, string $uri, array &$report): array
{
    $tenant=$fixture->tenant;$user=$fixture->user;
    $videoMeta=['category_code'=>'video','supported_durations'=>[1],'supported_resolutions'=>['720P'],'supported_ratios'=>['1:1'],
        'params_schema'=>['properties'=>['prompt'=>['type'=>'string'],'duration'=>['type'=>'integer','enum'=>[1]],'resolution'=>['type'=>'string','enum'=>['720P']],'aspect_ratio'=>['type'=>'string','enum'=>['1:1']],'image_urls'=>['type'=>'array','items'=>['type'=>'string'],'minItems'=>1,'maxItems'=>1]],'required'=>['prompt','image_urls']],
        'material_capabilities'=>['modes'=>[['code'=>'image_to_video','method'=>'image_to_video','fields'=>[['field'=>'image_urls','kind'=>'image','role'=>'first','required'=>true,'minimum'=>1,'maximum'=>1]]]]]];
    $videoProduct=\app\common\model\power\PowerMarketProduct::create(['product_code'=>$fixture->prefix.'promo','name'=>'Private promo video','resource_type'=>'model','model_type'=>'video','source_code'=>'isolated-fixture','upstream_resource_key'=>$fixture->prefix.'promo','upstream_model_code'=>'synthetic-video','upstream_channel_code'=>'fixture','source_payload'=>['category_code'=>'video','market_metadata'=>$videoMeta],'status'=>1]);
    \app\common\model\power\PowerMarketSku::create(['product_id'=>$videoProduct['id'],'sku_key'=>$fixture->prefix.'promo','title'=>'Private promo fixture','locked_params'=>['duration'=>1,'resolution'=>'720P'],'usage_unit'=>'per_call','usage_unit_size'=>1,'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1,'source_payload'=>['meter_contract'=>['version'=>1,'basis'=>'call','rounding'=>'ceil']]]);
    $videoChannel='market_video_model:'.$videoProduct['id'];
    $cases=[
        'product_image'=>['product_image'=>$uri,'custom_scene_image'=>$uri,'scene_mode'=>'custom','size_key'=>'1:1','prompt'=>'Synthetic product'],
        'product_suite'=>['product_images'=>[$uri],'module_codes'=>['hero'],'prompt'=>'Synthetic suite'],
        'style_transfer'=>['source_image'=>$uri,'size_key'=>'1:1','prompt'=>'Synthetic style'],
        'photo_restore'=>['source_image'=>$uri,'restore_type'=>'repair','prompt'=>'Synthetic repair'],
        'model_wear'=>['model_image'=>$uri,'wear_image'=>$uri,'prompt'=>'Synthetic wear'],
        'background_removal'=>['source_image'=>$uri],
        'image_translate'=>['source_image'=>$uri,'source_language'=>'auto','target_language'=>'en','prompt'=>'Synthetic translation'],
        'one_click_cleanup'=>['source_images'=>[$uri]],
        'product_multi_angle'=>['source_image'=>$uri,'prompt'=>'Synthetic angle'],
        'fashion_lookbook'=>['clothes_images'=>[$uri],'model_image'=>$uri,'prompt'=>'Synthetic clothes'],
        'outpaint'=>['source_image'=>$uri],
        'local_redraw'=>['source_image'=>$uri,'mask_image'=>$uri,'prompt'=>'Synthetic redraw'],
        'product_promo_video'=>['source_image'=>$uri,'ratio'=>'1:1','quality'=>'720P','duration'=>1,'prompt'=>'Synthetic promo'],
        'fitting'=>['garment_images'=>[$uri],'model_images'=>[$uri],'mode'=>'custom','quantity'=>1,'prompt'=>'Synthetic fitting'],
    ];
    $report=[];
    foreach($cases as$code=>$input) {
        $class='app\\common\\service\\app\\aigc_'.$code.'\\Aigc'.str_replace(' ','',ucwords(str_replace('_',' ',$code))).'Service';
        $caseChannel=$code==='product_promo_video'?$videoChannel:$channel;
        $caseQuality=$code==='product_promo_video'?'720P':'1k';
        $package=['code'=>'private-fixture','name'=>'Private fixture','channel'=>$caseChannel,'quality'=>$caseQuality,'ratio'=>'1:1','unit_price'=>3,'status'=>1];
        $class::saveConfig($tenant,['status'=>1,'default_channel'=>$caseChannel,'default_quality'=>$caseQuality,'default_ratio'=>'1:1','unit_price'=>3,
            'config_json'=>['channel'=>$caseChannel,'quality'=>$caseQuality,'ratio'=>'1:1','unit_price'=>3,'price_packages'=>[$package]],'price_config'=>[$package]]);
        $config=dataHttp($api,'/api/app.aigc_'.$code.'.config/detail'.$suffix,[],$token,'GET');
        $input+=['channel'=>$caseChannel,'quality'=>$caseQuality,'ratio'=>'1:1'];
        if($code==='style_transfer') {
            $category=$class::categoryLists($tenant)[0]['id'];
            $template=$class::saveTemplate($tenant,['category_id'=>$category,'name'=>'Private synthetic style','image'=>$uri]);
            $input['template_id']=$template['id'];
        }
        if($code==='product_promo_video') {
            $input['video_type_code']=$config['types'][0]['code'];
            $input['idempotency_key']=$fixture->prefix.'promo-first';
        }
        if($code==='one_click_cleanup')$input['option_codes']=[$config['options'][0]['code']];
        if($code==='product_multi_angle')$input['view_codes']=[$config['views'][0]['code']];
        $packages=$config['price_packages']??$config['price_options']??[];
        if($packages)$input['price_package_code']=$packages[0]['code'];
        if($code==='outpaint')$input['spec_code']=$input['price_package_code'];
        $path='/api/app.aigc_'.$code;
        try {$estimate=dataHttp($api,$path.'.generate/estimate'.$suffix,$input,$token);}
        catch(\Throwable $error) {
            $prepare=new ReflectionMethod($class,'prepareGeneratePayload');$prepare->setAccessible(true);
            $prepared=$prepare->invoke(null,$tenant,$input,true);
            throw new RuntimeException($code.': '.$error->getMessage().' prepared references: '.json_encode($prepared['image_payload']['reference_images']??[]),0,$error);
        }
        $before=(float)\think\facade\Db::name('user')->where('id',$user)->value('user_money');
        $tenantBefore=(float)\think\facade\Db::name('tenant')->where('id',$tenant)->value('point_balance');
        file_put_contents($fixture->dir.'/hold-from-index',(string)(count(file($fixture->dir.'/submissions.jsonl'))+1));
        $accepted=dataHttp($api,$path.'.generate/index'.$suffix,$input,$token);
        MarketWorkerFixture::check(count($accepted['generation_tasks']??[])===1,$code.' did not return one owned canonical child');
        if($code==='product_promo_video') {
            $same=dataHttp($api,$path.'.generate/index'.$suffix,$input,$token);
            MarketWorkerFixture::check($same['task_id']===$accepted['task_id'],$code.' same request key created a duplicate task');
            $native=\app\common\model\app\aigc_video\AigcVideoTask::find($accepted['video_task_id']);
            $lookup=new ReflectionMethod(\app\common\service\app\aigc_video\AigcVideoService::class,'findRecentMarketDuplicateTask');
            $scopeParams=['reference_assets'=>(array)$native['reference_assets'],'reference_images'=>(array)$native['reference_images'],
                'ratio'=>$native['ratio'],'duration'=>$native['duration'],'idempotency_key'=>$input['idempotency_key']];
            MarketWorkerFixture::check($lookup->invoke(null,$tenant,$user,$native['prompt'],(array)$native['model_json'],$scopeParams,'aigc_product_promo_video')!==null,'Same-app exact video intent was not reusable');
            MarketWorkerFixture::check($lookup->invoke(null,$tenant,$user,$native['prompt'],(array)$native['model_json'],$scopeParams,'aigc_video')===null,'Native entry reused another app billing owner');
        }
        unlink($fixture->dir.'/hold-from-index');
        $workerApp=$accepted['generation_tasks'][0]['app_code'];
        $fixture->start(dirname(__DIR__).'/market-generation-http.php',$workerApp);
        MarketWorkerFixture::wait(fn()=>\app\common\service\ai\GenerationTaskResultService::read((int)$accepted['generation_tasks'][0]['app_task_id'],$tenant,$user)['status']==='success',$code.' child did not finish',30);
        MarketWorkerFixture::wait(fn()=>\think\facade\Db::name('ai_task_job')->whereIn('consumption_id',\think\facade\Db::name('ai_consumption_log')->where('app_task_id',$accepted['generation_tasks'][0]['app_task_id'])->column('id'))->where('status','<>','success')->count()===0,$code.' unfinished business handoff',30);
        $fixture->stop();
        MarketWorkerFixture::check(\think\facade\Db::name('aigc_'.$code.'_task')->where('id',$accepted['task_id'])->value('status')==='success',$code.' parent still needs HTTP detail to finish');
        $detail=dataHttp($api,$path.'.task/detail'.$suffix.'&id='.$accepted['task_id'],[],$token,'GET');
        MarketWorkerFixture::check(($detail['status']??'')==='success'&&count($detail['results']??[])===1&&($detail['generation_tasks'][0]['status']??'')==='success',$code.' result writeback failed');
        $after=(float)\think\facade\Db::name('user')->where('id',$user)->value('user_money');
        MarketWorkerFixture::check(abs(($before-$after)-(float)$estimate['user_charge_points'])<0.001,$code.' estimate/charge mismatch');
        $lists=dataHttp($api,$path.'.task/lists'.$suffix.'&light=1',[],$token,'GET');
        $row=array_values(array_filter($lists['lists'],static fn($r)=>(int)$r['id']===(int)$accepted['task_id']))[0]??[];
        MarketWorkerFixture::check(($row['generation_task_refs']??[])===($detail['generation_task_refs']??[])&&count($row['results']??[])===1,$code.' list/detail result identity mismatch');
        $reused=$input;
        foreach($input as$key=>$value)if(array_key_exists($key,$detail))$reused[$key]=$detail[$key];
        if(array_key_exists('prompt',$input))$reused['prompt']=$detail['user_prompt']??$input['prompt'];
        if($code==='product_promo_video')$reused['idempotency_key']=$fixture->prefix.'promo-second';
        $reestimate=dataHttp($api,$path.'.generate/estimate'.$suffix,$reused,$token);
        MarketWorkerFixture::check((float)$reestimate['user_charge_points']===(float)$estimate['user_charge_points'],$code.' historical reuse changed estimate');
        $reaccepted=dataHttp($api,$path.'.generate/index'.$suffix,$reused,$token);
        MarketWorkerFixture::check($reaccepted['task_id']!==$accepted['task_id']&&($reaccepted['generation_task_refs']??[])!==($accepted['generation_task_refs']??[]),$code.' new attempt reused old identity');
        $workerApp=$accepted['generation_tasks'][0]['app_code'];
        $fixture->start(dirname(__DIR__).'/market-generation-http.php',$workerApp);
        MarketWorkerFixture::wait(fn()=>\app\common\service\ai\GenerationTaskResultService::read((int)$reaccepted['generation_tasks'][0]['app_task_id'],$tenant,$user)['status']==='success',$code.' reused child did not finish',30);
        MarketWorkerFixture::wait(fn()=>\think\facade\Db::name('ai_task_job')->whereIn('consumption_id',\think\facade\Db::name('ai_consumption_log')->where('app_task_id',$reaccepted['generation_tasks'][0]['app_task_id'])->column('id'))->where('status','<>','success')->count()===0,$code.' unfinished business handoff',30);
        $fixture->stop();
        $redetail=dataHttp($api,$path.'.task/detail'.$suffix.'&id='.$reaccepted['task_id'],[],$token,'GET');
        MarketWorkerFixture::check(($redetail['status']??'')==='success'&&count($redetail['results']??[])===1,$code.' reused result failed');
        $replayUser=(float)\think\facade\Db::name('user')->where('id',$user)->value('user_money');
        $replayTenant=(float)\think\facade\Db::name('tenant')->where('id',$tenant)->value('point_balance');
        MarketWorkerFixture::check(abs($before-$replayUser-2*(float)$estimate['user_charge_points'])<0.001 && abs($tenantBefore-$replayTenant-2*(float)$estimate['tenant_cost_points'])<0.001,$code.' attempts did not each settle their quoted amounts once');
        foreach(['source_image','source_images','product_image','product_images','custom_scene_image','model_image','model_images','wear_image','clothes_images','garment_images','mask_image','restore_type','source_language','target_language','module_codes','view_codes','option_codes','ratio','quality','channel']as$key)
            if(array_key_exists($key,$detail))MarketWorkerFixture::check($detail[$key]===($redetail[$key]??null),$code.' reused field changed: '.$key);
        $report[$code]=['normal_http_estimate_submit'=>true,'managed_result_and_business_writeback'=>true,'parent_finished_without_http_detail'=>true,'list_detail_same_owned_children'=>true,'history_rebuilt_new_attempt'=>true,'history_parameters_and_results_preserved'=>true,'both_attempts_settled_quoted_amount_once'=>true,'user_price'=>(float)$estimate['user_charge_points']];
        if($code==='product_promo_video') {
            $report[$code]['explicit_new_intent_key_and_same_key_replay']=true;
            $report[$code]['cross_application_duplicate_scope_preserved']=true;
        }
    }
    return $report;
}
