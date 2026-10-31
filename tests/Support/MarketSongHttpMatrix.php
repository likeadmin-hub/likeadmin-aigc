<?php
/** Original song -> owned result -> exports/persona/upload reference -> new music. Private loopback only. */
function runMarketSongHttpMatrix($f,$api,$suffix,$token,$supplier,array &$report): void
{
    $db=think\facade\Db::class;$t=$f->tenant;$u=$f->user;$file=dirname(__DIR__).'/market-generation-http.php';putenv('AIGC_HTTP_FIXTURE_DIR='.$f->dir);
    $check=fn($ok,$why)=>MarketWorkerFixture::check((bool)$ok,$why);
    $post=fn($route,$p)=>dataHttp($api,'/api/app.aigc_music.'.$route.$suffix,$p,$token);
    $get=fn($id)=>dataHttp($api,'/api/app.aigc_music.task/detail'.$suffix.'&id='.$id,[],$token,'GET');
    $product=function($code,$locked=[],$metadata=[])use($f){
        $p=app\common\model\power\PowerMarketProduct::create(['product_code'=>$f->prefix.'song_'.$code,'name'=>'Private song '.$code,'resource_type'=>'app_api','model_type'=>'audio','upstream_app_code'=>'music_generation','upstream_api_code'=>$code,'source_code'=>'isolated-fixture','upstream_resource_key'=>$f->prefix.'song_'.$code,'source_payload'=>['market_metadata'=>$metadata],'status'=>1]);
        $s=app\common\model\power\PowerMarketSku::create(['product_id'=>$p['id'],'sku_key'=>$f->prefix.'song_'.$code,'title'=>'Private song operation','locked_params'=>$locked,'usage_unit'=>'per_call','usage_unit_size'=>1,'sale_points'=>1,'upstream_price'=>1,'sale_status'=>1,'status'=>1]);return [(int)$p['id'],(int)$s['id']];
    };
    $properties=[];foreach(['prompt','lyric','style','vocal_gender','language','audio_id','persona_id']as$key)$properties[$key]=['type'=>'string'];foreach(['custom','instrumental']as$key)$properties[$key]=['type'=>'boolean'];$properties['duration']=['type'=>'integer','enum'=>[5]];$properties['audio_urls']=['type'=>'array','items'=>['type'=>'string'],'maxItems'=>1];
    [$p,$sku]=$product('create',['type'=>'generate','duration'=>5],['params_schema'=>['properties'=>$properties],'capabilities'=>['allow_reference_audio'=>true]]);$product('query');
    foreach(['wav','midi','mp4','persona','upload_audio','voice_clone']as$code)$product($code);
    file_put_contents($f->dir.'/synthetic.midi',hex2bin('4d546864000000060000000100604d54726b0000000c00903c4060803c4000ff2f00'));
    $base=['market_product_id'=>$p,'market_sku_id'=>$sku,'channel'=>'market_music_'.$sku,'quality'=>(string)$sku,'duration'=>5,'prompt'=>'Synthetic colored blocks in a gentle instrumental rhythm.','custom'=>false,'instrumental'=>true,'title'=>'Synthetic original song'];
    $generated=[];
    $generate=function(array $input,string $key,bool $freeze=false)use($post,$get,$f,$file,$db,$check,$sku,&$generated){
        $q=$post('generate/estimate',$input);$check((float)$q['user_charge_points']===1.,'Original song quote differs');$input['idempotency_key']=$key;
        $r=$post('generate/index',$input);$id=(int)$r['task_id'];$cid=(int)$r['consumption_id'];$same=$post('generate/index',$input);$check($same['task_id']===$id&&$same['generation_task']['task_ref']===$r['generation_task']['task_ref'],'Original song duplicate changed identity');
        if($freeze)$db::name('power_market_sku')->where('id',$sku)->update(['sale_status'=>0,'sale_points'=>99]);
        $f->start($file,'aigc_music');
        MarketWorkerFixture::wait(function()use($db,$id){$r=$db::name('aigc_music_task')->where('id',$id)->find();if(in_array($r['status'],['failed','canceled'],true))throw new RuntimeException('Original song failed: '.$r['error']);return $r['status']==='success';},'Original song did not finish without detail polling',40);
        MarketWorkerFixture::wait(fn()=>$db::name('ai_task_job')->where('status','<>','success')->count()===0,'Original song result jobs not drained',20);$f->stop();
        if($freeze)$db::name('power_market_sku')->where('id',$sku)->update(['sale_status'=>1,'sale_points'=>1]);
        $done=$get($id);$view=$done['generation_task'];$check($view['status']==='success'&&count($view['results'])===2&&count($done['results'])===2,'Original song lost one of its two outputs');
        foreach($done['results']as$row){$raw=is_array($row['result_json'])?$row['result_json']:json_decode($row['result_json'],true);$check(!empty($raw['audio_id']??$raw['id']??''),'Generated song lost supplier audio identity');$check(hash_file('sha256',$f->dir.'/public/'.$row['audio_uri'])===hash_file('sha256',$f->dir.'/synthetic.wav'),'Generated song bytes differ');}
        $bill=$db::name('ai_consumption_log')->where('id',$cid)->find();$check($bill['billing_status']==='settled'&&(float)$bill['actual_user_price']===1.&&$db::name('aigc_music_billing')->where('task_id',$id)->count()===1,'Two song outputs billed twice');
        $generated[]=$id;return $done;
    };
    $report['phase']='original_song';$first=$generate($base,'song-original-request',true);$source=(int)$first['results'][0]['id'];
    $report['original_generation']=['normal_login_quote_submit_same_key_background_two_songs'=>true,'two_native_and_canonical_audio_checksums'=>true,'one_settlement_per_generation'=>true,'accepted_price_and_shelf_freeze'=>true];
    $report['phase']='song_exports';
    foreach(['wav','midi','mp4']as$op){$r=$post('export/'.$op,['result_id'=>$source]);$id=(int)$r['task_id'];$f->start($file,'aigc_music');MarketWorkerFixture::wait(fn()=>$db::name('aigc_music_task')->where('id',$id)->value('status')==='success','Original song export '.$op.' incomplete',30);MarketWorkerFixture::wait(fn()=>$db::name('ai_task_job')->where('status','<>','success')->count()===0,'Original song export jobs pending',15);$f->stop();
        $export=$db::name('aigc_music_export')->where(['result_id'=>$source,'export_type'=>$op])->order('id','desc')->find();$check($export!==null,'Song export lacks native file');$meta=json_decode($export['result_json'],true);
        $curl=curl_init($api.'/api/app.aigc_music.export/download'.$suffix.'&export_id='.$export['id']);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['token: '.$token,'Host: private-media.example.test']]);$bytes=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$check($status===200&&hash('sha256',$bytes)===$meta['sha256'],'Original song export download checksum differs');$report['song_exports'][$op]=['actual_generated_source'=>true,'authenticated_download_checksum'=>true];
    }
    $report['phase']='persona_new_song';$persona=$post('persona/upstream',['result_id'=>$source,'name'=>'Synthetic original singer','description'=>'Synthetic tone only']);$personaId=(int)$persona['persona_id'];$check($personaId>0,'Generated song persona missing');
    $custom=array_replace($base,['title'=>'Synthetic custom vocal','prompt'=>'Synthetic short song.','custom'=>true,'instrumental'=>false,'lyrics'=>"[Verse]\n彩色色块随节奏移动",'style'=>'ambient','vocal_gender'=>'f','language'=>'zh','persona_id'=>$personaId]);$second=$generate($custom,'song-persona-custom');
    $report['persona_chain']=['actual_generated_song_to_owned_persona_to_custom_vocal_generation'=>true];
    $report['phase']='owned_upload_reference';
    $curl=curl_init($api.'/api/app.aigc_music.asset/upload_audio'.$suffix);curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['token: '.$token,'Host: private-media.example.test'],CURLOPT_POSTFIELDS=>['file'=>new CURLFile($f->dir.'/synthetic.wav','audio/wav','synthetic-original-reference.wav'),'asset_type'=>'reference_audio'],CURLOPT_TIMEOUT=>20]);$r=json_decode(curl_exec($curl),true);curl_close($curl);$check(($r['code']??0)===1,'Normal music multipart upload failed: '.($r['msg']??'unknown'));$upload=$r['data'];$asset=(int)($upload['id']??$upload['asset_id']??0);$check($asset>0,'Music upload lacks owned asset identity');
    $upstream=$post('asset/upload_upstream',['asset_id'=>$asset]);$audio=(string)$upstream['reference_audio_id'];$check($audio!=='','Uploaded source not registered upstream');
    $reference=$generate(array_replace($base,['title'=>'Synthetic uploaded reference song','reference_asset_id'=>$asset,'audio_id'=>$audio]),'song-reference-upload');
    $clone=$post('voice_clone/create',['reference_asset_id'=>$asset,'name'=>'Synthetic uploaded voice','description'=>'Synthetic tone only','authorization_confirmed'=>1,'authorization_text'=>'Private synthetic fixture']);$check((int)$clone['voice_clone_id']>0,'Uploaded source clone not created');
    $blocked=callHttp($api,'/api/app.aigc_music.generate/index'.$suffix,array_replace($base,['voice_clone_id'=>(int)$clone['voice_clone_id'],'idempotency_key'=>'song-unsupported-clone']),$token);$check($blocked['code']!==1,'Unsupported voice clone generation enabled');
    $report['upload_chain']=['normal_multipart_owned_file_and_music_asset'=>true,'registered_audio_id_reused_in_original_generation'=>true,'clone_from_actual_upload_retains_pending_capability'=>true];
    $report['phase']='history_new_attempt';$history=$get((int)$second['id']);$selection=$history['provider_payload']['generation'];$retry=array_replace($base,$selection,['title'=>$history['title'],'prompt'=>$history['prompt'],'lyrics'=>$history['lyrics'],'persona_id'=>$personaId]);$last=$generate($retry,'song-explicit-history-new-attempt');$check($last['id']!==$second['id'],'History edit did not create explicit new task');
    $rows=$db::name('aigc_music_task')->whereIn('id',$generated)->order('id')->select()->toArray();foreach($generated as$id)$get($id);$check($rows===$db::name('aigc_music_task')->whereIn('id',$generated)->order('id')->select()->toArray(),'Song history reread changed terminal rows');
    $calls=array_map(fn($line)=>json_decode($line,true),file($f->dir.'/deep-calls.jsonl'));$creates=array_values(array_filter($calls,fn($c)=>$c['kind']==='song_create'));$check(count($creates)===4,'Original song chain submitted unexpected generation count');
    $check($creates[1]['input']['custom']===true&&$creates[1]['input']['instrumental']===false&&$creates[1]['input']['vocal_gender']==='f'&&$creates[1]['input']['language']==='zh'&&$creates[1]['input']['persona_id']===$persona['provider_persona_id'],'Custom/vocal/persona fields did not reach supplier');
    $check($creates[2]['input']['audio_id']===$audio&&!empty($creates[2]['input']['audio_urls']),'Owned uploaded reference not forwarded');
    $report['generation_submissions']=4;$report['history_chain']=['native_inputs_to_explicit_new_attempt'=>true,'stable_read_only_terminal_rows'=>true];$report['phase']='complete';
}
