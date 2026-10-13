<?php
namespace { require dirname(__DIR__,2).'/vendor/autoload.php'; }
// Stub the transport only. Exercise the actual native service, task, Agent worker and billing.
namespace app\common\service\app\aigc_music {
 class XhadminAigcMusicProvider extends MockAigcMusicProvider {
  public static int $submits=0,$queries=0;
  public function generate(AigcMusicGenerateRequest $request): AigcMusicGenerateResult {self::$submits++;return new AigcMusicGenerateResult(true,[], '', 'agent-music-fixture');}
  public function fetchResult(string $id,AigcMusicGenerateRequest $request): AigcMusicGenerateResult {self::$queries++;return new AigcMusicGenerateResult(true,[['title'=>'Music fixture','audio_uri'=>'uploads/agent-music-fixture.mp3','mime_type'=>'audio/mpeg','duration'=>30,'storage_scope'=>'platform','storage_engine'=>'local','storage_domain'=>'']], '',$id);}
 }
}
namespace {
 (new think\App())->initialize();
 use think\facade\Db;
 use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentExecutionService as E,AgentCapabilityCatalog as C,AgentArtifactService as A};
 use app\common\service\app\aigc_music\{AigcMusicService as Music,XhadminAigcMusicProvider as Fake};
 function check($ok,$message){if(!$ok)throw new \RuntimeException($message);}
 $db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
 check(S::table('run')->whereIn('status',['queued','running','waiting_tasks'])->count()===0,'Do not run alongside active jobs');
 $request=request();$request->tenantId=1;$request->userId=1;$request->userInfo=['tenant_id'=>1,'user_id'=>1];
 $balance=fn()=>(float)Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money');$before=$balance();
 Db::startTrans();try{
  $models=C::models(1);S::preferences(1,1,['mode'=>'ask','models'=>['text'=>$models['text']['models'][0]['code'],'music'=>'music_generation']]);
  $view=E::submit(1,1,['request_id'=>'music_test_'.bin2hex(random_bytes(12)),'content'=>'offline music fixture']);$run=S::run(1,1,(int)$view['id']);
  $prepared=C::prepare($run,'aigc_music',['prompt'=>'offline music fixture','instrumental'=>true,'duration'=>30]);
  S::table('step')->insert(['tenant_id'=>1,'user_id'=>1,'run_id'=>$run['id'],'step_key'=>hash('sha256',(string)$run['id']),'title'=>'Music fixture','tool_code'=>'aigc_music','prepared'=>S::encode($prepared),'result'=>'','create_time'=>time(),'update_time'=>time()]);
  S::table('run')->where('id',$run['id'])->update(['phase'=>'tools','status'=>'queued']);
  E::tick();$view=E::detail(1,1,(int)$run['id']);check($view['status']==='waiting_tasks'&&Fake::$submits===1,'Music submission failed');
  $task=(int)$view['steps'][0]['task_id'];check($task>0,'No native task mapping');
  S::table('run')->where('id',$run['id'])->update(['next_run_time'=>0]);E::tick();
  $view=E::detail(1,1,(int)$run['id']);check($view['status']==='success','Music completion failed');
  $items=A::lists(1,1,(int)$run['session_id']);check(count($items)===1&&$items[0]['kind']==='audio'&&$items[0]['generation']['prompt']==='offline music fixture','Audio artifact or submitted prompt missing');
  check(abs($before-$balance()-(float)$prepared['quote']['user'])<0.001,'Music price not settled exactly once');$settled=$balance();
  Music::taskDetail(1,$task,1);Music::taskDetail(1,$task,1);check($balance()===$settled&&Fake::$submits===1,'Polling charged or submitted twice');
  A::favorite(1,1,(int)$items[0]['id'],true);check(A::lists(1,1,(int)$run['session_id'])[0]['favorite']===1,'Music favorite failed');
  echo "Home Agent music workflow passed: real task/worker, mocked transport, audio artifact, exact single billing, repeated poll and favorite; rolled back\n";
 }finally{Db::rollback();}
 check($balance()===$before,'Rollback did not restore balance');
}
