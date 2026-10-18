<?php
// Local rollback-only acceptance. Never dispatches a worker or provider generation.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
require dirname(__DIR__).'/Support/HomeAgentFixture.php';
use think\facade\Db;
use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentComposerSettings as Settings,AgentExecutionService as E,AgentCapabilityCatalog as C,AgentSubjectReferences as Subjects};
use app\common\service\app\aigc_llm\AigcLlmService;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function rejected(callable $call){try{$call();}catch(InvalidArgumentException|\app\common\service\open_platform\OpenException $e){return;}throw new RuntimeException('Invalid input accepted');}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
request()->tenantId=1;request()->userId=1;request()->userInfo=['tenant_id'=>1,'user_id'=>1];
$before=Db::name('user')->where('id',1)->value('user_money');
Db::startTrans();try{
 $prefs=HomeAgentFixture::preferences();$configured=Settings::textModel(1);
 $prefs['models']['text']='client-tampered';
 $prefs['generation']=['mode'=>'custom','image'=>['quantity'=>1],'video'=>[]];
 $params=['content'=>'创作配置离线回归','request_id'=>'composer-'.bin2hex(random_bytes(12)),'preferences'=>$prefs];
 $view=E::submit(1,1,$params);$run=S::run(1,1,$view['id']);$stored=S::json($run['preferences']);
 check($stored['models']['text']===$configured,'Client overrode backend text model');
 check(S::requiresConfirmation($stored,100),'Custom parameters bypassed confirmation');
 check(Settings::parameters($stored,'aigc_image')===['quantity'=>1],'Custom image preference lost');
 check(Settings::parameters($stored,'aigc_fitting')===[],'Image preference leaked into fitting');
 check(Settings::parameters(['generation'=>['mode'=>'auto','image'=>['quantity'=>4]]],'aigc_image')===[],'Auto used stale custom settings');
 foreach([['mode'=>'bad'],['mode'=>'custom','image'=>['quantity'=>0]],['mode'=>'custom','video'=>['duration'=>61]],['mode'=>'custom','image'=>['quality'=>[]]]] as $bad)rejected(fn()=>Settings::generation($bad));
 $quote=C::prepare($run,'aigc_image',['prompt'=>'蓝色杯子','quantity'=>4]);check((int)$quote['params']['quantity']===1,'Planner overrode user quantity before quote');
 check(($quote['inputs']['quantity']??0)===1,'Approval inputs disagree with quote');
 $videoOptions=C::models(1)['video'];$video=null;foreach($videoOptions['channels']??[] as $candidate)if(in_array('text_to_video',$candidate['generation_modes']??[],true)){$video=$candidate;break;}
 check($video!==null,'Video fixture missing');$resolution=(string)($video['qualities'][0]['resolution']??$video['default_resolution']??'');$duration=(int)($video['duration_options'][0]??$video['qualities'][0]['duration']??5);
 $videoPrefs=$stored;$videoPrefs['models']['video']=(string)($video['code']??$video['id']);$videoPrefs['generation']['video']=['duration'=>$duration];if($resolution!=='')$videoPrefs['generation']['video']['resolution']=$resolution;
 $videoRun=$run;$videoRun['preferences']=S::encode($videoPrefs);$videoQuote=C::prepare($videoRun,'aigc_video',['prompt'=>'缓慢移动的风景镜头','duration'=>60]);
 check((int)$videoQuote['params']['duration']===$duration,'User video duration not applied before pricing');
 $beforeConfig=AigcLlmService::config(1);AigcLlmService::saveConfig(1,['config_json'=>['agent_text_model'=>$configured]]);check(AigcLlmService::config(1)['config_json']['agent_text_model']===$configured,'Admin model setting not persisted');
 check(AigcLlmService::config(1)['config_json']['system_prompt']===$beforeConfig['config_json']['system_prompt'],'Admin model setting reset other configuration');
 rejected(fn()=>AigcLlmService::saveConfig(1,['config_json'=>['agent_text_model'=>'injected-invalid-model']]));
 $config=Db::name('aigc_llm_config')->where('tenant_id',1)->find();$json=S::json($config['config_json']);$json['agent_text_model']='unavailable-backend-model';Db::name('aigc_llm_config')->where('tenant_id',1)->update(['config_json'=>S::encode($json)]);
 check(E::submit(1,1,$params)['id']===$view['id'],'Retry rerouted or duplicated run after config change');
 rejected(fn()=>E::submit(1,1,array_replace($params,['request_id'=>'unavailable-'.bin2hex(random_bytes(12))])));
 Db::name('aigc_llm_config')->where('tenant_id',1)->update(['config_json'=>$config['config_json']]);
 rejected(fn()=>Subjects::resolve(1,1,PHP_INT_MAX));
 $id=Db::name('aigc_short_drama_subject')->insertGetId(['tenant_id'=>1,'user_id'=>0,'source'=>'public','name'=>'Agent reference fixture','image'=>'resource/image/common/menu_generator.png','description'=>'蓝色主体','status'=>1,'delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
 $listed=Subjects::lists(1,1,['scope'=>'public','keyword'=>'Agent reference fixture']);check(count($listed['lists'])===1,'Subject picker failed to list authorized subject');
 $subject=Subjects::resolve(1,1,$id);check($subject['mime']==='image/png'&&$subject['name']==='@Agent reference fixture','Subject not resolved as actual visual material');
 Db::name('aigc_short_drama_subject')->where('id',$id)->update(['tenant_id'=>2]);rejected(fn()=>Subjects::resolve(1,1,$id));
 Db::name('aigc_short_drama_subject')->where('id',$id)->update(['tenant_id'=>1,'user_id'=>2,'source'=>'user']);rejected(fn()=>Subjects::resolve(1,1,$id));
 Db::name('aigc_short_drama_subject')->where('id',$id)->update(['user_id'=>1,'status'=>0]);rejected(fn()=>Subjects::resolve(1,1,$id));
 Db::name('tenant_app')->where(['tenant_id'=>1,'app_code'=>'aigc_short_drama'])->update(['shelf_status'=>'off']);
 rejected(fn()=>Subjects::lists(1,1,['scope'=>'public']));rejected(fn()=>Subjects::resolve(1,1,$id));
 check(Db::name('user')->where('id',1)->value('user_money')===$before,'Validation changed wallet');
 echo "Agent composer passed: backend text routing, unavailable/replay safety, generation preference boundaries, native image quote, confirmation and subject tenant/user/status isolation; rolled back\n";
}finally{Db::rollback();}
