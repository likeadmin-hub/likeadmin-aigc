<?php
/** Isolated production flows; only supplier/DNS socket boundaries are replaced. */
function runMarketDeepHttpMatrix(MarketWorkerFixture $f,string $api,string $suffix,string $token,string $image,string $supplier,array &$report): void
{
    $t=$f->tenant;$u=$f->user;$file=dirname(__DIR__).'/market-generation-http.php';putenv('AIGC_HTTP_FIXTURE_DIR='.$f->dir);
    $check=static fn($ok,$why)=>MarketWorkerFixture::check((bool)$ok,$why);
    $post=static fn($path,$p)=>dataHttp($api,'/api/app.'.$path.$suffix,$p,$token);
    $get=static fn($path,$id)=>dataHttp($api,'/api/app.'.$path.$suffix.'&id='.$id,[],$token,'GET');
    $product=function(string $name,string $type,string $model,array $meta,array $locked=[],string $unit='per_call',string $app='',string $op='')use($f){
        $p=app\common\model\power\PowerMarketProduct::create(['product_code'=>$f->prefix.$name,'name'=>'Private '.$name,'resource_type'=>$app===''?'model':'app_api','model_type'=>$type,'source_code'=>'isolated-fixture','upstream_resource_key'=>$f->prefix.$name,'upstream_model_code'=>$model,'upstream_channel_code'=>'fixture','upstream_app_code'=>$app,'upstream_api_code'=>$op,'source_payload'=>['category_code'=>$type,'market_metadata'=>$meta],'status'=>1]);
        $s=app\common\model\power\PowerMarketSku::create(['product_id'=>$p['id'],'sku_key'=>$f->prefix.$name,'title'=>'Private '.$name,'locked_params'=>$locked,'usage_unit'=>$unit,'usage_unit_size'=>1,'upstream_price'=>1,'sale_points'=>1,'sale_status'=>1,'status'=>1]);
        return [(int)$p['id'],(int)$s['id']];
    };
    if(getenv('AIGC_HTTP_DEEP_ONLY_MUSIC')!=='1') {
    $report['phase']='avatar_text_tts';
    $product('lipsync','audio','',[],['model'=>'synthetic-lipsync'],'second','lipsync','submit');
    $product('lipsync_query','audio','',[],[],'per_call','lipsync','query');
    $dir=$f->dir.'/public/uploads/deep';mkdir($dir,0700,true);copy($f->dir.'/synthetic.wav',$dir.'/input.wav');copy($f->dir.'/synthetic.mp4',$dir.'/avatar.mp4');
    $avatar=think\facade\Db::name('aigc_digital_human_avatar')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'name'=>'Synthetic blocks','source'=>'mine','media_type'=>'video','media_uri'=>'uploads/deep/avatar.mp4','provider'=>'xhadmin','provider_asset_id'=>'deep-avatar','status'=>1,'delete_time'=>0]);
    $voice=think\facade\Db::name('aigc_digital_human_voice')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'name'=>'Synthetic tone','source'=>'mine','provider'=>'xhadmin','provider_asset_id'=>'deep-voice','status'=>'ready','delete_time'=>0]);
    $channels=app\common\service\app\aigc_digital_human\DigitalHumanMarketService::channels($t);$check(count($channels)===1,'Deep lipsync channel unavailable');
    $form=['avatar_id'=>$avatar,'voice_id'=>$voice,'script_text'=>'彩色色块向右移动。','title'=>'Synthetic text avatar','channel'=>$channels[0]['code'],'quality'=>'source','ratio'=>'adaptive'];
    $accepted=$post('aigc_digital_human.generate/index',$form);$id=(int)$accepted['task_id'];
    $f->start($file,'aigc_digital_human');
    MarketWorkerFixture::wait(function()use($get,$id){$d=$get('aigc_digital_human.task/detail',$id);if($d['status']==='failed')throw new RuntimeException('TTS native failure: '.$d['error']);return $d['status']==='success';},'TTS then lipsync did not finish',35);
    MarketWorkerFixture::wait(fn()=>think\facade\Db::name('ai_task_job')->where('status','<>','success')->count()===0,'Avatar deep jobs not drained',20);$f->stop();
    $done=$get('aigc_digital_human.task/detail',$id);$check($done['tts_audio_uri']!==''&&count($done['results'])===1&&$done['provider_payload_json']['drive_mode']==='text','TTS driver/result intent missing');
    $same=$post('aigc_digital_human.generate/index',$form);$check($same['task_id']===$id,'TTS duplicate resubmitted');
    $report['avatar_text']=['normal_http_tts_query_audio_storage_lipsync_worker_result'=>true,'same_body_no_second_tts'=>true,'history_detail_preserves_text_and_voice'=>true];

    $report['phase']='micro_planning';
    [$text]=$product('vision','text','synthetic-text',['supports_vision'=>true,'protocol'=>'openai_chat','max_output_tokens'=>8000,'context_window'=>64000],[],'token');
    think\facade\Db::name('power_market_sku')->where('product_id',$text)->update(['usage_unit_size'=>1000]);
    $modes=[['code'=>'reference_to_video','method'=>'image_reference','fields'=>[['field'=>'image_urls','kind'=>'image','role'=>'reference','required'=>true,'minimum'=>1,'maximum'=>2]]],['code'=>'image_to_video','method'=>'start_end','first_last_frame'=>'支持','fields'=>[['field'=>'first_frame','kind'=>'image','role'=>'first','required'=>true,'minimum'=>1,'maximum'=>1],['field'=>'last_frame','kind'=>'image','role'=>'last','required'=>true,'minimum'=>1,'maximum'=>1]]]];
    [$video]=$product('frames_video','video','synthetic-video',['supported_durations'=>[2],'supported_resolutions'=>['720P'],'supported_ratios'=>['1:1'],'max_reference_images'=>2,'supports_first_last_frame'=>true,'params_schema'=>['prompt'=>['type'=>'string','required'=>true],'duration'=>['type'=>'integer','enum'=>[2]],'resolution'=>['type'=>'string','enum'=>['720P']],'aspect_ratio'=>['type'=>'string','enum'=>['1:1']],'image_urls'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>2],'first_frame'=>['type'=>'string'],'last_frame'=>['type'=>'string']],'material_capabilities'=>['modes'=>$modes]],['duration'=>2,'resolution'=>'720P']);
    [$frame]=$product('frames_image','image','synthetic-image',['max_reference_images'=>3,'params_schema'=>['prompt'=>['type'=>'string','required'=>true],'image_size'=>['type'=>'string','enum'=>['1K']],'aspect_ratio'=>['type'=>'string','enum'=>['1:1']],'image_urls'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>3]]],['image_size'=>'1K','aspect_ratio'=>'1:1']);
    $model='market_video_model:'.$video;$frameModel='market_image_model:'.$frame;
    $report['phase']='micro_reference_admission';
    app\common\service\app\aigc_micro_video\MicroVideoCatalog::quote($t,['quality'=>'720P','ratio'=>'1:1','duration'=>2,'generation_method'=>'image_reference','resource_type'=>'model','market_product_id'=>$video],$model);
    $report['phase']='micro_pair_admission';
    app\common\service\app\aigc_micro_video\MicroVideoCatalog::quote($t,['quality'=>'720P','ratio'=>'1:1','duration'=>2,'generation_method'=>'start_end','resource_type'=>'model','market_product_id'=>$video],$model);
    app\common\service\app\aigc_micro_video\MicroVideoSettings::save($t,['video_model_ids'=>[$model],'default_model_id'=>$model,'default_quality'=>'720P','default_ratio'=>'1:1','vision_model_id'=>(string)$text,'script_model_id'=>(string)$text,'target_duration_options'=>[4],'frames_enabled'=>1,'frame_model_ids'=>[$frameModel],'default_frame_model_id'=>$frameModel,'default_frame_quality'=>'1k']);
    $plan=$post('aigc_micro_video.plan/create',['model_id'=>$model,'quality'=>'720P','ratio'=>'1:1','target_duration'=>4,'mode'=>'theme','theme'=>'四秒彩色色块移动','images'=>[['uri'=>$image]],'direction_id'=>0,'idempotency_key'=>'deep-micro-plan']);$micro=(int)$plan['id'];
    $f->start($file,'fixture_micro_runner','micro');
    MarketWorkerFixture::wait(function()use($micro){$r=think\facade\Db::name('aigc_micro_video_task')->where('id',$micro)->find();if($r['status']==='waiting_continue')throw new RuntimeException('Micro planning: '.$r['error']);return $r['status']==='awaiting_confirmation';},'Unseeded planning did not finish',30);$f->stop('micro');
    $detail=$get('aigc_micro_video.task/detail',$micro);$check(count($detail['segments'])===2,'Native text planning lost segments');
    $textAttempts=think\facade\Db::name('aigc_micro_video_attempt')->where(['task_id'=>$micro,'segment_id'=>0])->whereIn('kind',['analysis','script'])->select()->toArray();
    $check(count($textAttempts)===2&&array_column($textAttempts,'status')===['success','success'],'Planning attempts not both settled');
    $report['text_settlements']=2;
    $report['micro_planning']=['unseeded_analysis_and_script_native_transport'=>true,'usage_settlement_and_saved_receipts'=>true,'two_validated_timeline_segments'=>true];

    $report['phase']='micro_frames';
    $configured=$post('aigc_micro_video.frame/configure',['id'=>$micro,'plan_revision'=>$detail['plan_revision'],'generation_mode'=>'start_end','frame_model_id'=>$frameModel,'frame_quality'=>'1k']);
    $quote=$post('aigc_micro_video.frame/estimate',['id'=>$micro,'plan_revision'=>$configured['plan_revision'],'frames_revision'=>$configured['frames_revision']]);
    $request=['id'=>$micro,'plan_revision'=>$configured['plan_revision'],'frames_revision'=>$configured['frames_revision'],'quote_token'=>$quote['quote_token'],'idempotency_key'=>'deep-frames-generate'];
    $post('aigc_micro_video.frame/generate',$request);$post('aigc_micro_video.frame/generate',$request);
    $f->start($file,'fixture_micro_runner','micro');$f->start($file,'aigc_micro_video');
    MarketWorkerFixture::wait(function()use($micro){$r=think\facade\Db::name('aigc_micro_video_task')->where('id',$micro)->find();if($r['frame_status']==='waiting_continue')throw new RuntimeException('Frame stage failed');return $r['frame_status']==='review';},'Three anchor images did not finish',65);
    $f->stop('micro');MarketWorkerFixture::wait(fn()=>think\facade\Db::name('ai_task_job')->where('status','<>','success')->count()===0,'Frame jobs pending',20);$f->stop();
    $detail=$get('aigc_micro_video.task/detail',$micro);$check(count($detail['frames'])===3&&array_column($detail['frames'],'status')===['success','success','success'],'Frame boundaries missing');
    foreach(think\facade\Db::name('aigc_micro_video_attempt')->where(['task_id'=>$micro,'kind'=>'frame'])->select() as $attempt) {
        $snapshot=json_decode($attempt['request_json'],true);
        $check(!isset($snapshot['selection']['prompt'])&&!str_contains($snapshot['request']['prompt'],'Image boundary pricing'),'Frame quote placeholder entered execution');
    }
    $denied=callHttp($api,'/api/app.aigc_micro_video.generate/estimate'.$suffix,['id'=>$micro],$token);
    $check($denied['code']!==1,'Video accepted unconfirmed anchor images');
    $ready=$post('aigc_micro_video.frame/confirm',['id'=>$micro,'plan_revision'=>$detail['plan_revision'],'frames_revision'=>$detail['frames_revision']]);
    $quote=$post('aigc_micro_video.generate/estimate',['id'=>$micro,'plan_revision'=>$ready['plan_revision']]);
    $confirm=['id'=>$micro,'plan_revision'=>$ready['plan_revision'],'quote_token'=>$quote['quote_token'],'idempotency_key'=>'deep-video-confirm'];$post('aigc_micro_video.generate/index',$confirm);$post('aigc_micro_video.generate/index',$confirm);
    $f->start($file,'fixture_micro_runner','micro');$f->start($file,'aigc_micro_video');
    MarketWorkerFixture::wait(function()use($micro){$r=think\facade\Db::name('aigc_micro_video_task')->where('id',$micro)->find();if($r['status']==='waiting_continue')throw new RuntimeException('Micro framed generation: '.$r['error']);return $r['status']==='success';},'Frame-driven segments/merge not finished',45);
    $f->stop('micro');MarketWorkerFixture::wait(fn()=>think\facade\Db::name('ai_task_job')->where('status','<>','success')->count()===0,'Video jobs pending',20);$f->stop();
    $detail=$get('aigc_micro_video.task/detail',$micro);$check($detail['result_url']!==''&&count($detail['generation_tasks'])>=5,'Micro canonical frame/video identities missing');
    $report['micro_media_result_counts']=[];
    foreach(think\facade\Db::name('aigc_micro_video_attempt')->where('task_id',$micro)->whereIn('kind',['frame','video'])->select() as $attempt) {
        $view=app\common\service\ai\GenerationTaskResultService::read((int)$attempt['app_task_id'],$t,$u);
        $report['micro_media_result_counts'][]=count($view['results']);
        $check($view['status']==='success'&&count($view['results'])===1,'Micro completed media missing canonical stored result');
    }
    $report['micro_frames']=['three_ordered_anchors_two_video_segments_actual_merge'=>true,'frame_and_video_confirmation_replay'=>true,'reopen_keeps_generation_identities'=>true,'actual_duration'=>$detail['actual_duration']];
    // Model a historical frame whose old worker wrote its file but skipped the
    // canonical handoff. Remove only these private test rows, never the file.
    $legacy=(int)think\facade\Db::name('aigc_micro_video_attempt')->where(['task_id'=>$micro,'kind'=>'frame'])->order('id')->value('consumption_id');
    $billBefore=think\facade\Db::name('ai_consumption_log')->where('id',$legacy)->find();$submitsBefore=count(file($f->dir.'/submissions.jsonl'));
    think\facade\Db::name('ai_task_job')->where('consumption_id',$legacy)->delete();
    think\facade\Db::name('ai_task_result_asset')->where('consumption_id',$legacy)->delete();
    $get('aigc_micro_video.task/detail',$micro);$f->start($file,'aigc_micro_video');
    MarketWorkerFixture::wait(fn()=>think\facade\Db::name('ai_task_result_asset')->where(['consumption_id'=>$legacy,'transfer_status'=>'stored'])->count()===1,'Legacy frame handoff not repaired',20);
    MarketWorkerFixture::wait(fn()=>think\facade\Db::name('ai_task_job')->where('status','<>','success')->count()===0,'Legacy frame repair pending',20);$f->stop();
    $check($billBefore===think\facade\Db::name('ai_consumption_log')->where('id',$legacy)->find()&&$submitsBefore===count(file($f->dir.'/submissions.jsonl')),'Legacy repair changed bill or called supplier');
    $report['micro_frames']['legacy_missing_handoff_recovered_without_submission_or_billing']=true;

    }
    $report['phase']='music_clone_exports';
    $dir=$f->dir.'/public/uploads/deep';if(!is_dir($dir))mkdir($dir,0700,true);copy($f->dir.'/synthetic.wav',$dir.'/input.wav');
    file_put_contents($f->dir.'/synthetic.midi',hex2bin('4d546864000000060000000100604d54726b0000000c00903c4060803c4000ff2f00'));
    foreach(['voice_clone','wav','midi','mp4','vox','timing','upload_audio','persona']as$op){[$p,$sku]=$product('music_'.$op,'audio','',[],[],'per_call','music_generation',$op);if($op==='timing')think\facade\Db::name('power_market_sku')->where('id',$sku)->update(['sale_points'=>0,'upstream_price'=>0]);}
    think\facade\Db::name('tenant_file')->insert(['tenant_id'=>$t,'source_id'=>$u,'source'=>app\common\enum\FileEnum::SOURCE_USER,'type'=>app\common\enum\FileEnum::FILE_TYPE,'uri'=>'uploads/deep/input.wav','name'=>'Synthetic tone','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']);
    $asset=think\facade\Db::name('aigc_music_asset')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'asset_type'=>'voice_reference','title'=>'Synthetic tone','uri'=>'uploads/deep/input.wav','storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'','duration'=>1,'status'=>1,'delete_time'=>0]);
    think\facade\Db::name('user')->insert(['id'=>$u+2,'tenant_id'=>$t,'sn'=>$u+2,'account'=>$f->prefix.'deep_other','nickname'=>'Synthetic other','password'=>create_password('Private-fixture-pass-2026',config('project.unique_identification')),'user_money'=>100]);
    $other=dataHttp($api,'/api/login/account'.$suffix,['account'=>$f->prefix.'deep_other','password'=>'Private-fixture-pass-2026','scene'=>1,'terminal'=>4])['token'];
    $denied=callHttp($api,'/api/app.aigc_music.voice_clone/create'.$suffix,['reference_asset_id'=>$asset,'name'=>'Synthetic rejected'],$other);
    $check($denied['code']!==1,'Cross-owner voice clone accepted');
    $source=think\facade\Db::name('aigc_music_result')->insertGetId(['tenant_id'=>$t,'user_id'=>$u,'task_id'=>0,'title'=>'Synthetic tone','audio_uri'=>'uploads/deep/input.wav','result_json'=>json_encode(['audio_id'=>'deep-owned-audio']),'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'','delete_time'=>0]);
    $clone=$post('aigc_music.voice_clone/create',['reference_asset_id'=>$asset,'name'=>'Synthetic clone','description'=>'Synthetic tone only','authorization_confirmed'=>1,'authorization_text'=>'Private synthetic fixture']);
    $check(($clone['status']??'')==='success'&&($clone['voice_clone_id']??0)>0,'Music clone not written to native owner');
    $report['music_clone']=['normal_http_owned_upload_authorization_voice_id_and_native_history'=>true,'cross_owner_submission_rejected'=>true];
    foreach(['upload_audio'=>'asset/upload_upstream','persona'=>'persona/upstream']as$op=>$route){$p=$op==='upload_audio'?['asset_id'=>$asset]:['result_id'=>$source,'name'=>'Synthetic persona','description'=>'Synthetic'];$reply=$post('aigc_music.'.$route,$p);$check($reply['status']==='success','Music '.$op.' not ready');}
    foreach(['wav','midi','mp4','vox','timing']as$op) {
        $accepted=$post('aigc_music.export/'.$op,['result_id'=>$source]);$native=(int)$accepted['task_id'];
        $f->start($file,'aigc_music');
        MarketWorkerFixture::wait(fn()=>think\facade\Db::name('aigc_music_task')->where('id',$native)->value('status')==='success','Music '.$op.' recovery not finished',25);
        MarketWorkerFixture::wait(fn()=>think\facade\Db::name('ai_task_job')->where('status','<>','success')->count()===0,'Music recovery job pending',20);$f->stop();
        $done=$get('aigc_music.task/detail',$native);$saved=think\facade\Db::name('aigc_music_task')->where('id',$native)->find();$get('aigc_music.task/detail',$native);
        $check($saved===think\facade\Db::name('aigc_music_task')->where('id',$native)->find(),'Music terminal detail rewrote row');
        if(in_array($op,['wav','midi','mp4'],true)) {
            $export=think\facade\Db::name('aigc_music_export')->where(['tenant_id'=>$t,'user_id'=>$u,'result_id'=>$source,'export_type'=>$op])->find();$check($export!==null,'Missing owned export row');
            $curl=curl_init($api.'/api/app.aigc_music.export/download'.$suffix.'&export_id='.$export['id']);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['token: '.$token,'Host: private-media.example.test']]);$bytes=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
            $meta=json_decode($export['result_json'],true);$check($status===200&&hash('sha256',$bytes)===$meta['sha256'],'Owned export HTTP bytes differ');
            $denied=callHttp($api,'/api/app.aigc_music.export/download'.$suffix.'&export_id='.$export['id'],[],$other,'GET');
            $check($denied['code']!==1,'Cross-owner export download accepted');
        }
        $report['music_exports'][$op]=['http_submit_native_worker_detail_replay'=>true,'owned_download_checksum'=>in_array($op,['wav','midi','mp4'],true)];
    }
    $calls=array_map(fn($line)=>json_decode($line,true),file($f->dir.'/deep-calls.jsonl'));$kinds=array_count_values(array_column($calls,'kind'));
    $check(($kinds['tts']??0)===(getenv('AIGC_HTTP_DEEP_ONLY_MUSIC')==='1'?0:1)&&($kinds['text']??0)===(getenv('AIGC_HTTP_DEEP_ONLY_MUSIC')==='1'?0:2),'TTS or planning submitted twice');
    $report['supplier_call_counts']=$kinds;$report['music_operation_count']=8;$report['phase']='complete';
    $bills=think\facade\Db::name('ai_consumption_log')->select()->toArray();
    $check(count($bills)===count(file($f->dir.'/submissions.jsonl'))+($kinds['text']??0)+8,'Accepted supplier calls do not match local bills');
    $check(count(array_filter($bills,fn($bill)=>$bill['billing_status']==='settled'))===count($bills),'An accepted stage remains unsettled');
    $userCost=array_sum(array_column($bills,'actual_user_price'));$tenantCost=array_sum(array_column($bills,'actual_tenant_cost'));
    $check(abs(100-(float)think\facade\Db::name('user')->where('id',$u)->value('user_money')-$userCost)<0.000001,'Fake user ledger mismatch');
    $check(abs(100-(float)think\facade\Db::name('tenant')->where('id',$t)->value('point_balance')-$tenantCost)<0.000001,'Fake tenant ledger mismatch');
    $report['settlement_totals']=['consumptions'=>count($bills),'settled'=>count($bills),'actual_user'=>round($userCost,6),'actual_tenant'=>round($tenantCost,6),'protocols'=>array_count_values(array_column($bills,'protocol'))];
}
