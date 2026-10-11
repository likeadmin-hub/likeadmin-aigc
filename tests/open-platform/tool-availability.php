<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\open_platform\OpenToolRegistry as R;
use app\common\service\open_platform\OpenToolAdapter as A;
use app\common\service\open_platform\OpenContracts as C;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenCatalog;
use app\common\service\open_platform\OpenDocuments;
use app\common\service\open_platform\OpenManagement;
use think\facade\Db;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function denied(callable $fn){try{$fn();}catch(app\common\service\open_platform\OpenException $e){check($e->httpStatus===404,'Wrong unavailable-operation error');return;}throw new RuntimeException('Demo operation accepted');}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixture only');
Db::startTrans();try{
 $tool=R::get('aigc_music');$blocked=array_filter($tool['operations'],fn($op)=>!R::executable($op));check(count($blocked)===8,'Unsupported music inventory changed');
 foreach($blocked as $route=>$op){[$method,$suffix]=explode(' ',$route,2);$path='/api/v1/tools/aigc_music/'.$suffix;
  denied(fn()=>R::match($method,$path));
  denied(fn()=>C::validate(['code'=>'fixture','kind'=>'tool','method'=>$method,'path'=>$path,'config'=>[]]));
  // A direct trusted-controller call and a historical published contract cannot bypass the gate.
  denied(fn()=>A::call(request(),$op+['app_code'=>'aigc_music','history'=>true],[]));
  S::table('contract')->insert(['code'=>'availability_fixture_'.bin2hex(random_bytes(5)),'kind'=>'tool','method'=>$method,'path'=>$path,'version'=>1,'status'=>'published','product_id'=>0,'config'=>S::encode(['executor'=>'tool','app_code'=>'aigc_music','billable'=>true,'input'=>['type'=>'object'],'example'=>[],'responses'=>['200'=>['description'=>'OK']]])]+S::timestamp());
 }
 foreach([0,1] as $tenant){$settings=S::settings($tenant);$settings['apps'][]='aigc_music';OpenManagement::persistSettings($tenant,true,$settings);}
 $catalog=array_values(array_filter(OpenCatalog::forUser(1,1),fn($item)=>$item['code']==='aigc_music'))[0];
 check(count($catalog['operations'])===count($tool['operations'])-8,'Catalog exposes demo operations');
 foreach(OpenManagement::contractsForTenant(1) as $c)check(!str_contains($c['code'],'availability_fixture_'),'Tenant document directory exposes demo contract');
 foreach(array_keys((array)OpenDocuments::openapi(1)['paths']) as $path)foreach(array_keys($blocked) as $route)check($path!=='/api/v1/tools/aigc_music/'.explode(' ',$route,2)[1],'OpenAPI exposes demo operation');
 $caps=R::capabilities('aigc_music',['config_json'=>['allow_voice_clone'=>true],'export_types'=>['wav']]);
 check($caps['limitations']['exact_duration']===false&&$caps['config_json']['allow_voice_clone']===false&&!isset($caps['export_types']),'Capability falsely promises exact duration, cloning or conversion');
 check(R::supported('POST','/api/v1/tools/aigc_music/tasks')&&R::supported('GET','/api/v1/tools/aigc_music/results'),'Real generation and owned results disabled');
 echo "PASS 8 demo music operations denied in routing, publication, execution, catalog and OpenAPI; real generation remains registered with explicit duration limits\n";
}finally{Db::rollback();}
