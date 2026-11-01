<?php
/** Repeat admission only. No Agent worker, provider request or text reservation. */
function runMarketAgentAdmissionProbe($f,string $api,string $peer,string $suffix,string $token,array &$report): void
{
    $report['admission_probe']=['pairs'=>0,'failures'=>[]];
    for($i=0;$i<30;$i++) {
        $p=['session_id'=>0,'request_id'=>'agent-concurrent-probe-'.str_pad((string)$i,8,'0',STR_PAD_LEFT),'content'=>'Synthetic admission probe','preferences'=>['mode'=>'ask']];
        $multi=curl_multi_init();$handles=[];
        foreach([$api,$peer]as$base){$c=curl_init($base.'/api/app.aigc_llm.agent/submit'.$suffix);curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($p),CURLOPT_HTTPHEADER=>['Content-Type: application/json','token: '.$token],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);curl_multi_add_handle($multi,$c);$handles[]=$c;}
        do{curl_multi_exec($multi,$active);if($active)curl_multi_select($multi,.01);}while($active);
        $out=[];foreach($handles as$c){$out[]=json_decode((string)curl_multi_getcontent($c),true);curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);
        ++$report['admission_probe']['pairs'];
        if(($out[0]['code']??0)!==1||($out[1]['code']??0)!==1||($out[0]['data']['id']??0)!==($out[1]['data']['id']??0))$report['admission_probe']['failures'][]=['pair'=>$i,'responses'=>$out];
    }
    $report['admission_probe']['run_count']=(int)think\facade\Db::name('aigc_llm_agent_run')->count();
    MarketWorkerFixture::check($report['admission_probe']['failures']===[]&&$report['admission_probe']['run_count']===30,'Repeated same-key Agent admission did not return one durable run per pair');
}
