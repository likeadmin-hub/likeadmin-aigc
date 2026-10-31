<?php
/** Two independent authenticated HTTP processes share only the owned fixture SQL/cache/media. */
function marketConcurrentDataHttp(string $api,string $path,array $params,string $token): array
{
    global $fixture,$urls,$report;
    $route=explode('?',ltrim(substr($path,strpos($path,'app.')),'/'))[0];
    $native='';foreach(['request_key','idempotency_key','batch_key','submission_key']as$field)if(!empty($params[$field])){$native=(string)$params[$field];break;}
    $key=$native!==''?'native_'.hash('sha256',$route.'|'.$native):'fixture_'.bin2hex(random_bytes(16));
    $headers=['Content-Type: application/json','token: '.$token,'X-Generation-Request-Key: '.$key];
    if(getenv('AIGC_HTTP_DEEP_MATRIX')==='1')$headers[]='Host: private-media.example.test';
    $make=function($base,$timeout=20000)use($path,$params,$headers){$c=curl_init($base.$path);curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($params),CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT_MS=>$timeout]);return $c;};
    $lost=empty($report['concurrent_admission']['lost_response_replayed']);
    $multi=curl_multi_init();$a=$make($api,$lost?150:20000);curl_multi_add_handle($multi,$a);
    $active=0;$overlap=false;$until=microtime(true)+5;
    do {
        curl_multi_exec($multi,$active);
        $row=\think\facade\Db::name('ai_generation_submission')->where(['tenant_id'=>$fixture->tenant,'user_id'=>$fixture->user,'operation'=>strtolower($route),'key_hash'=>hash('sha256',$key)])->find();
        if($row){$overlap=$active>0&&$row['status']==='submitting';break;}
        if($active)curl_multi_select($multi,0.005);
    }while($active&&microtime(true)<$until);
    $b=$make($urls['api_peer']);curl_multi_add_handle($multi,$b);
    do{curl_multi_exec($multi,$active);if($active)curl_multi_select($multi,0.01);}while($active);
    $raw=[curl_multi_getcontent($a),curl_multi_getcontent($b)];$errors=[];
    while($info=curl_multi_info_read($multi))$errors[]=$info['result'];
    foreach([$a,$b]as$c){curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);
    $responses=[];
    foreach($raw as$i=>$body){$r=json_decode((string)$body,true);
        if(!is_array($r)||($r['generation_submission']['state']??'')==='submitting')$r=callHttp($api,$path,$params,$token,'POST',['X-Generation-Request-Key: '.$key]);
        $responses[]=$r;
    }
    MarketWorkerFixture::check($responses[0]['data']===$responses[1]['data']&&$responses[0]['code']===$responses[1]['code'],'Concurrent admission returned different task receipts: '.$route.' '.json_encode($responses));
    $counts=static function()use($fixture){$r=[];foreach(['ai_app_task','ai_consumption_log','ai_task_result_asset']as$table)$r[$table]=\think\facade\Db::name($table)->count();foreach(['submissions.jsonl','deep-calls.jsonl']as$file)$r[$file]=is_file($fixture->dir.'/'.$file)?count(file($fixture->dir.'/'.$file)):0;return $r;};
    $before=$counts();
    $replayed=callHttp($urls['api_peer'],$path,$params,$token,'POST',['X-Generation-Request-Key: '.$key]);
    MarketWorkerFixture::check($replayed['data']===$responses[0]['data']&&($replayed['generation_submission']['replayed']??false),'Same-key HTTP retry did not return the persisted receipt: '.$route);
    $conflict=callHttp($urls['api_peer'],$path,$params+['__concurrent_conflict_probe'=>true],$token,'POST',['X-Generation-Request-Key: '.$key]);
    MarketWorkerFixture::check(($conflict['code']??1)!==1&&$counts()===$before,'Changed same-key request dispatched or changed generation facts: '.$route);
    $report['concurrent_admission']['operations'][$route]['attempts']=($report['concurrent_admission']['operations'][$route]['attempts']??0)+1;
    $report['concurrent_admission']['operations'][$route]['overlap_observed']=($report['concurrent_admission']['operations'][$route]['overlap_observed']??false)||$overlap;
    $report['concurrent_admission']['operations'][$route]['same_receipt_replay_conflict_no_extra_tasks_or_supplier_calls']=true;
    if($lost){MarketWorkerFixture::check(in_array(CURLE_OPERATION_TIMEDOUT,$errors,true),'Fixture did not lose an in-flight HTTP response');$report['concurrent_admission']['lost_response_replayed']=true;}
    if(($responses[0]['code']??0)!==1)throw new RuntimeException('Concurrent HTTP '.$path.': '.($responses[0]['msg']??'unknown'));
    return $responses[0]['data'];
}
