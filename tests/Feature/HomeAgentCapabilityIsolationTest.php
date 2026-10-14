<?php
namespace app\common\service\app\aigc_video {
 // An unavailable display catalogue must not prevent the Agent loading capabilities.
 class VideoEffectService {
  public static function catalog(int $tenant):array{throw new \RuntimeException('fixture: unavailable optional preview catalogue');}
 }
}
namespace {
 require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
 require dirname(__DIR__).'/Support/HomeAgentFixture.php';
 set_exception_handler(static function(\Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);exit(1);});
 use app\common\service\app\aigc_video\AigcVideoService as Video;
 use app\common\service\app\aigc_llm\agent\{AgentStore as S,AgentCapabilityCatalog as C};
 use app\common\model\app\aigc_video\AigcVideoConfig;
 use think\facade\Db;
 function check($ok,$message){if(!$ok)throw new \RuntimeException($message);}
 $db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&in_array($db['database'],['x_cn','z_cn'],true),'Local rollback test only');
 $r=request();$r->tenantId=1;$r->userId=1;$r->userInfo=['tenant_id'=>1,'user_id'=>1];
 try{Video::config(1);throw new \LogicException('Broken preview fixture did not intercept display config');}catch(\RuntimeException $e){check(str_contains($e->getMessage(),'fixture:'),'Unexpected error');}
 $models=C::models(1);check($models['video']===Video::creationConfig(1)['option_config'],'Agent changed selected video catalogue');
 $run=['tenant_id'=>1,'user_id'=>1,'preferences'=>S::encode(HomeAgentFixture::preferences())];
 check(isset(C::all(1,1)['aigc_video']),'Optional preview failure removed video tool');
 check(!empty(C::describe($run,'aigc_video')['selected_model']['selection']),'Optional preview failure blocked selected model');
 Db::startTrans();try{
  if(AigcVideoConfig::where('tenant_id',1)->count())AigcVideoConfig::where('tenant_id',1)->update(['status'=>0]);
  else AigcVideoConfig::create(['tenant_id'=>1,'status'=>0,'config_json'=>[],'create_time'=>time(),'update_time'=>time()]);
  check(!isset(C::all(1,1)['aigc_video']),'Disabled video tool became accessible');
  try{C::describe($run,'aigc_video');throw new \RuntimeException('Disabled model exposed');}catch(\InvalidArgumentException){}
 }finally{Db::rollback();}
 echo "Home Agent capability isolation passed: preview failure, catalogue, selected model and disabled-tool boundary; rolled back\n";
}
