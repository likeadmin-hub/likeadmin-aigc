<?php
/** Private fixture is driven by the actual mobile request layer running on the host. */
function runMarketMobileHttp($f,$api,$peer,$token,$video,$channel,array &$report): void
{
    $db=think\facade\Db::class;$script=dirname(__DIR__).'/market-generation-http.php';
    $native=array_intersect_key($video,array_flip(['resource_type','model_id','market_product_id','market_sku_id','channel','prompt','duration','quality','ratio','generation_method','advanced_params']));
    $native['prompt']='Synthetic mobile batch video';
    $input=['api'=>$api,'api_peer'=>$peer,'tenant'=>$f->tenant,'token'=>$token,'video'=>$native,'image'=>['channel'=>$channel,'prompt'=>'Synthetic mobile batch image','quality'=>'1k','ratio'=>'1:1','quantity'=>1]];
    file_put_contents($f->dir.'/mobile-input.json',json_encode($input,JSON_THROW_ON_ERROR));fwrite(STDERR,'MOBILE_FIXTURE_READY '.$f->dir."\n");
    $read=function($name)use($f){if(is_file($f->dir.'/mobile-failed.json'))throw new RuntimeException(file_get_contents($f->dir.'/mobile-failed.json'));return is_file($f->dir.'/'.$name)?json_decode(file_get_contents($f->dir.'/'.$name),true):null;};
    $drain=function()use($f,$script,$db){foreach(['aigc_image','aigc_video']as$app){$f->start($script,$app);MarketWorkerFixture::wait(fn()=>$db::name('ai_task_job')->where('app_code',$app)->where('status','<>','success')->count()===0,'Mobile results incomplete '.$app,40);$f->stop();}};
    $submitted=null;MarketWorkerFixture::wait(function()use($read,&$submitted){return (bool)($submitted=$read('mobile-submitted.json'));},'Host mobile submit did not finish',120);
    $frozen=$db::name('ai_consumption_log')->order('id')->column('price_snapshot','id');$drain();
    $old=[];foreach(['aigc_image_task','aigc_video_task']as$table)$old[$table]=$db::name($table)->order('id')->select()->toArray();
    file_put_contents($f->dir.'/mobile-ready.json','true');$history=null;
    MarketWorkerFixture::wait(function()use($read,&$history){return (bool)($history=$read('mobile-history.json'));},'Host mobile history did not finish',120);$drain();
    foreach($old as$table=>$rows)MarketWorkerFixture::check($rows===$db::name($table)->whereIn('id',array_column($rows,'id'))->order('id')->select()->toArray(),'Mobile history changed old native tasks');
    foreach($frozen as$id=>$snapshot)MarketWorkerFixture::check($snapshot===$db::name('ai_consumption_log')->where('id',$id)->value('price_snapshot'),'Mobile retry changed frozen specification');
    file_put_contents($f->dir.'/mobile-history-ready.json','true');$done=null;
    MarketWorkerFixture::wait(function()use($read,&$done){return (bool)($done=$read('mobile-done.json'));},'Host mobile readback did not finish',120);
    $report=$done+['scope'=>'Private authenticated HTTP driven by actual mobile hooks and API wrappers; synthetic loopback suppliers only','lost_response'=>$submitted['lost_response'],'batch_keys_reused'=>$submitted['batch_keys_reused'],'first_image_tasks'=>count($submitted['images']),'first_video_tasks'=>count($submitted['videos']),'history_new_tasks'=>count($history['images'])+count($history['videos']),'old_native_rows_unchanged'=>true,'frozen_snapshots_unchanged'=>true];
}
