<?php
/** Pure signing regression: no database, HTTP fetch, credentials or cloud writes. */
namespace app\common\service\storage {
    final class StorageConfigService {
        public static int $calls=0;
        public static function getStoredFileConfig(...$args): array {
            self::$calls++;
            return ['engine'=>['qiniu'=>['private_access'=>true,'access_key'=>'local-fixture-key','secret_key'=>'local-fixture-secret','domain'=>'https://owned-assets.example.com']]];
        }
    }
}
namespace {
 require dirname(__DIR__).'/vendor/autoload.php';
 use app\common\service\app\aigc_product_video\ProductVideoBuiltinCatalog as C;
 use app\common\service\storage\{StorageSignedUrlService as Signed,StorageConfigService as Config};
 $dir=sys_get_temp_dir().'/pv_signing_'.bin2hex(random_bytes(8));mkdir($dir,0700);
 try{C::withDirectory($dir,static function(){
   $domain='https://owned-assets.example.com';$uri='shared/product.png';
   C::write(['schema_version'=>1,'key'=>'builtin-'.str_repeat('a',24),'version'=>1,'title'=>'Shared signing fixture','status'=>1,'model_identity'=>['source_code'=>'fixture','upstream_resource_key'=>'video:fixture'],'configuration'=>['images'=>[['uri'=>$uri,'storage_engine'=>'qiniu','storage_domain'=>$domain]],'prompt'=>'展示商品','video_selection'=>['model_id'=>'','quality'=>'720P','ratio'=>'9:16','duration'=>8]],'media'=>['uri'=>'shared/preview.mp4','storage_engine'=>'qiniu','storage_domain'=>$domain]]);
   if(Signed::resolve($uri,'platform','qiniu',$domain,88)!==$domain.'/'.$uri||Config::$calls!==0)throw new RuntimeException('Approved public coordinate must not touch another installation credentials');
   foreach([[$uri,'tenant'],['private/own.png','platform']] as [$key,$scope]){
     $url=Signed::resolve($key,$scope,'qiniu',$domain,88);
     if(!str_starts_with($url,$domain.'/'.$key.'?e=')||!str_contains($url,'&token='))throw new RuntimeException('Existing private signing must remain scoped and signed');
   }
   if(Config::$calls!==2)throw new RuntimeException('Only registered platform references bypass private credentials');
   $rejected=false;try{Signed::resolve('../private.png','tenant','qiniu',$domain,88);}catch(RuntimeException $e){$rejected=$e->getMessage()==='PRIVATE_MEDIA_URL_INVALID';}
   if(!$rejected)throw new RuntimeException('Private path traversal guard changed');
   if(Signed::resolve('uploads/local.png','platform','local','',88)!==null)throw new RuntimeException('Local engine path changed');
 });echo "PASS registered shared URL isolation, normal tenant/platform private signing and unsafe-key regression\n";
 }finally{foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
}
