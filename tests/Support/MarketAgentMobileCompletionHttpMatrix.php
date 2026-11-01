<?php
/** Mobile remainder: owned subject references, frozen preferences, DOCX result/history; no external inference. */
function runMarketAgentMobileCompletionHttp($f,string $api,string $peer,string $suffix,string $token,string $image,int $text,array &$report): void
{
    $db=think\facade\Db::class;$check=fn($ok,$why)=>MarketWorkerFixture::check((bool)$ok,$why);$script=dirname(__DIR__).'/market-generation-http.php';
    $post=fn($a,$p)=>dataHttp($api,'/api/app.aigc_llm.agent/'.$a.$suffix,$p,$token);
    $login=fn($terminal)=>dataHttp($peer,'/api/login/account'.$suffix,['account'=>$f->prefix,'password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>$terminal])['token'];
    $row=$db::name('power_market_product')->where('id',$text)->find();$source=json_decode($row['source_payload'],true);$source['market_metadata']['supports_vision']=true;$db::name('power_market_product')->where('id',$text)->update(['source_payload'=>json_encode($source)]);
    $post('preferences',['mode'=>'ask','models'=>['image'=>$image],'generation'=>['mode'=>'custom','image'=>['quality'=>'1k','ratio'=>'1:1']]]);
    mkdir($f->dir.'/public/resource',0700,true);file_put_contents($f->dir.'/public/resource/b73-subject.png',base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII='));
    $subject=$db::name('aigc_short_drama_subject')->insertGetId(['tenant_id'=>$f->tenant,'user_id'=>0,'source'=>'public','name'=>'Synthetic public subject','image'=>'resource/b73-subject.png','description'=>'Synthetic colored block.','category'=>'character','status'=>1,'delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
    $foreign=$db::name('aigc_short_drama_subject')->insertGetId(['tenant_id'=>$f->tenant+1,'user_id'=>0,'source'=>'public','name'=>'Foreign private subject','image'=>'resource/b73-subject.png','category'=>'character','status'=>1,'delete_time'=>0]);
    $input=['api'=>$api,'api_peer'=>$peer,'tenant'=>$f->tenant,'account'=>$f->prefix,'tokens'=>['pc'=>$token,'h5'=>$login(3),'mp'=>$login(1)],'image_channel'=>$image,'subject_id'=>$subject,'foreign_subject_id'=>$foreign,'ui'=>getenv('AIGC_HTTP_AGENT_MOBILE_COMPLETION_UI')==='1'];
    $write=fn($data)=>file_put_contents($f->dir.'/mobile-input.json',json_encode($data,JSON_THROW_ON_ERROR));$write($input);
    $wait=function($name,$seconds=120)use($f){$row=null;MarketWorkerFixture::wait(function()use($f,$name,&$row){if(is_file($f->dir.'/mobile-failed.json'))throw new RuntimeException(file_get_contents($f->dir.'/mobile-failed.json'));return is_file($f->dir.'/'.$name)&&($row=json_decode(file_get_contents($f->dir.'/'.$name),true));},'Mobile completion phase missing '.$name,$seconds);return$row;};
    $f->start($script,'fixture_agent_runner','agent');$f->start($script,'aigc_image','worker');
    try {
        if($input['ui']){fwrite(STDERR,'MOBILE_AGENT_COMPLETION_UI_READY '.$f->dir.chr(10));$ui=$wait('mobile-ui-done.json',600);$report['actual_h5']=$ui;}
        $input['tokens']['h5']=$login(3);$input['tokens']['mp']=$login(1);$write($input);fwrite(STDERR,'MOBILE_AGENT_COMPLETION_READY '.$f->dir.chr(10));$host=$wait('mobile-done.json',180);
        foreach($host['run_ids']as$id){$run=$db::name('aigc_llm_agent_run')->where('id',$id)->find();$check($run['status']==='success','Mobile completion run not successful');}
        $imageRun=$db::name('aigc_llm_agent_run')->where('id',$host['image_run_id'])->find();$prefs=json_decode($imageRun['preferences'],true);$check($prefs['generation']['image']===['ratio'=>'1:1','quality'=>'1k']&&$prefs['models']['image']===$image,'Original frozen image preference changed');
        $refs=json_decode($imageRun['attachments'],true);$check(count($refs)===3&&count(array_filter($refs,fn($a)=>($a['subject_id']??0)===$subject))===1,'Subject or mixed references lost');
        $step=$db::name('aigc_llm_agent_step')->where('run_id',$imageRun['id'])->find();$prepared=json_decode($step['prepared'],true);$check(count($prepared['inputs']['reference_images']??[])===2,'Both subject and uploaded image did not reach native preparation');
        $doc=$db::name('aigc_llm_agent_artifact')->where(['run_id'=>$host['document_run_id'],'kind'=>'document'])->find();$data=json_decode($doc['data'],true);$file=$db::name('open_platform_file')->where('file_id',$data['file_id'])->find();$zip=new ZipArchive();$check($file&&$file['storage_engine']==='local'&&$zip->open($f->dir.'/public/'.$file['uri'])===true,'DOCX result storage invalid');$xml=$zip->getFromName('word/document.xml');$zip->close();$check(str_contains($xml,'隔离移动文档正文'),'DOCX body missing');
        $report=$host+$report+['original_subject_and_upload_native_preparation'=>true,'original_document_managed_storage_and_zip'=>true,'scope'=>'Isolated normal-login H5/MP wrappers and optional actual H5 UI; original services and loopback suppliers only'];
    } finally {$f->stop('agent');$f->stop('worker');}
}
