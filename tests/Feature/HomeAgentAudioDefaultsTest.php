<?php
// Local rollback-only routing/quote checks. No generation or wallet mutation.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
require dirname(__DIR__).'/Support/HomeAgentFixture.php';
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentExecutionService as E,AgentCapabilityCatalog as C,AgentSpeechTool as T};
use app\common\service\app\aigc_music\AigcMusicService;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
request()->tenantId=1;request()->userId=1;request()->userInfo=['tenant_id'=>1,'user_id'=>1];
$before=Db::name('user')->where('id',1)->value('user_money');
Db::startTrans();try{
 $prefs=HomeAgentFixture::preferences();$prefs['mode']='ask';$prefs['models']['music']='retired-music';$prefs['models']['voice']='speech_sku:0';
 $params=['content'=>'音频默认路由离线验收','request_id'=>'audio-default-'.bin2hex(random_bytes(12)),'preferences'=>$prefs+['_audio_defaults'=>['music'=>'injected']]];
 $view=E::submit(1,1,$params);$run=S::run(1,1,$view['id']);$stored=S::json($run['preferences']);$defaults=$stored['_audio_defaults'];
 check(!isset($stored['models']['music'],$stored['models']['voice']),'Hidden preference survived new submission');
 check($stored['models']['text']===$prefs['models']['text'],'Text preference changed');
 check($defaults['music']===(AigcMusicService::config(1)['option_config']['defaults']['channel']??''),'Music ignored backend default');
 check(C::describe($run,'aigc_music')['selected_model']['selection']===$defaults['music'],'Music description disagrees with execution');
 $music=C::prepare($run,'aigc_music',['prompt'=>'轻柔钢琴纯音乐','duration'=>5]);check($music['params']['channel']===$defaults['music'],'Music did not use default channel');
 check($defaults['speech']['short']!==''&&$defaults['speech']['long']!=='','Need enabled local sync and async speech fixtures');
 $short=T::prepare($run,['text'=>str_repeat('字',500)]);$long=T::prepare($run,['text'=>str_repeat('字',501)]);
 check($short['params']['execution_mode']==='sync'&&$short['params']['characters']===500,'Short routing wrong');
 check($long['params']['execution_mode']==='async'&&$long['params']['characters']===501,'Long routing wrong');
 check($long['speech_payload']['text']===str_repeat('字',501),'Body was rewritten or split');
 check(T::prepare($run,['text'=>str_repeat('字',10000)])['params']['characters']===10000,'Maximum body truncated');
 try{T::prepare($run,['text'=>str_repeat('字',10001)]);throw new LogicException('Oversize accepted');}catch(InvalidArgumentException|\app\common\service\open_platform\OpenException){}
 check(S::requiresConfirmation($stored,(float)$long['quote']['user']),'Ask confirmation bypassed');
 $auto=$stored;$auto['mode']='auto';$auto['budget']=max(1,(float)$long['quote']['user']);check(!S::requiresConfirmation($auto,(float)$long['quote']['user']),'Automatic budget no longer works');
 $legacy=$run;$legacy['preferences']=S::encode(['models'=>['voice'=>$defaults['speech']['long']]]);
 check(T::prepare($legacy,['text'=>'旧任务'])['params']['execution_mode']==='async','Legacy selected service changed');
 Db::name('power_market_sku')->where('id',$short['quote']['snapshot']['sku_id'])->update(['sale_status'=>0]);
 check(E::submit(1,1,$params)['id']===$view['id'],'Replay created another run');
 check(S::json(S::run(1,1,$view['id'])['preferences'])['_audio_defaults']===$defaults,'Replay rerouted original task');
 try{T::prepare($run,['text'=>'服务已下架']);throw new LogicException('Frozen service silently replaced');}catch(InvalidArgumentException){}
 check(Db::name('user')->where('id',1)->value('user_money')===$before,'Quote changed wallet');
 echo "Audio defaults passed: hidden legacy choices ignored for new runs, server defaults, 500/501/10000 boundaries, exact body, confirmation/budget, legacy snapshot, unavailable/replay safety; rolled back\n";
}finally{Db::rollback();}
