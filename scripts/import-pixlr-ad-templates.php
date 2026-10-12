<?php
/** Append reviewed Ads templates to a tenant; existing IDs are never overwritten.
 * php scripts/import-pixlr-ad-templates.php --tenant=1 --product=98 --media-dir=... [--apply]
 */
require dirname(__DIR__).'/vendor/autoload.php';
(new \think\App())->initialize();
use app\common\service\app\aigc_video\VideoEffectService as Effects;
use app\common\service\app\aigc_video\AigcVideoService as Video;
use app\common\service\storage\StorageConfigService;
use app\common\service\storage\Driver;
use app\common\model\file\TenantFile;
use app\common\model\auth\TenantAdmin;
use app\common\service\FileService;

$options=getopt('', ['tenant:','product:','media-dir:','apply']);
$tenant=(int)($options['tenant']??0);$product=(int)($options['product']??0);$dir=realpath($options['media-dir']??'');
if($tenant<=0 || $product<=0 || !$dir)throw new RuntimeException('Require tenant, product and media directory');
$source=json_decode(file_get_contents(dirname(__DIR__).'/docs/video-effects/pixlr-ads.json'),true,512,JSON_THROW_ON_ERROR)['items'];
$media=array_column(json_decode(file_get_contents($dir.'/media.json'),true,512,JSON_THROW_ON_ERROR),null,'id');
$config=Video::config($tenant);$existing=Effects::catalog($tenant);$existingIds=array_column($existing,'id');
$model=null;foreach($config['option_config']['channels']??[] as $candidate)if((int)$candidate['market_product_id']===$product)$model=$candidate;
if(!$model || !in_array('image_to_video',$model['generation_modes']??[],true))throw new RuntimeException('Selected model must support image-to-video');
$quality=null;foreach($model['qualities']??[] as $q)if(strtolower($q['resolution']??'')==='768p')$quality=$q;
if(!$quality)throw new RuntimeException('Selected model must support 768P');
$durations=array_map('intval',$model['duration_options']??[]);$ratios=array_column($quality['ratios']??[],'value');
if(!$durations || !$ratios)throw new RuntimeException('Missing model capability options');
$prepared=[];
foreach($source as $recipe){
    $id=$recipe['id'];if(!preg_match('/^pixlr_ads_[a-f0-9]{24}$/D',$id))throw new RuntimeException('Invalid preset ID');
    if(in_array($id,$existingIds,true))continue;
    $info=$media[$id]??null;if(!$info || $info['source_video']!==$recipe['source_video'])throw new RuntimeException('Missing source metadata: '.$id);
    foreach(['video'=>['mp4','video/mp4',80],'image'=>['jpg','image/jpeg',10]] as $key=>[$ext,$mime,$limit]){
        $path=$dir.'/'.$id.'.'.$ext;
        if(!is_file($path) || filesize($path)<100 || filesize($path)>$limit*1024*1024 || (new finfo(FILEINFO_MIME_TYPE))->file($path)!==$mime || hash_file('sha256',$path)!==($info[$key.'_sha256']??''))throw new RuntimeException('Invalid media: '.$id.'/'.$key);
    }
    $duration=$durations[0];foreach($durations as $d)if(abs($d-$info['duration'])<abs($duration-$info['duration']))$duration=$d;
    $ratio='adaptive';if(!in_array($ratio,$ratios,true))throw new RuntimeException('Adaptive ratio required to retain first-frame composition');
    $prepared[]=['id'=>$id,'name'=>$recipe['name'],'description'=>$recipe['description'],'instruction'=>$recipe['instruction'],'categories'=>['Commercial/Ads'],'enabled'=>true,'product_ids'=>[$product],'methods'=>['image_to_video'],'require_image'=>true,'poster'=>'/validation/'.$id.'.jpg','video'=>'/validation/'.$id.'.mp4','template'=>['product_id'=>$product,'generation_method'=>'image_to_video','prompt'=>$recipe['prompt'],'duration'=>$duration,'resolution'=>$quality['resolution'],'ratio'=>$ratio,'reference_assets'=>[['type'=>'image','role'=>'reference_image','name'=>$recipe['name'].'首帧','url'=>'/validation/'.$id.'.jpg']]]];
}
Effects::normalize(array_merge($existing,$prepared));
echo 'Validated '.count($prepared).' new templates; '.count($existing)." existing templates preserved\n";
if(!isset($options['apply']) || !$prepared)exit;
$lock=fopen(runtime_path().'pixlr-ads-'.$tenant.'.lock','c');if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Import already running');
$admin=TenantAdmin::where('tenant_id',$tenant)->where('disable',0)->order('id','asc')->value('id');if(!$admin)throw new RuntimeException('Missing tenant administrator');
$backup=runtime_path().'pixlr-ads-backup-'.$tenant.'-'.date('YmdHis').'.json';
if(file_put_contents($backup,json_encode($config,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Backup failed');chmod($backup,0600);
$storage=StorageConfigService::getEffectiveConfig($tenant);$domain=StorageConfigService::getEffectiveDomain($tenant);
foreach($prepared as &$row){
    foreach(['poster'=>['jpg',10],'video'=>['mp4',20]] as $field=>[$ext,$type]){
        $path=$dir.'/'.$row['id'].'.'.$ext;$name=$row['id'].'-'.substr(hash_file('sha256',$path),0,16).'.'.$ext;
        $file=TenantFile::where(['tenant_id'=>$tenant,'name'=>$name,'source'=>0])->findOrEmpty();
        if($file->isEmpty()){
            $driver=new Driver($storage);$driver->setUploadFileByReal($path);$saveDir='uploads/video-effects/'.$tenant.'/pixlr-ads';
            if(!$driver->upload($saveDir))throw new RuntimeException('Upload failed: '.$row['id']);
            $file=TenantFile::create(['tenant_id'=>$tenant,'cid'=>0,'type'=>$type,'name'=>$name,'uri'=>$saveDir.'/'.$driver->getFileName(),'storage_scope'=>$storage['scope'],'storage_engine'=>$storage['default'],'storage_domain'=>$domain,'source'=>0,'source_id'=>(int)$admin,'upload_origin'=>'local','create_time'=>time()]);
        }
        $row[$field]=FileService::getFileUrlByStorage($file['uri'],$file['storage_scope'],$file['storage_engine'],$file['storage_domain']);
    }
    $row['template']['reference_assets'][0]['url']=$row['poster'];
    echo 'Stored '.$row['name']."\n";
}unset($row);
$latest=Video::config($tenant);
if(($latest['config_json']??[])!==($config['config_json']??[]))throw new RuntimeException('Configuration changed during import; no configuration saved. Rerun after review.');
$rows=Effects::normalize(array_merge($existing,$prepared));
Video::saveConfig($tenant,['config_json'=>array_replace($latest['config_json']??[],['video_effects'=>$rows])]);
$saved=array_column(Effects::catalog($tenant),null,'id');
foreach($prepared as $row)if(($saved[$row['id']]??null)!==$row)throw new RuntimeException('Readback mismatch');
echo 'Saved '.count($prepared).' Ads templates; total '.count($saved).". No generation submitted. Backup: ".$backup."\n";
