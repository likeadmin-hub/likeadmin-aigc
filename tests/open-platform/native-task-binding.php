<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenCallContext as C;
use app\common\model\app\aigc_image\AigcImageTask as Image;
use app\common\model\app\aigc_product_suite\AigcProductSuiteTask as Suite;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixtures only');
Db::startTrans();try{
 $begin=static function($app){$rid='req_fixture_'.bin2hex(random_bytes(16));S::table('request')->insert(['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'tool','app_code'=>$app,'method'=>'POST','path'=>'/api/v1/tools/'.$app.'/tasks','status'=>'accepted','billing_status'=>'pending','active'=>1,'consumption_ids'=>'[]','snapshot'=>'{}','payload_hash'=>hash('sha256',$rid),'response_blob'=>'','budget_user'=>1,'budget_tenant'=>1]+S::timestamp());C::enter($rid);return $rid;};
 $image=static fn($u=1)=>Image::create(['tenant_id'=>1,'user_id'=>$u,'prompt'=>'fixture','style'=>'hairstyle','reference_images'=>[],'provider_params_json'=>[],'quantity'=>1,'provider'=>'fixture','status'=>'pending','create_time'=>time(),'update_time'=>time()]);
 $rid=$begin('aigc_hairstyle');$foreign=$image(2);check(S::table('request')->where('request_id',$rid)->value('tool_task_id')==='','Foreign task bound');$task=$image();check(S::table('request')->where('request_id',$rid)->value('tool_task_id')===(string)$task['id'],'Alias task not persisted before transport');$image();check(S::table('request')->where('request_id',$rid)->value('tool_task_id')===(string)$task['id'],'Second task replaced durable mapping');
 $rid=$begin('aigc_product_suite');$image();check(S::table('request')->where('request_id',$rid)->value('tool_task_id')==='','Composite child bound as parent');$parent=Suite::create(['tenant_id'=>1,'user_id'=>1,'batch_no'=>'fixture_'.bin2hex(random_bytes(8)),'image_task_ids'=>[],'module_snapshot'=>[],'module_codes'=>[],'product_images'=>[],'status'=>'running','quantity'=>1,'delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);$image();check(S::table('request')->where('request_id',$rid)->value('tool_task_id')===(string)$parent['id'],'Parent mapping replaced by child');
 $rid=$begin('aigc_music');$image();check(S::table('request')->where('request_id',$rid)->value('tool_task_id')==='','Different tool task bound');
 echo "PASS shared native task aliases bind before transport; foreign owners, unrelated tools and composite children cannot replace mappings\n";
}finally{C::leave();Db::rollback();}
