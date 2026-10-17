<?php
/** Export an explicitly selected published preset as source JSON, retaining media in public object storage. */
require dirname(__DIR__).'/vendor/autoload.php'; (new think\App())->initialize();
use app\common\service\app\aigc_canvas\{CanvasTemplateService,CanvasTemplateMediaStorage,CanvasBuiltinPresetService};
use app\common\service\{CanvasPortalService,FileService};
use app\common\service\storage\{StorageConfigService,Driver};
use app\common\service\open_platform\OpenSafeHttp;
use app\common\model\file\TenantFile;
$options=getopt('',['tenant:','template:','id:','title:','version:','write']);
if (PHP_SAPI!=='cli' || empty($options['tenant']) || empty($options['template']) || !preg_match('/^builtin-[a-z0-9_-]{1,56}$/D',$options['id'] ?? '')) {
    fwrite(STDERR,"Usage: php scripts/export-canvas-builtin-preset.php --tenant=1 --template=CARD_ID --id=builtin-slug [--title=TITLE] [--version=1] [--write]\nWithout --write only checks the selected snapshot. Writes source JSON to runtime/canvas-presets/; never modifies tenant catalog or source files.\n");exit(1);
}
request()->tenantId=(int)$options['tenant'];request()->source=app\common\enum\AdminTerminalEnum::TENANT;
$card=null;foreach(CanvasPortalService::editor()['config']['cards'] as $row)if($row['id']===$options['template'])$card=$row;
if (!$card || !$card['workflow_ready']) throw new InvalidArgumentException('请选择当前租户已经发布的工作流预设');
$graph=CanvasTemplateService::publishedSnapshot((int)$options['tenant'],$card['id']);
if (empty($graph['media_schema'])) throw new InvalidArgumentException('请先从原画布重新发布，以保留媒体素材');
$config=StorageConfigService::getEffectiveConfig((int)$options['tenant']);$engine=$config['engine'][$config['default']]??[];
if ($config['default']==='local' || !empty($engine['private_access']) || !empty($engine['private'])) throw new InvalidArgumentException('内置示例媒体需要公开的对象存储/CDN，请先配置后导出');
if (!isset($options['write'])) {echo json_encode(['id'=>$options['id'],'title'=>$options['title']??$card['title'],'nodes'=>count($graph['nodes']),'storage'=>$config['default'],'domain'=>StorageConfigService::getStorageDomain($config),'ready'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";exit;}
$copies=[];$total=0;
$copy=static function(array $file)use(&$copies,&$total,$config,$options):string {
    $url=CanvasTemplateMediaStorage::resolve($file);
    if(isset($copies[$url]))return $copies[$url];
    $bytes=OpenSafeHttp::get($url,104857600,35)['body'];$total+=strlen($bytes);
    if($total>268435456)throw new InvalidArgumentException('内置预设媒体总量超过256MB');
    $hash=hash('sha256',$bytes);$ext=strtolower(pathinfo($file['uri'],PATHINFO_EXTENSION));
    if(!preg_match('/^(png|jpg|jpeg|webp|gif|avif|mp4|webm|mov|mp3|wav|ogg|flac|m4a)$/D',$ext))throw new InvalidArgumentException('示例媒体类型不受支持');
    $dir='uploads/aigc_canvas/builtin/'.$options['id'];$name=$hash.'.'.$ext;$temp=tempnam(sys_get_temp_dir(),'canvas_builtin_');
    if($temp===false)throw new RuntimeException('无法创建素材临时文件');$local=$temp.'.'.$ext;
    try{rename($temp,$local);file_put_contents($local,$bytes);$driver=new Driver($config);$driver->setUploadFileByReal($local,$name);if(!$driver->upload($dir))throw new RuntimeException('内置示例媒体上传失败');}
    finally{@unlink($temp);@unlink($local);}
    return $copies[$url]=CanvasBuiltinPresetService::publicUrl(rtrim(StorageConfigService::getStorageDomain($config),'/').'/'.$dir.'/'.$name);
};
$walk=static function(array $value)use(&$walk,$copy):array {
    if(isset($value['template_file']))$value['url']=$copy($value['template_file']);
    if(isset($value['template_poster']))$value['poster']=$copy($value['template_poster']);
    foreach(['template_file','template_poster','model_id','market_model_id','market_sku_id','sku_id','fileId','file_id','asset_id']as$key)unset($value[$key]);
    foreach($value as$key=>$item)if(is_array($item))$value[$key]=$walk($item);
    return $value;
};
foreach($graph['nodes'] as &$node){$node['metadata']=$walk($node['metadata']);if(!empty($node['metadata']['mediaItems'])){$active=array_search($node['metadata']['activeMediaId']??'',array_column($node['metadata']['mediaItems'],'id'),true);$item=$node['metadata']['mediaItems'][$active===false?0:$active];$node['metadata']['url']=$item['url'];if($node['type']==='image')$node['metadata']['image']=$item['url'];$node['metadata']['poster']=$item['poster']??'';}foreach(['model','channel']as$key)if(isset($node['metadata'][$key])&&ctype_digit((string)$node['metadata'][$key]))unset($node['metadata'][$key]);}unset($node);
$cover='';if($card['file_id']){$f=TenantFile::where(['tenant_id'=>(int)$options['tenant'],'id'=>$card['file_id']])->findOrEmpty();if(!$f->isEmpty())$cover=$copy($f->toArray());}
$entry=CanvasBuiltinPresetService::validate(['schema_version'=>1,'id'=>$options['id'],'version'=>(int)($options['version']??1),'title'=>$options['title']??$card['title'],'category'=>$card['category'],'description'=>$card['description'],'cover'=>$cover,'graph'=>$graph]);
$directory=root_path().'runtime/canvas-presets';if(!is_dir($directory))mkdir($directory,0770,true);
$path=$directory.'/'.$entry['id'].'.json';file_put_contents($path,json_encode($entry,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo "Exported source definition: $path (".count($copies)." object-storage files)\n";
