<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
set_exception_handler(static function(Throwable $error): void {fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);});
use think\facade\Db;
use app\tenantapi\controller\decorate\AgentController;
use app\common\service\decorate\agent\{DecorationAgentService as S,DecorationAgentDesignService as D,DecorationAgentCatalog as C,DecorationAgentOperations as O,DecorationAgentMediaService as M};
use app\common\service\decorate\DecorateTemplateService as T;
use app\common\service\power\MarketTextModelRuntimeService as Text;
$checks=0;function transportCheck(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$originalRequest=request();Db::startTrans();
try {
 $template=T::add(1,['name'=>'design transport isolated','terminal'=>'mobile']);$page=T::pageLists(1,$template['id'],'mobile')[0];
 $models=array_values(array_filter(Text::textOptions(1),fn($model)=>!empty($model['supports_vision'])));$image=M::options(1)[0];
 $payload=['template_id'=>$template['id'],'page_id'=>$page['id'],'message'=>'设计整页创作工作室','catalog_hash'=>C::hash(),'design_first'=>true,'scope'=>'page','sources'=>['generate','content'],'model_selection'=>['id'=>$models[0]['id']],'image_model_selection'=>['market_product_id'=>$image['market_product_id'],'market_sku_id'=>$image['skus'][0]['market_sku_id']],'budget_points'=>25];
 $quote=static function(array $payload): array {
  $request=(new app\Request())->withServer(['REQUEST_METHOD'=>'POST'])->withHeader(['content-type'=>'application/json;charset=UTF-8'])->withInput(S::json($payload));
  $request->adminInfo=['tenant_id'=>1,'admin_id'=>1,'root'=>1];$request->tenantId=1;app()->instance('request',$request);
  return (new AgentController(app()))->quote()->getData();
 };
 $result=$quote($payload);transportCheck(($result['code']??0)===1&&$result['data']['max_images']===1,'actual JSON Request and controller preserve design_first boolean true');
 $payload['design_first']=false;$result=$quote($payload);transportCheck(($result['code']??0)===1&&$result['data']['max_images']===5,'actual JSON boolean false remains false');
 foreach(['false','true',0,1] as $invalid){$payload['design_first']=$invalid;$result=$quote($payload);transportCheck(($result['code']??1)!==1&&str_contains($result['msg']??'','布尔值'),'explicit nonboolean input remains rejected');}
 unset($payload['design_first']);$result=$quote($payload);transportCheck(($result['code']??0)===1&&$result['data']['max_images']===5,'omitted design_first retains ordinary default false');
 $review=static function(array $selection): array {
  $request=(new app\Request())->withServer(['REQUEST_METHOD'=>'POST'])->withHeader(['content-type'=>'application/json;charset=UTF-8'])->withInput(S::json(['idempotency_key'=>'transport-review','model_selection'=>$selection]));
  $request->adminInfo=['tenant_id'=>1,'admin_id'=>1,'root'=>1];$request->tenantId=1;app()->instance('request',$request);
  return (new AgentController(app()))->reviewDesign()->getData();
 };
 foreach([['id'=>true],['id'=>1,'sku_id'=>true]] as $selection){
  $result=$review($selection);transportCheck(($result['code']??1)!==1&&str_contains($result['msg']??'','标识无效'),'actual review controller rejects JSON boolean model identities before candidate lookup');
 }
 $reviewNotes=static function(mixed $notes): array {
  $request=(new app\Request())->withServer(['REQUEST_METHOD'=>'POST'])->withHeader(['content-type'=>'application/json;charset=UTF-8'])->withInput(S::json(['idempotency_key'=>'transport-review-notes','review_notes'=>$notes]));
  $request->adminInfo=['tenant_id'=>1,'admin_id'=>1,'root'=>1];$request->tenantId=1;app()->instance('request',$request);
  return (new AgentController(app()))->reviewDesign()->getData();
 };
 foreach([true,false,1,1.5,null,[]] as $notes){$result=$reviewNotes($notes);transportCheck(($result['code']??1)!==1&&str_contains($result['msg']??'','补充对照说明须为'),'actual JSON controller rejects non-string review notes before candidate lookup');}
 foreach(['https://fixture.invalid/%75ploads/design.png','https://fixture.invalid/uploads/temp/../design.png','https://fixture.invalid/uploads/%2e%2e/uploads/design.png','https://fixture.invalid/%2575ploads/design.png'] as $url){
  $page=O::apply(['data'=>[],'meta'=>[]],[['type'=>'add_component','name'=>'banner','content'=>['data'=>[['image'=>$url]]]]],['scope'=>'page','approved_urls'=>[$url]])['candidate'];
  $rejected=false;try{D::assertNoReferenceImages($page,null,['uris'=>['uploads/design.png']]);}catch(RuntimeException $error){$rejected=true;}
  transportCheck($rejected,'encoded or dot-segment design alias remains forbidden despite exact image URL approval');
 }
}finally{Db::rollback();app()->instance('request',$originalRequest);}
echo "design transport: $checks checks passed (DB rolled back, no provider calls)\n";
