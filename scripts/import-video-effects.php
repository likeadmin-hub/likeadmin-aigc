<?php
/** Import reviewed presets and local media into one tenant. No model calls.
 * php scripts/import-video-effects.php --tenant=1 --product=98 --media-dir=/tmp/video-effects --apply
 * Without --apply: validate prerequisites and report only. Reapplying replaces matching preset configs,
 * preserves custom effects and existing managed preview URLs, and reuses checksum-named material files.
 */
require dirname(__DIR__).'/vendor/autoload.php';
(new \think\App())->initialize();
use app\common\service\app\aigc_video\VideoEffectService as Effects;
use app\common\service\app\aigc_video\AigcVideoService as Video;
use app\common\service\storage\StorageConfigService;
use app\common\service\storage\Driver;
use app\common\model\file\TenantFile;
use app\common\service\FileService;
use think\facade\Db;
$options=getopt('', ['tenant:','product:','media-dir:','apply']);
$tenant=(int)($options['tenant']??0);$product=(int)($options['product']??0);$dir=realpath($options['media-dir']??'');
if($tenant<=0 || $product<=0 || !$dir)throw new RuntimeException('Require --tenant, --product and --media-dir');
$config=Video::config($tenant);$model=null;
foreach($config['option_config']['channels']??[] as $candidate)if((int)$candidate['market_product_id']===$product)$model=$candidate;
if(!$model)throw new RuntimeException('Selected model is not available for this tenant');
$admin=Db::name('tenant_system_admin')->where('tenant_id',$tenant)->where('is_disable',0)->order('id','asc')->value('id');
if(!$admin)throw new RuntimeException('No tenant administrator found');
$rows=Effects::defaults();$existing=array_column(Effects::catalog($tenant),null,'id');
foreach($rows as &$row){
    if(!array_intersect($row['methods'],$model['generation_modes']??[]))throw new RuntimeException('Unsupported model mode for '.$row['id']);
    $row['methods']=array_values(array_intersect($row['methods'],$model['generation_modes']));
    $row['product_ids']=[$product];$row['enabled']=true;
    foreach(['poster'=>'webp','video'=>'mp4'] as $field=>$ext){
        $old=$existing[$row['id']][$field]??'';
        if($old!=='' && !str_contains($old,'imagine.animagic.art')){$row[$field]=$old;continue;}
        $path=$dir.'/'.$row['id'].'.'.$ext;
        if(!is_file($path) || filesize($path)>40*1024*1024 || filesize($path)<100)throw new RuntimeException('Missing/invalid media: '.$row['id'].'.'.$ext);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
        if(!in_array($mime,$ext==='mp4'?['video/mp4']:['image/webp'],true))throw new RuntimeException('Unexpected media type: '.$row['id']);
    }
}unset($row);
Effects::normalize($rows);
if(!isset($options['apply'])){echo 'Validated '.count($rows)." presets and model capabilities; no changes made\n";exit;}
$storage=StorageConfigService::getEffectiveConfig($tenant);$domain=StorageConfigService::getEffectiveDomain($tenant);
foreach($rows as &$row){
    foreach(['poster'=>'webp','video'=>'mp4'] as $field=>$ext){
        if(!str_contains($row[$field],'imagine.animagic.art'))continue;
        $path=$dir.'/'.$row['id'].'.'.$ext;$name='effect-'.$row['id'].'-'.substr(hash_file('sha256',$path),0,12).'.'.$ext;
        $file=TenantFile::where(['tenant_id'=>$tenant,'name'=>$name,'source'=>0])->findOrEmpty();
        if($file->isEmpty()){
            $driver=new Driver($storage);$driver->setUploadFileByReal($path);$saveDir='uploads/video-effects/'.$tenant;
            if(!$driver->upload($saveDir))throw new RuntimeException('Media upload failed: '.$row['id']);
            $file=TenantFile::create(['tenant_id'=>$tenant,'cid'=>0,'type'=>$ext==='mp4'?20:10,'name'=>$name,'uri'=>$saveDir.'/'.$driver->getFileName(),'storage_scope'=>$storage['scope'],'storage_engine'=>$storage['default'],'storage_domain'=>$domain,'source'=>0,'source_id'=>(int)$admin,'upload_origin'=>'local','create_time'=>time()]);
        }
        $row[$field]=FileService::getFileUrlByStorage($file['uri'],$file['storage_scope'],$file['storage_engine'],$file['storage_domain']);
    }
    echo 'Prepared '.$row['id']."\n";
}unset($row);
// Reload before save so unrelated basic config and newly added custom effects survive the upload phase.
$latest=Video::config($tenant);$ids=array_column($rows,'id');
foreach($latest['video_effects']??[] as $row)if(!in_array($row['id'],$ids,true))$rows[]=$row;
Video::saveConfig($tenant,['config_json'=>array_replace($latest['config_json']??[],['video_effects'=>$rows])]);
echo 'Saved '.count($rows)." effects; no generation submitted\n";
