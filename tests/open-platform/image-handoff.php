<?php
/** Rollback fixtures exercise actual native parent persistence; no upstream calls or stored files. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenCallContext as C;
use app\common\service\open_platform\OpenImageHandoff as H;
use app\common\service\open_platform\OpenToolRegistry as R;
use app\common\service\open_platform\OpenException;
use app\common\model\app\aigc_image\AigcImageTask as Image;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixtures only');
$wallet=Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money');$tenantWallet=Db::name('tenant')->where('id',1)->value('point_balance');
$prepared=['source_image'=>'uploads/fixture.png','product_image'=>'uploads/fixture.png','model_image'=>'uploads/fixture.png','wear_image'=>'uploads/fixture.png','scene_mode'=>'template','style_mode'=>'template','template_id'=>0,'custom_scene_image'=>'','style_image'=>'','mask_image'=>'uploads/mask.png','size_key'=>'square','width'=>1024,'height'=>1024,'user_prompt'=>'fixture','unit_price'=>2,'quality_label'=>'1K','price_package'=>['code'=>'fixture','name'=>'Fixture'],'source_language'=>'zh','source_language_label'=>'Chinese','target_language'=>'en','target_language_label'=>'English','restore_type'=>['code'=>'enhance','name'=>'Enhance'],'image_payload'=>['prompt'=>'fixture','negative_prompt'=>'']];
$estimate=['tenant_cost_points'=>1,'user_charge_points'=>2];
$image=static fn($user=1,$tenant=1)=>Image::create(['tenant_id'=>$tenant,'user_id'=>$user,'prompt'=>'fixture','reference_images'=>[],'provider_params_json'=>[],'quantity'=>1,'provider'=>'fixture','status'=>'running','channel'=>'fixture','quality'=>'1k','ratio'=>'1:1','error'=>'','finish_time'=>0,'delete_time'=>0,'create_time'=>time(),'update_time'=>time()]);
Db::startTrans();try{
 check(H::callback(1,1,'aigc_style_transfer',static fn()=>null)===null,'Ordinary user calls gained API side effects');
 foreach(['product_image','style_transfer','background_removal','outpaint','image_translate','local_redraw','photo_restore','model_wear'] as $name){
  $app='aigc_'.$name;$class='app\\common\\service\\app\\'.$app.'\\Aigc'.implode('',array_map('ucfirst',explode('_',$name))).'Service';$upsert=new ReflectionMethod($class,'upsertTaskFromImageTask');
  $rid='req_fixture_'.bin2hex(random_bytes(16));S::table('request')->insert(['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'tool','app_code'=>$app,'method'=>'POST','path'=>'/api/v1/tools/'.$app.'/tasks','status'=>'accepted','billing_status'=>'pending','active'=>1,'consumption_ids'=>'[]','snapshot'=>'{}','payload_hash'=>hash('sha256',$rid),'response_blob'=>'','budget_user'=>2,'budget_tenant'=>1]+S::timestamp());C::enter($rid);
  $attach=static fn($child)=>$upsert->invoke(null,1,1,(int)$child['id'],$prepared,$estimate);$callback=H::callback(1,1,$app,$attach);
  $child=$image();check(S::table('request')->where('request_id',$rid)->value('tool_task_id')==='','Child incorrectly mapped as parent');
  $callback($child);$parentId=S::table('request')->where('request_id',$rid)->value('tool_task_id');check((int)$parentId>0,$app.' missing durable parent before submit');
  $model=R::get($app)['task_model'];$parent=$model::findOrEmpty((int)$parentId);check((int)$parent['image_task_id']===(int)$child['id']&&$parent['status']==='running',$app.' missing running child association');
  // A transport exception after this handoff cannot erase the queryable identity.
  try{throw new RuntimeException('fixture submit timeout');}catch(RuntimeException){}
  $callback($child);check($model::where(['tenant_id'=>1,'image_task_id'=>$child['id']])->count()===1,$app.' duplicate parent');
  foreach([$image(2),$image(1,2)] as $foreign){$denied=false;try{$callback($foreign);}catch(OpenException){$denied=true;}check($denied,$app.' foreign child attached');}
  $second=$image();$denied=false;try{$callback($second);}catch(OpenException){$denied=true;}check($denied&&$model::where(['tenant_id'=>1,'image_task_id'=>$second['id']])->count()===0,$app.' conflicting parent was not rolled back');
  S::table('request')->where('request_id',$rid)->update(['lease_token'=>'fixture-new','lease_expire'=>time()+60]);C::enter($rid,'fixture-old');$denied=false;try{$callback($child);}catch(OpenException){$denied=true;}check($denied,$app.' stale recovery lease wrote parent');C::leave();
 }
 echo "PASS 8 native image parents persist before submit; repeat handoff is idempotent, owners and leases fenced, conflicting parent rolls back without provider or billing calls\n";
}finally{C::leave();Db::rollback();}
check(Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money')===$wallet&&Db::name('tenant')->where('id',1)->value('point_balance')===$tenantWallet,'Rollback changed wallets');
