<?php
// Real wallet/database transactions; only outbound synthesis, download and storage are replaced.
namespace app\common\service\app\aigc_llm\agent {
    final class SpeechFixture {public static int $calls=0;public static string $mode='success';public static bool $storageFail=false;}
    function curl_init($url){return new \stdClass();}
    function curl_setopt_array($ch,$options){$ch->options=$options;return true;}
    function curl_exec($ch){
        SpeechFixture::$calls++;$body=SpeechFixture::$mode==='reject'?['code'=>0,'msg'=>'fixture rejection']:['code'=>1,'data'=>['result'=>['audio_url'=>'https://fixture.invalid/audio.mp3']]];
        ($ch->options[CURLOPT_WRITEFUNCTION])($ch,json_encode($body));return SpeechFixture::$mode!=='timeout';
    }
    function curl_getinfo($ch,$option){return SpeechFixture::$mode==='reject'?400:200;}
    function curl_errno($ch){return SpeechFixture::$mode==='timeout'?28:0;}
    function curl_close($ch){}
}
namespace app\common\service\open_platform {
    final class OpenSafeHttp {public static function get($url,$max,$timeout):array{return ['body'=>"ID3\x04\x00\x00\x00\x00\x00\x00".str_repeat("\xff\xfb\x90\x00".str_repeat("\0",413),5)];}}
}
namespace app\common\service\app\aigc_music {
    final class AigcMusicAssetService {
        public static function persistGeneratedAudio($url,$tenant,$user):array {
            if(\app\common\service\app\aigc_llm\agent\SpeechFixture::$storageFail)throw new \RuntimeException('fixture storage failure');
            return ['stored'=>true,'uri'=>'uploads/fixture/speech.mp3','file_size'=>2095,'duration'=>1,'storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>''];
        }
    }
}
namespace {
    require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
    set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
    use think\facade\Db;
    use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentExecutionService as E,AgentSpeechTool as T,SpeechFixture as F,AgentArtifactService};
    use app\common\model\ai\{AiConsumptionLog,AiAppTask};
    function check($ok,$message){if(!$ok)throw new \RuntimeException($message);}
    function balance(){return (float)Db::name('user')->where('id',1)->value('user_money');}
    function fixture(callable $fn){$before=balance();$files=glob(runtime_path().'home-agent-speech-receipts/*')?:[];Db::startTrans();try{F::$calls=0;F::$mode='success';F::$storageFail=false;$fn();}finally{Db::rollback();foreach(array_diff(glob(runtime_path().'home-agent-speech-receipts/*')?:[],$files) as $file)unlink($file);}check(balance()===$before,'Test altered wallet');}
    $db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
    check(S::table('run')->whereIn('status',['queued','running','waiting_tasks'])->count()===0,'Active Agent jobs present');
    request()->tenantId=1;request()->userId=1;request()->userInfo=['tenant_id'=>1,'user_id'=>1];
    $models=T::models(1);check(count($models['models'])>0,'A priced default speech SKU is required');
    $prefs=S::preferences(1,1);$prefs['models']['voice']=$models['defaults']['model'];$prefs['mode']='ask';
    function prepared(array $prefs):array {
        $view=E::submit(1,1,['session_id'=>0,'content'=>'测试配音','request_id'=>'speech-test-'.bin2hex(random_bytes(12)),'preferences'=>$prefs]);
        S::table('run')->where('id',$view['id'])->update(['status'=>'running','phase'=>'tools','lease_token'=>'fixture']);$run=S::run(1,1,$view['id']);
        $p=T::prepare($run,['text'=>'你好，世界。']);
        $p['step_id']=S::table('step')->insertGetId(['tenant_id'=>1,'user_id'=>1,'run_id'=>$view['id'],'step_key'=>bin2hex(random_bytes(16)),'title'=>'配音测试','tool_code'=>T::CODE,'prepared'=>S::encode($p),'result'=>'','status'=>'submitting','create_time'=>time(),'update_time'=>time()]);
        return [$run,$p];
    }
    fixture(function()use($prefs){
        [$run,$p]=prepared($prefs);$before=balance();$first=T::submit($p);check($first['status']==='success'&&count($first['results'])===1,'Speech did not finish');
        $cid=(int)AiConsumptionLog::where('app_task_id',$first['task_id'])->value('id');$paid=balance();check(abs($before-$paid-$p['quote']['user'])<0.0001,'Wrong character charge');
        T::submit($p);T::reconcile($cid,1);check(F::$calls===1&&balance()===$paid,'Duplicate submit/recovery charged twice');
        $step=S::table('step')->where('id',$p['step_id'])->find();AgentArtifactService::collect($run,$step,$first);AgentArtifactService::collect($run,$step,$first);
        check(S::table('artifact')->where('run_id',$run['id'])->count()===1,'Duplicate artifact');
        $assets=AgentArtifactService::lists(1,1,(int)$run['session_id']);check($assets[0]['kind']==='audio'&&$assets[0]['generation']['prompt']==='你好，世界。','Audio metadata did not use submitted text');
        $audit=(new \ReflectionMethod(\app\common\service\ai\MarketTaskReconciliationService::class,'issues'))->invoke(null,AiConsumptionLog::find($cid)->toArray());check($audit===[],'Settled speech audit failed');
        try{T::query(1,999999,$first['task_id']);throw new \LogicException('Other user got task');}catch(\InvalidArgumentException){}
        try{T::reconcile($cid,999999);throw new \LogicException('Other tenant got receipt');}catch(\InvalidArgumentException){}
    });
    fixture(function()use($prefs){[$run,$p]=prepared($prefs);$before=balance();F::$mode='reject';$r=T::submit($p);check($r['status']==='failed'&&balance()===$before,'Explicit rejection was not refunded');T::submit($p);check(F::$calls===1&&balance()===$before,'Rejected task was resubmitted');});
    fixture(function()use($prefs){[$run,$p]=prepared($prefs);$before=balance();F::$mode='timeout';$r=T::submit($p);$cid=(int)AiConsumptionLog::where('app_task_id',$r['task_id'])->value('id');T::reconcile($cid,1);check($r['status']==='needs_review'&&F::$calls===1&&abs($before-balance()-$p['quote']['user'])<0.0001,'Unknown receipt refunded or resubmitted');});
    fixture(function()use($prefs){[$run,$p]=prepared($prefs);F::$storageFail=true;$r=T::submit($p);check($r['status']==='needs_review','Storage failure presented as complete');$cid=(int)AiConsumptionLog::where('app_task_id',$r['task_id'])->value('id');F::$storageFail=false;T::reconcile($cid,1);check(T::query(1,1,$r['task_id'])['status']==='success'&&F::$calls===1,'Storage recovery regenerated speech');
        S::table('step')->where('id',$p['step_id'])->update(['status'=>'needs_review']);S::table('run')->where('id',$run['id'])->update(['status'=>'needs_review','lease_token'=>'','lease_until'=>0]);T::scan();check(S::run(1,1,$run['id'])['status']==='waiting_tasks','Settled task failed to wake original run');
    });
    fixture(function()use($prefs){[$run,$p]=prepared($prefs);foreach([['text'=>' '],['text'=>str_repeat('字',501)],['text'=>'你好','reference_id'=>'other-voice']] as $bad){try{T::prepare($run,$bad);throw new \LogicException('Invalid speech input accepted');}catch(\InvalidArgumentException|\app\common\service\open_platform\OpenException){}}$run['preferences']=S::encode(['models'=>['voice'=>'speech_sku:0']]);try{T::prepare($run,['text'=>'你好']);throw new \LogicException('Unavailable selection fell back');}catch(\InvalidArgumentException){}});
    check(T::classify(['complete'=>true,'http_status'=>500,'body'=>'{"code":0}'])===null,'HTTP 500 must not refund');
    check(T::classify(['complete'=>true,'http_status'=>200,'body'=>'{"code":1,"data":{}}'])===null,'Missing output must not complete');
    echo "Speech passed: real rollback billing, frozen character quote, success/refund/review, duplicate recovery, storage recovery, asset metadata, tenant isolation, no model fallback\n";
}
