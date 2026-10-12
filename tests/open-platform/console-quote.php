<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\{OpenStore as S,OpenManagement as M,OpenContracts as C,OpenException};
use app\common\service\power\TenantPowerMarketService as T;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function rejects(callable $call,string $type){try{$call();throw new RuntimeException('Expected '.$type);}catch(OpenException $e){check($e->errorType===$type,'Unexpected quote rejection');}}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local DB required');
Db::startTrans();try{
 $product=T::detail(1,51);check($product&&!empty($product['skus']),'Local market fixture absent');$sku=array_values(array_filter($product['skus'],fn($s)=>!empty($s['sale_status'])))[0];
 foreach([0,1] as $tenant){$settings=S::settings($tenant);unset($settings['enabled']);$settings['models']=array_values(array_unique([...$settings['models'],'51']));M::persistSettings($tenant,true,$settings);}
 $code='fixture.quote.'.bin2hex(random_bytes(6));$draft=['code'=>$code,'method'=>'POST','path'=>'/api/v1/apps/watermark_removal/remove','kind'=>'market','product_id'=>51,'config'=>['executor'=>'json','role'=>'submit','billable'=>true,'input'=>['type'=>'object','required'=>['fixture'],'properties'=>['fixture'=>['type'=>'string']]],'example'=>['fixture'=>'hello'],'responses'=>['200'=>['description'=>'OK']],'billing'=>[['sku_id'=>(int)$sku['id'],'basis'=>'input','max_units'=>1]]]];
 $values=C::validate($draft);$id=S::table('contract')->insertGetId($values+['version'=>1,'status'=>'published']+S::timestamp());
 $before=S::table('request')->count();$logs=Db::name('user_account_log')->count();
 $quote=M::quote(1,1,$code,['fixture'=>'hello']);check($quote['reserved_points']===(float)$sku['sale_points']&&$quote['pricing_mode']==='fixed'&&$quote['items'][0]['unit_price']===(float)$sku['sale_points'],'Quote changed effective retail SKU price');
 check(S::table('request')->count()===$before&&Db::name('user_account_log')->count()===$logs,'Estimate created a request or wallet log');
 foreach(['tenant','snapshot','source_blob','platform_sale_points','upstream_price'] as $secret)check(!str_contains(S::encode($quote),$secret),'Quote exposes internal '.$secret);
 rejects(fn()=>M::quote(1,1,$code,['tenant_id'=>2,'fixture'=>'hello']),'invalid_request');
 S::table('contract')->where('id',$id)->update(['status'=>'draft']);rejects(fn()=>M::quote(1,1,$code,['fixture'=>'hello']),'not_found');
 S::table('contract')->where('id',$id)->update(['status'=>'published']);$settings=S::settings(1);unset($settings['enabled']);$settings['models']=[];M::persistSettings(1,true,$settings);rejects(fn()=>M::quote(1,1,$code,['fixture'=>'hello']),'permission_denied');
 M::persistSettings(1,false,$settings);rejects(fn()=>M::quote(1,1,$code,['fixture'=>'hello']),'tenant_disabled');
 echo "PASS own console retail estimate, publication/scope gates, no submission and no cost exposure\n";
}finally{Db::rollback();}
