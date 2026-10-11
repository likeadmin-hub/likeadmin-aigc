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
  $frozen=P::prepared(1,1,$app);check(S::encode($frozen)===S::encode(['prepared'=>$body,'estimate'=>$estimate]),$app.' prepared recipe changed');check(P::prepared(1,2,$app)===null&&P::prepared(2,1,$app)===null,$app.' private recipe leaked');
  $stopped=false;try{$class::generate(1,1,[]);}catch(OpenException $e){$stopped=$e->errorType==='recovery_lease_lost';}check($stopped,$app.' did not reach fenced native entry using accepted prepared parameters');
  $image=Image::where(['tenant_id'=>1,'user_id'=>1,'prompt'=>$prompt])->findOrEmpty();
  if($app==='aigc_local_redraw')check($image->isEmpty(),'Stale mask materializer reached native image creation');
  else check(!$image->isEmpty()&&(float)$image['user_charge_points']===2.&&(float)$image['tenant_cost_points']===$native['tenant'],$app.' native entry repriced');
  check(S::json(S::table('request')->where('request_id',$rid)->value('consumption_ids'))===[],$app.' reserved before handoff fence');C::leave();S::table('request')->where('request_id',$rid)->update(['active'=>0]);
 }
 // Exercise actual admission preparation with a previously owned synthetic portrait.
 $receipt=S::json(file_get_contents(dirname(__DIR__,2).'/runtime/open-platform-acceptance/multimodal-reconciliation.json'));$portrait='';foreach($receipt['items']??[] as $item)if($item['app_code']==='aigc_image')$portrait=$item['file_id'];check($portrait!=='','Owned portrait fixture missing');
 $params=app\common\service\open_platform\OpenMaterials::resolve(1,1,['source_image'=>$portrait,'template_id'=>7,'price_package'=>'default_1','prompt'=>'Synthetic immutable template fixture']);
 $actual=P::compositeBudget($request,'aigc_style_transfer',$params);check($actual['user']>0&&$actual['tenant']>0&&isset($actual['snapshot']['native_prepared_blob']),'Validated native admission recipe missing');
 check(app()->request===$request&&!C::active(),'Read-only native admission scope leaked into ordinary pages');

 // Canonical native aliases must be checked before any pricing or network reads.
 $foreignUri='uploads/opf-foreign-'.bin2hex(random_bytes(8)).'.png';
 app\common\model\file\TenantFile::create(['tenant_id'=>1,'source_id'=>987654321,'source'=>app\common\enum\FileEnum::SOURCE_USER,'type'=>10,'uri'=>$foreignUri,'name'=>'fixture.png','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time()]);
 $denied=false;try{P::compositeBudget($request,'aigc_model_wear',['model'=>$foreignUri,'wear_image'=>$portrait]);}catch(OpenException $e){$denied=$e->httpStatus===404;}check($denied,'model material alias bypassed user ownership');
 $denied=false;try{P::compositeBudget($request,'aigc_model_wear',['model'=>'http://127.0.0.1/private.png','wear_image'=>$portrait]);}catch(OpenException $e){$denied=$e->errorType==='invalid_url';}check($denied,'model material alias bypassed private address validation');
 check(app()->request===$request&&!C::active(),'Failed native admission scope leaked into ordinary pages');
 // A public template comes from the verified native tenant template, never caller data.
 $adminUri='uploads/opf-admin-template-'.bin2hex(random_bytes(8)).'.png';
 app\common\model\file\TenantFile::create(['tenant_id'=>1,'source_id'=>1,'source'=>app\common\enum\FileEnum::SOURCE_ADMIN,'type'=>10,'uri'=>$adminUri,'name'=>'fixture.png','storage_scope'=>'tenant','storage_engine'=>'local','storage_domain'=>'','create_time'=>time()]);
 Db::name('aigc_style_transfer_style_template')->where(['tenant_id'=>1,'id'=>7])->update(['image'=>$adminUri]);
 $public=P::compositeBudget($request,'aigc_style_transfer',$params);$publicRecipe=S::json(V::open($public['snapshot']['native_prepared_blob']))['prepared'];
 check($public['user']===$actual['user']&&$public['tenant']===$actual['tenant']&&app\common\service\FileService::setFileUrl($publicRecipe['template']['image'])===$adminUri,'Authorized administrator template was rejected or repriced');
 $denied=false;try{P::compositeBudget($request,'aigc_style_transfer',array_replace($params,['source_image'=>$adminUri]));}catch(OpenException $e){$denied=$e->httpStatus===404;}check($denied,'Caller could pass shared template URI as private source input');
 $scene=Db::name('aigc_product_image_scene_template')->where(['tenant_id'=>1,'status'=>1,'delete_time'=>0])->find();check($scene,'Local scene template fixture missing');
 Db::name('aigc_product_image_scene_template')->where('id',$scene['id'])->update(['image'=>$adminUri,'vip'=>0]);
 $styleSelection=S::json(V::open($actual['snapshot']['native_selection_blob']))['selection'];
 $product=P::compositeBudget($request,'aigc_product_image',['product_image'=>$portrait,'template_id'=>$scene['id'],'channel'=>$styleSelection['channel']['code'],'quality'=>$styleSelection['spec']['quality'],'prompt'=>'Synthetic administrator template fixture']);
 $productRecipe=S::json(V::open($product['snapshot']['native_prepared_blob']))['prepared'];check(app\common\service\FileService::setFileUrl($productRecipe['template']['image'])===$adminUri,'Authorized product scene template rejected');
 // Actual Data URI preparation must be read-only, including mask_url alias.
 $mask='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6zwAAAABJRU5ErkJggg==';
 $sel=S::json(V::open($actual['snapshot']['native_selection_blob']))['selection'];
 $localParams=['source_image'=>$portrait,'mask_url'=>$mask,'prompt'=>'Synthetic local redraw fixture','channel'=>$sel['channel']['code'],'quality'=>$sel['spec']['quality'],'ratio'=>$sel['spec']['ratio']];
 $fileCount=Db::name('tenant_file')->where('tenant_id',1)->count();
 $local=P::compositeBudget($request,'aigc_local_redraw',$localParams);
 $localFrozen=S::json(V::open($local['snapshot']['native_prepared_blob']));
 check($localFrozen['prepared']['mask_image']===$mask&&$localFrozen['prepared']['image_payload']['mask_url']===$mask,'Mask was persisted before admission');
 $settings=S::settings(1);M::persistSettings(1,true,$settings);
 $keyId=S::table('key')->insertGetId(['tenant_id'=>1,'user_id'=>1,'name'=>'opf-local-mask-fixture','key_hash'=>hash('sha256',random_bytes(32)),'prefix'=>'fixture','status'=>1,'scopes'=>'["aigc_style_transfer"]','ip_allowlist'=>'[]','concurrency'=>1]+S::timestamp());
 $admissionBudget=array_replace($local,['user'=>max(1.,$local['user'])]); // Explicit positive boundary fixture; native free retail remains unchanged.
 $op=['kind'=>'tool','app_code'=>'aigc_local_redraw','billable'=>true];$path='/api/v1/tools/aigc_local_redraw/tasks';
 foreach(['permission_denied','key_quota_exceeded','concurrency_exceeded'] as $error){
  if($error!=='permission_denied')S::table('key')->where('id',$keyId)->update(['scopes'=>'["aigc_local_redraw"]','daily_limit'=>$error==='key_quota_exceeded'?0.01:0]);
  if($error==='concurrency_exceeded')S::table('request')->insert(['request_id'=>'req_fixture_'.bin2hex(random_bytes(16)),'tenant_id'=>1,'user_id'=>1,'key_id'=>$keyId,'kind'=>'tool','app_code'=>'aigc_local_redraw','method'=>'POST','path'=>$path,'status'=>'accepted','billing_status'=>'pending','active'=>1,'budget_user'=>0,'budget_tenant'=>0,'consumption_ids'=>'[]','snapshot'=>'{}','response_blob'=>'','payload_hash'=>hash('sha256','mask-fixture')]+S::timestamp());
  $key=S::table('key')->where('id',$keyId)->find();$denied=false;
  try{app\common\service\open_platform\OpenAdmission::begin($key,'POST',$path,$localParams,$op,$admissionBudget,'');}catch(OpenException $e){$observed=$e->errorType;$denied=$observed===$error;}
  check($denied,'Mask fixture expected '.$error.' observed '.($observed??'accepted'));check(Db::name('tenant_file')->where('tenant_id',1)->count()===$fileCount,'Rejected mask request wrote a file');
 }
 // Accepted preprocessing is fenced, persisted once in the private recipe, and reused.
 $rid='req_fixture_'.bin2hex(random_bytes(16));S::table('request')->insert(['request_id'=>$rid,'tenant_id'=>1,'user_id'=>1,'key_id'=>$keyId,'kind'=>'tool','app_code'=>'aigc_local_redraw','method'=>'POST','path'=>$path,'status'=>'accepted','billing_status'=>'pending','active'=>1,'budget_user'=>$local['user'],'budget_tenant'=>$local['tenant'],'lease_token'=>'valid-mask-owner','lease_expire'=>time()+60,'consumption_ids'=>'[]','snapshot'=>S::encode($local['snapshot']),'response_blob'=>'','payload_hash'=>hash('sha256',$rid)]+S::timestamp());
 $writes=0;$materialize=static function($recipe)use(&$writes){$writes++;$recipe['mask_image']='uploads/synthetic-stored-mask.png';$recipe['image_payload']['mask_url']=$recipe['mask_image'];return $recipe;};
 C::enter($rid,'stale-mask-owner');$denied=false;try{P::materializePrepared(1,1,'aigc_local_redraw',$materialize);}catch(OpenException $e){$denied=$e->errorType==='recovery_lease_lost';}check($denied&&$writes===0,'Stale worker persisted a mask');
 C::enter($rid,'valid-mask-owner');$first=P::materializePrepared(1,1,'aigc_local_redraw',$materialize);$second=P::materializePrepared(1,1,'aigc_local_redraw',$materialize);check($writes===1&&$first===$second&&$second['estimate']===$localFrozen['estimate'],'Accepted mask processing was repeated or repriced');C::leave();
 check(Db::name('user')->where(['tenant_id'=>1,'id'=>1])->value('user_money')===$wallet&&Db::name('tenant')->where('id',1)->value('point_balance')===$tenantWallet,'Fixture charged live wallet');
 echo "PASS 8 composite image native entries retain owned prepared recipe and retail price; stale lease stops before reservation/provider; actual style/local-redraw admission captures validated template, SKU and read-only mask; alias ownership, rejected mask writes and once-only leased preprocessing pass with no charge\n";
}finally{C::leave();Db::rollback();}
