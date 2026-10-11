<?php
/** Attach complete reusable templates to existing effects. No provider calls.
 * php scripts/configure-video-effect-templates.php --tenant=1 --product=98 --media-dir=/tmp/effect-template-inputs [--apply]
 * Requires recipes.json and ID-first.jpg / ID-last.jpg (transitions only).
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
$config=Video::config($tenant);$model=null;
foreach($config['option_config']['channels']??[] as $candidate)if((int)$candidate['market_product_id']===$product)$model=$candidate;
if(!$model)throw new RuntimeException('Model unavailable');
$recipes=json_decode(file_get_contents($dir.'/recipes.json'),true,512,JSON_THROW_ON_ERROR);
$existing=array_column(Effects::catalog($tenant),null,'id');$templates=[];$paths=[];
foreach($recipes as $recipe){
    $id=$recipe['id'];if(!isset($existing[$id]) || !preg_match('/^[a-z0-9_]+$/D',$id))throw new RuntimeException('Unknown effect');
    $row=$existing[$id];$method=$recipe['generation_method'];
    if(!in_array($product,$row['product_ids'],true) || !in_array($method,$row['methods'],true) || !in_array($method,$model['generation_modes'],true))throw new RuntimeException('Unsupported template model/mode: '.$id);
    if(!in_array($recipe['duration'],$model['duration_options']??[],true))throw new RuntimeException('Unsupported duration: '.$id);
    $quality=null;foreach($model['qualities']??[] as $q)if(($q['resolution']??'')==='768P')$quality=$q;
    if(!$quality || !in_array($recipe['ratio'],array_column($quality['ratios'],'value'),true))throw new RuntimeException('Unsupported resolution/ratio: '.$id);
    $assets=[];
    foreach($method==='start_end'?['first','last']:['first'] as $frame){
        $path=$dir.'/'.$id.'-'.$frame.'.jpg';
        if(!is_file($path) || filesize($path)>10*1024*1024 || (new finfo(FILEINFO_MIME_TYPE))->file($path)!=='image/jpeg')throw new RuntimeException('Invalid template image: '.$id);
        $paths[$id][$frame]=$path;
        $assets[]=['type'=>'image','url'=>'/template-validation/'.$id.'-'.$frame.'.jpg','role'=>$method==='start_end'?($frame==='first'?'first_frame_image':'last_frame_image'):'reference_image','name'=>$row['name'].($frame==='last'?'尾帧':'参考图')];
    }
    $templates[$id]=['product_id'=>$product,'generation_method'=>$method,'prompt'=>$row['description'],'duration'=>$recipe['duration'],'resolution'=>'768P','ratio'=>$recipe['ratio'],'reference_assets'=>$assets];
    Effects::normalize([array_replace($row,['template'=>$templates[$id]])]);
}
if(!isset($options['apply'])){echo 'Validated '.count($templates)." complete templates; no changes made\n";exit;}
$admin=TenantAdmin::where('tenant_id',$tenant)->where('disable',0)->order('id')->value('id');if(!$admin)throw new RuntimeException('No tenant administrator');
$storage=StorageConfigService::getEffectiveConfig($tenant);$domain=StorageConfigService::getEffectiveDomain($tenant);
foreach($templates as $id=>&$template){
    foreach($template['reference_assets'] as $index=>&$asset){
        $frame=$index===0?'first':'last';$path=$paths[$id][$frame];$name='effect-input-'.$id.'-'.$frame.'-'.substr(hash_file('sha256',$path),0,12).'.jpg';
        $file=TenantFile::where(['tenant_id'=>$tenant,'name'=>$name,'source'=>0])->findOrEmpty();
        if($file->isEmpty()){
            $driver=new Driver($storage);$driver->setUploadFileByReal($path);$saveDir='uploads/video-effects/'.$tenant;
            if(!$driver->upload($saveDir))throw new RuntimeException('Template image upload failed: '.$id);
            $file=TenantFile::create(['tenant_id'=>$tenant,'cid'=>0,'type'=>10,'name'=>$name,'uri'=>$saveDir.'/'.$driver->getFileName(),'storage_scope'=>$storage['scope'],'storage_engine'=>$storage['default'],'storage_domain'=>$domain,'source'=>0,'source_id'=>(int)$admin,'upload_origin'=>'local','create_time'=>time()]);
        }
        $asset['url']=FileService::getFileUrlByStorage($file['uri'],$file['storage_scope'],$file['storage_engine'],$file['storage_domain']);
    }unset($asset);
    echo 'Prepared '.$id."\n";
}unset($template);
$latest=Video::config($tenant);$rows=$latest['video_effects'];
foreach($rows as &$row)if(isset($templates[$row['id']]))$row['template']=$templates[$row['id']];unset($row);
Video::saveConfig($tenant,['config_json'=>array_replace($latest['config_json']??[],['video_effects'=>$rows])]);
echo 'Saved '.count($templates)." complete templates; no generation submitted\n";
