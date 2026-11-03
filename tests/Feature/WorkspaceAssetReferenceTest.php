<?php
// Local synthetic reference records only, rolled back; no network/provider/file operations.
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
set_exception_handler(static function(Throwable $error){fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n");exit(1);});
use think\facade\Db;
use app\common\service\WorkspaceAssetReferenceService as References;
use app\common\service\WorkspaceAssetService;
use app\common\service\app\aigc_product_suite\AigcProductSuiteService as Suite;
$checks=0;$check=static function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$db=config('database.connections.mysql');$check(PHP_SAPI==='cli' && $db['hostname']==='127.0.0.1' && in_array($db['database'],['x_cn','z_cn'],true),'Local rollback only');
Db::startTrans();
try {
 $tenant=1999999652;$user=$tenant;$uri='uploads/Recorded-Reference.PNG';$url='https://fixtures.invalid/'.$uri;
 Db::name('tenant_file')->insert(['tenant_id'=>$tenant,'source_id'=>$user,'source'=>1,'uri'=>$uri,'type'=>10,'storage_scope'=>'platform','storage_engine'=>'aliyun','storage_domain'=>'https://fixtures.invalid']);
 $refs=References::resolveGroups($tenant,$user,[References::inputs([['reference_images'=>json_encode([$uri]),'reference_assets'=>[['url'=>$url,'api_key'=>'never expose']]]])])[0];
 $check(count($refs)===1 && $refs[0]['url']===$url,'Stored reference domain replaces relative path and deduplicates');
 $check(!str_contains(json_encode($refs),'never expose'),'No secret descriptor metadata');
 $check(References::resolveGroups($tenant+1,$user,[[$uri]])[0]===[],'No foreign tenant storage lookup');
 $check(References::resolveGroups($tenant,$user+1,[[$uri]])[0]===[],'No foreign account storage lookup');
 $other='https://other.invalid/'.$uri;
 $check(References::resolveGroups($tenant,$user,[[$other]])[0][0]['url']===$other,'Do not rewrite unrelated external hosts');
 $check(References::resolveGroups($tenant,$user,[[['api_key'=>'secret'],'blob:expired']])[0]===[],'Malformed and expired local descriptors stay out');
 $task=Db::name('aigc_product_suite_task')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'product_images'=>json_encode([$uri]),'image_task_ids'=>'[]','status'=>'success']);
 $method=new ReflectionMethod(Suite::class,'appendTaskResults');$method->setAccessible(true);
 $rows=$method->invoke(null,$tenant,$user,[Db::name('aigc_product_suite_task')->where('id',$task)->find()]);
 $check($rows[0]['reference_assets'][0]['url']===$url && $rows[0]['product_image_urls']===[$url],'Product-suite task history uses persisted storage');
 $owned=new ReflectionMethod(WorkspaceAssetService::class,'owned');$owned->setAccessible(true);
 $saved=$owned->invoke(null,$tenant,$user,'product_suite:task:'.$task);
 $check($saved['raw']['reference_assets'][0]['url']===$url,'Favorites retain the same original reference');
 echo "PASS $checks storage references, product-suite results, favorites, identity and safe serialization checks; rolled back\n";
} finally { Db::rollback(); }
