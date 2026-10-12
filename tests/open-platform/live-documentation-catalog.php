<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\open_platform\{OpenDocumentationCatalog as D,OpenStore as S,OpenManagement as M};
use app\common\model\power\{PowerMarketProduct as Product,PowerMarketSku as Sku};
use think\facade\Db;
function verifyLiveDocs($ok,$message){if(!$ok)throw new RuntimeException($message);}
$c=config('database.connections.mysql');verifyLiveDocs($c['hostname']==='127.0.0.1'&&$c['database']==='z_cn','Local rollback only');
$contracts=S::table('contract')->order('id')->select()->toArray();$documents=S::table('document')->order('id')->select()->toArray();$requests=S::table('request')->count();$tag=bin2hex(random_bytes(4));
Db::startTrans();try{
    $base=Product::where('resource_type','app_api')->find()->toArray();$template=Sku::where(['product_id'=>$base['id'],'status'=>1,'sale_status'=>1])->find()->toArray();unset($base['id'],$template['id']);$base['create_time']=$base['update_time']=$template['create_time']=$template['update_time']=time();
    $base['name']='Dynamic docs '.$tag;$base['product_code']='dynamic_docs_'.$tag;$base['upstream_resource_key']='dynamic_docs_'.$tag;$base['upstream_app_code']='future_app_'.$tag;$base['upstream_api_code']='new_api';$base['status']=1;
    $base['source_payload']=S::encode(['market_metadata'=>['app_name'=>'Future App','category_code'=>'audio','params_schema'=>['text'=>['type'=>'string','required'=>true]],'default_params'=>['secret_key'=>'SECRET_FIXTURE'],'developer_doc_content'=>'https://private-vendor.test/secret','method'=>'POST','call_type'=>1]]);
    $product=Product::create($base);$template['product_id']=$product['id'];$template['sale_points']=12.5;$template['sale_status']=1;$sku=Sku::create($template);
    $entries=D::build(1);$found=array_values(array_filter($entries,fn($entry)=>($entry['x-product-id']??0)===$product['id']));
    verifyLiveDocs(count($found)===1,'New market API not discovered without importing/publishing');
    $item=$found[0];verifyLiveDocs($item['section']==='应用 API'&&$item['group']==='Future App'&&$item['x-published']===false&&$item['x-callable']===false,'Reference incorrectly became callable');
    verifyLiveDocs(isset($item['requestBody']['content']['application/json']['schema']['properties']['text']),'New params missing');
    verifyLiveDocs((float)$item['x-prices'][0]['price']===12.5,'Retail price missing');
    $encoded=S::encode($entries);foreach(['SECRET_FIXTURE','private-vendor.test','source_payload','platform_sale_points','upstream_api_key'] as $secret)verifyLiveDocs(!str_contains($encoded,$secret),'Internal metadata leaked: '.$secret);
    $before=Sku::where('id',$sku['id'])->find()->toArray();$settings=S::settings(0);M::setMarketExposure(0,[(int)$product['id']],true,true);verifyLiveDocs(in_array((string)$product['id'],S::settings(0)['models'],true),'Application API could not join');M::setMarketExposure(0,[(int)$product['id']],false,true);verifyLiveDocs(!in_array((string)$product['id'],S::settings(0)['models'],true),'Application API could not leave');verifyLiveDocs(Sku::where('id',$sku['id'])->find()->toArray()===$before,'API batch changed pricing');
    $hidden=Product::create(array_replace($base,['name'=>'Hidden child '.$tag,'product_code'=>'hidden_child_'.$tag,'upstream_resource_key'=>'hidden_child_'.$tag,'status'=>0]));
    M::setMarketExposure(0,[(int)$product['id'],(int)$hidden['id']],true,true);M::setMarketExposure(0,[(int)$product['id']],false,true,[$base['upstream_app_code']]);verifyLiveDocs(!array_intersect([(string)$product['id'],(string)$hidden['id']],S::settings(0)['models']),'App cancellation retained hidden child');
    $disabledSku=Sku::create(array_replace($template,['sku_key'=>'disabled_'.$tag,'sale_status'=>0]));
    $config=['executor'=>'json','role'=>'submit','billable'=>true,'title'=>'Disabled SKU reference','input'=>['type'=>'object','properties'=>['text'=>['type'=>'string']],'required'=>['text']],'example'=>['text'=>'GET example retained'],'responses'=>[],'billing'=>[['sku_id'=>(int)$disabledSku['id'],'basis'=>'input','max_units'=>1]]];
    $contractId=S::table('contract')->insertGetId(['code'=>'docs.test.'.$tag,'kind'=>'market','product_id'=>(int)$product['id'],'method'=>'GET','path'=>'/api/v1/test_live_docs','version'=>1,'status'=>'published','config'=>S::encode($config)]+S::timestamp());
    M::setMarketExposure(0,[(int)$product['id']],true,true);S::table('setting')->where('tenant_id',0)->update(['enabled'=>1]);$local=S::settings(1);$local['tenant_enabled']=true;$local['models'][]=(string)$product['id'];S::table('setting')->where('tenant_id',1)->update(['enabled'=>1,'config'=>S::encode($local)]);
    $entries=D::build(1);$publishedEntry=array_values(array_filter($entries,fn($entry)=>($entry['path']??'')==='/api/v1/test_live_docs'))[0];
    verifyLiveDocs($publishedEntry['x-published']===true&&$publishedEntry['x-callable']===false,'Unavailable published SKU marked callable');verifyLiveDocs($publishedEntry['parameters'][0]['example']==='GET example retained','GET example lost');
    $history=array_values(array_filter($entries,fn($entry)=>($entry['x-builtin-history']??false)));verifyLiveDocs($history&&$history[0]['x-callable']===true,'Built-in historical reads marked unavailable');
    $reflection=new ReflectionMethod(D::class,'published');$config['executor']='multipart';$multipart=$reflection->invoke(null,['code'=>'multipart.test','version'=>1,'method'=>'POST','path'=>'/api/v1/multipart','config'=>S::encode($config)]);verifyLiveDocs(isset($multipart['requestBody']['content']['multipart/form-data']),'Multipart executor became JSON');
    Product::where('id',$product['id'])->update(['status'=>0]);verifyLiveDocs(!array_filter(D::build(1),fn($entry)=>($entry['x-product-id']??0)===$product['id']),'Unshelved market API still visible');
    $spec=app\common\service\open_platform\OpenDocuments::openapi(1);$paths=(array)$spec['paths'];verifyLiveDocs($paths['/api/v1/test_live_docs']['get']['x-callable']===false,'Off-shelf published fallback incorrectly callable');S::table('contract')->where('id',$contractId)->delete();
    verifyLiveDocs(S::table('contract')->order('id')->select()->toArray()===$contracts&&S::table('document')->order('id')->select()->toArray()===$documents&&S::table('request')->count()===$requests,'Read projection published or executed');
}finally{Db::rollback();}
echo "PASS live discovery, schema/category/retail projection, safe metadata, application API join/cancel, shelf removal; no publication/call/price changes\n";
