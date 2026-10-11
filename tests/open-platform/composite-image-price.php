<?php
/** Owned immutable prepared parameters; rollback writes and refused loopback prevent paid submission. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenManagement as M;
use app\common\service\open_platform\OpenImagePricing as P;
use app\common\service\open_platform\OpenCallContext as C;
use app\common\service\open_platform\OpenVault as V;
use app\common\service\open_platform\OpenException;
use app\common\service\update\UpdateSourceClient as Source;
use app\common\model\app\aigc_image\AigcImageTask as Image;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check(PHP_SAPI==='cli'&&$db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback fixtures only');
$wallet=Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money');$tenantWallet=Db::name('tenant')->where('id',1)->value('point_balance');$request=request();$request->tenantId=1;$request->userId=1;
Db::startTrans();try{
 $source=Db::name('update_source')->where('status',1)->order('id','desc')->find();check($source,'No local source');unset($source['id']);Db::name('update_source')->insert(array_replace($source,['name'=>'opf-refused-loopback','base_url'=>'http://127.0.0.1:1','online_base_url'=>'http://127.0.0.1:1','license_key'=>'fixture','online_license_key'=>'fixture','dev_mode'=>0]));check(Source::getSource()['active_base_url']==='http://127.0.0.1:1','Refuse real supplier');
 foreach([0,1] as $t){$settings=S::settings($t);$settings['apps']=array_values(array_unique(array_merge($settings['apps'],P::COMPOSITES,['aigc_image'])));M::persistSettings($t,true,$settings);}
 $input=['channel'=>'market_image_model:154','quality'=>'1k','ratio'=>'1:1','quantity'=>1];$native=P::budget($request,$input);$stored=S::json(V::open($native['snapshot']['native_selection_blob']));
 $prepared=['source_image'=>'uploads/fixture.png','product_image'=>'uploads/fixture.png','model_image'=>'uploads/fixture.png','wear_image'=>'uploads/fixture.png','scene_mode'=>'template','style_mode'=>'template','template_id'=>0,'custom_scene_image'=>'','style_image'=>'','mask_image'=>'uploads/mask.png','size_key'=>'square','width'=>1024,'height'=>1024,'user_prompt'=>'fixture','unit_price'=>2,'quality_label'=>'1K','price_package'=>['code'=>'fixture','name'=>'Fixture'],'source_language'=>'zh','source_language_label'=>'Chinese','target_language'=>'en','target_language_label'=>'English','restore_type'=>['code'=>'enhance','name'=>'Enhance']];
 foreach(P::COMPOSITES as $app){
  $suffix=substr($app,5);$class='app\\common\\service\\app\\'.$app.'\\Aigc'.implode('',array_map('ucfirst',explode('_',$suffix))).'Service';
  $rid='req_fixture_'.bin2hex(random_bytes(16));$prompt='synthetic immutable '.$suffix.' '.$rid;$body=$prepared+['image_payload'=>$input+['model_id'=>$input['channel'],'prompt'=>$prompt,'negative_prompt'=>'','reference_images'=>[]]];
  $estimate=['tenant_cost_points'=>$native['tenant'],'user_charge_points'=>2];$snapshot=$native['snapshot'];$stored['app_code']=$app;$snapshot['native_selection_blob']=V::seal(S::encode($stored));$snapshot['quote']=$estimate;$snapshot['native_prepared_blob']=V::seal(S::encode(['app_code'=>$app,'tenant_id'=>1,'user_id'=>1,'prepared'=>$body,'estimate'=>$estimate]));
  S::table('request')->insert(['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>0,'kind'=>'tool','app_code'=>$app,'method'=>'POST','path'=>'/api/v1/tools/'.$app.'/tasks','status'=>'accepted','billing_status'=>'pending','active'=>1,'budget_user'=>2,'budget_tenant'=>$native['tenant'],'lease_token'=>'new-owner','lease_expire'=>time()+60,'consumption_ids'=>'[]','snapshot'=>S::encode($snapshot),'response_blob'=>'','payload_hash'=>hash('sha256',$rid)]+S::timestamp());C::enter($rid,'expired-owner');
  $frozen=P::prepared(1,1,$app);check($frozen===['prepared'=>$body,'estimate'=>$estimate],$app.' prepared recipe changed');check(P::prepared(1,2,$app)===null&&P::prepared(2,1,$app)===null,$app.' private recipe leaked');
  $stopped=false;try{$class::generate(1,1,[]);}catch(OpenException $e){$stopped=$e->errorType==='recovery_lease_lost';}check($stopped,$app.' did not reach fenced native entry using accepted prepared parameters');
  $image=Image::where(['tenant_id'=>1,'user_id'=>1,'prompt'=>$prompt])->findOrEmpty();check(!$image->isEmpty()&&(float)$image['user_charge_points']===2.&&(float)$image['tenant_cost_points']===$native['tenant'],$app.' native entry repriced');
  check(S::json(S::table('request')->where('request_id',$rid)->value('consumption_ids'))===[],$app.' reserved before handoff fence');C::leave();
 }
 // Exercise actual admission preparation with a previously owned synthetic portrait.
 $receipt=S::json(file_get_contents(dirname(__DIR__,2).'/runtime/open-platform-acceptance/multimodal-reconciliation.json'));$portrait='';foreach($receipt['items']??[] as $item)if($item['app_code']==='aigc_image')$portrait=$item['file_id'];check($portrait!=='','Owned portrait fixture missing');
 $params=app\common\service\open_platform\OpenMaterials::resolve(1,1,['source_image'=>$portrait,'template_id'=>7,'price_package'=>'default_1','prompt'=>'Synthetic immutable template fixture']);
 $actual=P::compositeBudget($request,'aigc_style_transfer',$params);check($actual['user']>0&&$actual['tenant']>0&&isset($actual['snapshot']['native_prepared_blob']),'Validated native admission recipe missing');
 check(Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money')===$wallet&&Db::name('tenant')->where('id',1)->value('point_balance')===$tenantWallet,'Fixture charged live wallet');
 echo "PASS 8 composite image native entries retain owned prepared recipe and retail price; stale lease stops before reservation/provider; actual style admission captures validated template and SKU with no charge\n";
}finally{C::leave();Db::rollback();}
