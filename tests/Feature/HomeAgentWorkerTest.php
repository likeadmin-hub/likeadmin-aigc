<?php
// Replace the creative service before autoload: no provider or actual business submit is possible.
namespace app\common\service\app\aigc_image {
 final class AigcImageService {
  public static int $submits=0;public static int $queries=0;public static bool $unknown=false;
  public static function config(int $tenant):array{return ['status'=>1,'option_config'=>[]];}
  public static function generate(int $t,int $u,array $p):array{self::$submits++;if(self::$unknown)throw new \RuntimeException('Unknown submit result');return ['task_id'=>990001,'status'=>'running','results'=>[]];}
  public static function taskDetail(int $t,int $id,int $u):array{self::$queries++;return ['task_id'=>$id,'status'=>'success','results'=>[]];}
 }
}
namespace {
 require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
 require dirname(__DIR__).'/Support/HomeAgentFixture.php';
 use think\facade\Db;
 use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentExecutionService as E,AgentCapabilityCatalog as C};
 use app\common\service\app\aigc_image\AigcImageService as Fake;
 function verify($ok,$message){if(!$ok)throw new RuntimeException($message);}
 $db=config('database.connections.mysql');verify(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
 verify(S::table('run')->whereIn('status',['queued','running','waiting_tasks'])->count()===0,'Do not run fixtures alongside user jobs');
 $request=request();$request->tenantId=1;$request->userId=1;$request->userInfo=['user_id'=>1,'tenant_id'=>1];
 Db::startTrans();try{
  S::preferences(1,1,HomeAgentFixture::preferences());
  $fixture=static function(){
   $view=E::submit(1,1,['request_id'=>'worker_test_'.bin2hex(random_bytes(12)),'content'=>'离线 Worker 验收']);$run=S::run(1,1,(int)$view['id']);$prepared=C::prepare($run,'aigc_image',['prompt'=>'offline worker fixture']);
   S::table('step')->insert(['tenant_id'=>1,'user_id'=>1,'run_id'=>$run['id'],'step_key'=>hash('sha256',(string)$run['id']),'title'=>'fixture','tool_code'=>'aigc_image','prepared'=>S::encode($prepared),'result'=>'','create_time'=>time(),'update_time'=>time()]);
   S::table('run')->where('id',$run['id'])->update(['phase'=>'tools','status'=>'queued']);return (int)$run['id'];
  };
  $id=$fixture();verify(E::tick()===1,'worker did not claim fixture');verify(E::detail(1,1,$id)['status']==='waiting_tasks'&&Fake::$submits===1,'native submit not mapped');
  E::stop(1,1,$id);S::table('run')->where('id',$id)->update(['next_run_time'=>0]);E::tick();verify(E::detail(1,1,$id)['status']==='canceled'&&Fake::$queries===1&&Fake::$submits===1,'stop lost submitted job or duplicated submit');
  $id=$fixture();Fake::$unknown=true;E::tick();verify(E::detail(1,1,$id)['status']==='needs_review','unknown submission was not quarantined');verify(E::tick()===0&&Fake::$submits===2,'unknown submission replayed');
  echo "Home Agent worker recovery passed; creative service mocked\n";
 }finally{Db::rollback();}
}
