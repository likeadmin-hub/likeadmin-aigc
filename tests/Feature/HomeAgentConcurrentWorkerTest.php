<?php
// Local, committed-and-cleaned fixtures are necessary for independent process visibility.
// Native generation is replaced before autoload, so no provider or wallet can be called.
namespace app\common\service\app\aigc_image {
 final class AigcImageService {
  public static string $audit='';public static bool $crash=false;
  public static function config(int $tenant):array{return ['status'=>1,'option_config'=>[]];}
  public static function generate(int $tenant,int $user,array $params):array{
   file_put_contents(self::$audit,"submit\n",FILE_APPEND|LOCK_EX);
   if(self::$crash)exit(77);
   usleep(200000);return ['task_id'=>990001,'status'=>'running','results'=>[]];
  }
  public static function taskDetail(int $tenant,int $id,int $user):array{file_put_contents(self::$audit,"query\n",FILE_APPEND|LOCK_EX);return ['task_id'=>$id,'status'=>'success','results'=>[]];}
 }
}
namespace {
 require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
 require dirname(__DIR__).'/Support/HomeAgentFixture.php';
 set_exception_handler(static function(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);});
 use think\facade\Db;
 use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentExecutionService as E,AgentCapabilityCatalog as C};
 use app\common\service\app\aigc_image\AigcImageService as Fake;
 function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
 $db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local fixture only');
 if(($argv[1]??'')==='child'){
  $dir=$argv[2]??'';check(str_starts_with($dir,sys_get_temp_dir().'/home-agent-concurrency-')&&is_file($dir.'/fixture.json'),'Missing test ownership marker');
  $fixture=json_decode(file_get_contents($dir.'/fixture.json'),true);$ids=$fixture['ids'];
  check(S::table('run')->whereIn('status',['queued','running','waiting_tasks'])->whereNotIn('id',$ids)->count()===0,'Unrelated active task detected');
  Fake::$audit=$dir.'/calls';Fake::$crash=($argv[3]??'')==='crash';
  for($i=0;$i<12;$i++){E::tick();usleep(50000);}exit(0);
 }
 check(S::table('run')->whereIn('status',['queued','running','waiting_tasks'])->count()===0,'Stop active jobs before testing');
 check(\app\common\service\app\aigc_llm\agent\AgentWorkerHealth::processes()===[],'Stop real homepage worker before concurrent test');
 $dir=sys_get_temp_dir().'/home-agent-concurrency-'.bin2hex(random_bytes(8));mkdir($dir,0700);$ids=[];$sessions=[];$children=[];
 $request=request();$request->tenantId=1;$request->userId=1;$request->userInfo=['user_id'=>1,'tenant_id'=>1];
 $wallet=(float)Db::name('user')->where('id',1)->value('user_money');$consumptions=Db::name('ai_consumption_log')->where(['tenant_id'=>1,'user_id'=>1,'app_code'=>'aigc_llm'])->count();
 $launch=function(string $mode='')use($dir,&$children){
  $log=fopen($dir.'/child-'.count($children).'.log','w');
  $process=proc_open([PHP_BINARY,__FILE__,'child',$dir,$mode],[0=>['file','/dev/null','r'],1=>$log,2=>$log],$pipes);
  check(is_resource($process),'Child start failed');$children[]=[$process,$log];
 };
 $wait=function()use(&$children){$codes=[];foreach($children as [$process,$log]){$codes[]=proc_close($process);fclose($log);}$children=[];return $codes;};
 $create=function()use(&$ids,&$sessions,$dir){
  $now=time();$session=Db::name('aigc_llm_session')->insertGetId(['tenant_id'=>1,'user_id'=>1,'title'=>'离线并发夹具','model_code'=>'fixture','status'=>'idle','last_message_at'=>$now,'message_count'=>0,'create_time'=>$now,'update_time'=>$now,'delete_time'=>0]);$sessions[]=$session;
  $id=S::table('run')->insertGetId(['tenant_id'=>1,'user_id'=>1,'session_id'=>$session,'message_id'=>0,'request_id'=>'concurrency-'.bin2hex(random_bytes(12)),'payload_hash'=>str_repeat('d',64),'content'=>'离线并发验收','preferences'=>S::encode(HomeAgentFixture::preferences()),'attachments'=>'[]','status'=>'awaiting_confirmation','phase'=>'tools','reply'=>'','create_time'=>$now,'update_time'=>$now]);
  $ids[]=$id;$run=S::run(1,1,$id);$prepared=C::prepare($run,'aigc_image',['prompt'=>'offline concurrency fixture']);
  S::table('step')->insert(['tenant_id'=>1,'user_id'=>1,'run_id'=>$id,'step_key'=>hash('sha256',(string)$id),'title'=>'fixture','tool_code'=>'aigc_image','prepared'=>S::encode($prepared),'result'=>'','create_time'=>$now,'update_time'=>$now]);
  S::table('run')->where('id',$id)->update(['status'=>'queued']);file_put_contents($dir.'/fixture.json',json_encode(['ids'=>$ids]));return $id;
 };
 try{
  for($i=0;$i<6;$i++)$create();
  for($i=0;$i<3;$i++)$launch();check($wait()===[0,0,0],'Concurrent workers failed; inspect '.$dir);
  check(is_file($dir.'/calls'),'No native submit: '.json_encode(S::table('run')->whereIn('id',$ids)->field('id,status,error')->select(),JSON_UNESCAPED_UNICODE));
  $lines=file($dir.'/calls',FILE_IGNORE_NEW_LINES);check(count(array_filter($lines,fn($s)=>$s==='submit'))===6,'Concurrent submit duplicated or lost');
  check(S::table('run')->whereIn('id',$ids)->where('status','waiting_tasks')->count()===6,'Submitted task state lost');
  // Simulate process loss after durable task binding. Recovery may query, never submit.
  S::table('run')->whereIn('id',$ids)->update(['status'=>'running','lease_token'=>'lost-fixture','lease_until'=>time()-1]);
  for($i=0;$i<3;$i++)$launch();check($wait()===[0,0,0],'Restart recovery failed');
  check(S::table('run')->whereIn('id',$ids)->where('status','success')->count()===6,'Restart did not recover all results');
  $lines=file($dir.'/calls',FILE_IGNORE_NEW_LINES);check(count(array_filter($lines,fn($s)=>$s==='submit'))===6&&count(array_filter($lines,fn($s)=>$s==='query'))===6,'Recovery replayed generation or duplicate query ownership');
  // A real child exits immediately after submission begins, before receiving/binding a result.
  $crash=$create();$launch('crash');check($wait()===[77],'Crash fixture did not exit at submission boundary');
  S::table('run')->where('id',$crash)->update(['lease_until'=>time()-1]);
  for($i=0;$i<2;$i++)$launch();check($wait()===[0,0],'Unknown recovery failed');
  check(S::run(1,1,$crash)['status']==='needs_review','Unknown submit not quarantined');
  check(count(array_filter(file($dir.'/calls',FILE_IGNORE_NEW_LINES),fn($s)=>$s==='submit'))===7,'Unknown submit was replayed');
  check((float)Db::name('user')->where('id',1)->value('user_money')===$wallet&&Db::name('ai_consumption_log')->where(['tenant_id'=>1,'user_id'=>1,'app_code'=>'aigc_llm'])->count()===$consumptions,'Fixture unexpectedly charged');
  echo "Concurrent Agent passed: 3 processes/6 runs, one submit each, bound-task restart queries, real child crash quarantine, no paid calls\n";
 }finally{
  foreach($children as [$process,$log]){proc_terminate($process);proc_close($process);fclose($log);}
  if($ids)Db::transaction(function()use($ids){foreach(['event','step','run'] as $table)S::table($table)->whereIn($table==='run'?'id':'run_id',$ids)->delete();});
  if($sessions)Db::name('aigc_llm_session')->whereIn('id',$sessions)->delete();
  foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);
 }
}
