<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
$c=config('database.connections.mysql');if(PHP_SAPI!=='cli'||$c['hostname']!=='127.0.0.1'||$c['database']!=='z_cn')throw new RuntimeException('Local inventory only');
use think\facade\Db;
$user=Db::name('user')->where('account','xhadmin001')->field('id,tenant_id,account,user_money,is_disable')->find();if(!$user)throw new RuntimeException('Test user missing');request()->tenantId=(int)$user['tenant_id'];
$tenant=Db::name('tenant')->where('id',$user['tenant_id'])->field('id,sn')->find();
$products=Db::name('power_market_product')->where('status',1)->field('id,name,resource_type,upstream_model_code,upstream_app_code,upstream_api_code')->select()->toArray();
$effective=[];foreach($products as $product){if($product['resource_type']!=='model')continue;$detail=app\common\service\power\TenantPowerMarketService::detail((int)$user['tenant_id'],(int)$product['id']);foreach($detail['skus']??[] as $sku){if(empty($sku['sale_status']))continue;$effective[]=['product_id'=>$product['id'],'name'=>$product['name'],'model'=>$product['upstream_model_code'],'sku_id'=>$sku['id'],'usage_unit'=>$sku['usage_unit'],'usage_unit_size'=>$sku['usage_unit_size'],'sale_points'=>$sku['sale_points'],'locked_params'=>$sku['locked_params']];}}
$tools=[];foreach(\app\common\service\open_platform\OpenToolRegistry::all() as $app=>$tool){$tools[$app]=['name'=>$tool['name'],'operations'=>count($tool['operations'])];}
$skus=Db::name('power_market_sku')->whereIn('product_id',[2,74,75,142])->field('id,product_id,sku_key,locked_params,usage_unit,usage_unit_size,sale_points,sale_status,status')->select()->toArray();
echo json_encode(['effective_model_skus'=>$effective,'user'=>$user,'tenant'=>$tenant,'skus'=>$skus,'product_count'=>count($products),'products'=>array_values(array_filter($products,fn($p)=>$p['resource_type']==='model'&&str_contains(strtolower($p['upstream_model_code']),'qwen'))),'tools'=>$tools],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
