<?php

/** Runs only inside the private authenticated HTTP fixture, never against real tenant tables or suppliers. */
function runMarketToolHttpMatrix(MarketWorkerFixture $fixture, string $api, string $suffix, string $token, string $channel, string $uri): array
{
    $tenant=$fixture->tenant;$user=$fixture->user;
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
        'fitting'=>['garment_images'=>[$uri],'model_images'=>[$uri],'mode'=>'custom','quantity'=>1,'prompt'=>'Synthetic fitting'],
    ];
    $report=[];
    foreach($cases as$code=>$input) {
        $class='app\\common\\service\\app\\aigc_'.$code.'\\Aigc'.str_replace(' ','',ucwords(str_replace('_',' ',$code))).'Service';
        $package=['code'=>'private-fixture','name'=>'Private fixture','channel'=>$channel,'quality'=>'1k','ratio'=>'1:1','unit_price'=>3,'status'=>1];
        $class::saveConfig($tenant,['status'=>1,'default_channel'=>$channel,'default_quality'=>'1k','default_ratio'=>'1:1','unit_price'=>3,
            'config_json'=>['channel'=>$channel,'quality'=>'1k','ratio'=>'1:1','unit_price'=>3,'price_packages'=>[$package]],'price_config'=>[$package]]);
        $config=dataHttp($api,'/api/app.aigc_'.$code.'.config/detail'.$suffix,[],$token,'GET');
        $input+=['channel'=>$channel,'quality'=>'1k','ratio'=>'1:1'];
        if($code==='style_transfer') {
            $category=\think\facade\Db::name('aigc_style_transfer_style_category')->where('tenant_id',$tenant)->value('id');
            $template=$class::saveTemplate($tenant,['category_id'=>$category,'name'=>'Private synthetic style','image'=>$uri]);
            $input['template_id']=$template['id'];
        }
        if($code==='one_click_cleanup')$input['option_codes']=[$config['options'][0]['code']];
        if($code==='product_multi_angle')$input['view_codes']=[$config['views'][0]['code']];
        $packages=$config['price_packages']??$config['price_options']??[];
        if($packages)$input['price_package_code']=$packages[0]['code'];
        if($code==='outpaint')$input['spec_code']=$input['price_package_code'];
        $path='/api/app.aigc_'.$code;
        $estimate=dataHttp($api,$path.'.generate/estimate'.$suffix,$input,$token);
        $before=(float)\think\facade\Db::name('user')->where('id',$user)->value('user_money');
        $accepted=dataHttp($api,$path.'.generate/index'.$suffix,$input,$token);
        MarketWorkerFixture::check(count($accepted['generation_tasks']??[])===1,$code.' did not return one owned canonical child');
        $fixture->start(dirname(__DIR__).'/market-generation-http.php','aigc_image');
        MarketWorkerFixture::wait(fn()=>\app\common\service\ai\GenerationTaskResultService::read((int)$accepted['generation_tasks'][0]['app_task_id'],$tenant,$user)['status']==='success',$code.' child did not finish',30);
        $fixture->stop();
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
        $reestimate=dataHttp($api,$path.'.generate/estimate'.$suffix,$reused,$token);
        MarketWorkerFixture::check((float)$reestimate['user_charge_points']===(float)$estimate['user_charge_points'],$code.' historical reuse changed estimate');
        $reaccepted=dataHttp($api,$path.'.generate/index'.$suffix,$reused,$token);
        MarketWorkerFixture::check($reaccepted['task_id']!==$accepted['task_id']&&($reaccepted['generation_task_refs']??[])!==($accepted['generation_task_refs']??[]),$code.' new attempt reused old identity');
        $fixture->start(dirname(__DIR__).'/market-generation-http.php','aigc_image');
        MarketWorkerFixture::wait(fn()=>\app\common\service\ai\GenerationTaskResultService::read((int)$reaccepted['generation_tasks'][0]['app_task_id'],$tenant,$user)['status']==='success',$code.' reused child did not finish',30);
        $fixture->stop();
        $redetail=dataHttp($api,$path.'.task/detail'.$suffix.'&id='.$reaccepted['task_id'],[],$token,'GET');
        MarketWorkerFixture::check(($redetail['status']??'')==='success'&&count($redetail['results']??[])===1,$code.' reused result failed');
        foreach(['source_image','source_images','product_image','product_images','custom_scene_image','model_image','model_images','wear_image','clothes_images','garment_images','mask_image','restore_type','source_language','target_language','module_codes','view_codes','option_codes','ratio','quality','channel']as$key)
            if(array_key_exists($key,$detail))MarketWorkerFixture::check($detail[$key]===($redetail[$key]??null),$code.' reused field changed: '.$key);
        $report[$code]=['normal_http_estimate_submit'=>true,'managed_result_and_business_writeback'=>true,'list_detail_same_owned_children'=>true,'history_rebuilt_new_attempt'=>true,'history_parameters_and_results_preserved'=>true,'user_price'=>(float)$estimate['user_charge_points']];
    }
    return $report;
}
