<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\app\aigc_product_suite\AigcProductSuiteService as S;
use app\common\model\app\aigc_image\AigcImageTask as I;
use app\common\model\app\aigc_product_suite\AigcProductSuiteTask as P;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$c=config('database.connections.mysql');check($c['hostname']==='127.0.0.1'&&$c['database']==='z_cn','Local DB required');
Db::startTrans();
try{
 $prepared=['product_images'=>[],'platform'=>'amazon','country'=>'us','language'=>'en','module_codes'=>['a','b'],'modules'=>[['code'=>'a','name'=>'A'],['code'=>'b','name'=>'B']],'size_key'=>'square','width'=>1024,'height'=>1024,'config'=>['prompt_template'=>'商品图'],'user_prompt'=>'fixture','image_payload'=>['negative_prompt'=>'','channel'=>'fixture','quality'=>'1k','ratio'=>'1:1'],'quality_label'=>'1K','unit_price'=>1];
 $create=new ReflectionMethod(S::class,'createBatchTask');$task=$create->invoke(null,1,1,'fixture_'.bin2hex(random_bytes(5)),[],$prepared,['tenant_cost_points'=>2,'user_charge_points'=>2]);check((int)$task['id']>0&&$task['image_task_ids']===[],'Parent must exist before provider submission');
 $child=I::create(['tenant_id'=>1,'user_id'=>1,'prompt'=>'fixture','reference_images'=>[],'provider_params_json'=>[],'quantity'=>1,'provider'=>'fixture','status'=>'pending','create_time'=>time(),'update_time'=>time()]);
 S::attachImageTask((int)$task['id'],$child);$stored=P::findOrEmpty((int)$task['id']);check($stored['image_task_ids']===[(int)$child['id']],'Created child must be durable before transport');
 S::attachImageTask((int)$task['id'],$child);check(count(P::findOrEmpty((int)$task['id'])['image_task_ids'])===1,'Repeated child association duplicated');
 $other=I::create(['tenant_id'=>1,'user_id'=>2,'prompt'=>'fixture','reference_images'=>[],'provider_params_json'=>[],'quantity'=>1,'provider'=>'fixture','status'=>'pending','create_time'=>time(),'update_time'=>time()]);
 $denied=false;try{S::attachImageTask((int)$task['id'],$other);}catch(RuntimeException){$denied=true;}check($denied,'Foreign user child attached');
 echo "PASS composite parent before submit, durable children, idempotent association and ownership\n";
}finally{Db::rollback();}
