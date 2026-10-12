<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\{OpenCatalog as C,OpenPages as P,OpenStore as S};
use app\common\model\power\{PowerMarketProduct as Product,PowerMarketSku as Sku,TenantPowerMarketSkuPrice as Price};
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local rollback only');
Db::startTrans();try{
 $base=Product::where('status',1)->find()->toArray();unset($base['id']);
 $sku=Sku::where(['status'=>1,'sale_status'=>1])->find()->toArray();unset($sku['id']);
 $product=Product::create(array_replace($base,['name'=>'Console catalogue fixture','product_code'=>'opf_console_'.bin2hex(random_bytes(5)),'resource_type'=>'model','model_type'=>'audio','status'=>1,'source_payload'=>['api_key'=>'never_public_fixture']]));
 $native=Sku::create(array_replace($sku,['product_id'=>$product['id'],'sku_key'=>'opf_console_'.bin2hex(random_bytes(5)),'status'=>1,'sale_status'=>1,'sale_points'=>19,'source_payload'=>['credential'=>'never_public_fixture']]));
 $tenant=987654319;Price::create(['tenant_id'=>$tenant,'sku_id'=>$native['id'],'sale_points'=>29,'sale_status'=>1,'create_time'=>time(),'update_time'=>time()]);
 $find=static fn($items)=>array_values(array_filter($items,fn($item)=>(int)($item['product_id']??0)===(int)$product['id']));
 $items=C::forConsole($tenant,1);$found=$find($items);check(count($found)===1,'Unpublished market product missing from console discovery');$item=$found[0];
 check($item['category_code']==='audio'&&$item['category_name']==='音频生成'&&$item['prices'][0]['price']===29.,'Market classification or effective retail mismatch');
 check(!$item['available']&&$item['operations']===[]&&$item['reason']==='接口尚未发布','Discovery implicitly published executable API');
 check(!str_contains(S::encode($item),'never_public_fixture')&&!str_contains(S::encode($item),'platform_sale_points'),'Console leaked supplier payload/cost');
 check(count(array_filter($items,fn($item)=>$item['kind']==='tool'&&$item['category_code']==='tools'))===27,'Tools not kept in their own category');
 check(!$find(C::forUser($tenant,1)),'Unpublished console product reached protocol directory');
 Price::where(['tenant_id'=>$tenant,'sku_id'=>$native['id']])->update(['sale_status'=>0]);check(!$find(C::forConsole($tenant,1)),'Tenant unshelved API remained in on-shelf catalogue');
 Price::where(['tenant_id'=>$tenant,'sku_id'=>$native['id']])->update(['sale_status'=>1]);$native->save(['sale_status'=>0]);check(!$find(C::forConsole($tenant,1)),'Platform unshelved SKU remained visible');
 $icons=array_column(P::defaults(),'icon','id');check(count(array_unique($icons))===9&&$icons['keys']==='keys'&&$icons['home']==='home','Developer pages still share one icon');
 echo "PASS console market discovery uses category and effective retail, never publishes routes or costs, respects tenant/platform shelf, and distinguishes 27 tool APIs and nine icons\n";
}finally{Db::rollback();}
