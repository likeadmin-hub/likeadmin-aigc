<?php
declare(strict_types=1);
namespace app\common\service\decorate;

use app\common\enum\FileEnum;
use app\common\model\file\TenantFile;
use app\common\service\FileService;
use app\common\service\decorate\agent\DecorationAgentAssetReferences as References;
use app\common\service\open_platform\OpenSafeHttp;
use app\common\service\storage\{Driver,StorageConfigService};
use RuntimeException;

/** A bounded import session. Object keys are unique to this attempt, so compensation cannot delete shared files. */
final class DecorationPortableAssets
{
    public const MAX_FILES=128;
    public const MAX_FILE_BYTES=20971520;
    public const MAX_TOTAL_BYTES=104857600;
    public const MAX_SECONDS=60;
    private int $bytes=0;
    private float $deadline;
    private array $config;
    private array $sources=[];
    private array $hashes=[];
    private array $objects=[];
    private array $materials=[];
    private string $directory;

    public function __construct(private int $tenant,private array $embedded=[])
    {
        if($tenant<=0)throw new RuntimeException('装修素材租户无效');
        $this->deadline=microtime(true)+self::MAX_SECONDS;
        $this->config=StorageConfigService::getEffectiveConfig($tenant);
        $this->directory='uploads/decoration/'.$tenant.'/'.bin2hex(random_bytes(12));
    }

    public static function export(array $payload,int $tenant,?callable $progress=null): array
    {
        $managed=self::managedAssets($payload,$tenant);$resources=[];$cache=[];$files=[];$bytes=0;$deadline=microtime(true)+self::MAX_SECONDS;
        $payload=DecorationPortableDocument::rewrite($payload,function(string $value,string $location)use($tenant,$managed,&$resources,&$cache,&$files,&$bytes,$deadline,$progress):string{
            if(isset($cache[$value])){$url=$cache[$value];$resources[$url]['locations'][]=$location;return $url;}
            if(isset($managed[$value])){
                $asset=$managed[$value];$url=FileService::getFileUrlByStorage($asset['image_uri'],$asset['storage_scope'],$asset['storage_engine'],$asset['storage_domain']??'').self::fragment($value);
            }else {
                $url=self::exportUrl($value,$tenant);
                $asset=self::ownedExternal($value,$tenant);
                if($asset){
                    $url=FileService::getFileUrlByStorage($asset['image_uri'],$asset['storage_scope'],$asset['storage_engine'],$asset['storage_domain']).self::fragment($value);
                    // Transform parameters must affect the copied bytes; never silently drop them.
                    $query=(string)parse_url($value,PHP_URL_QUERY);
                    if($query!==''&&self::hasTransformQuery($query)){
                        if(preg_match('~^https?://~i',$value))$url=$value;
                        elseif(self::privateAsset($asset,$tenant))throw new RuntimeException('私有相对素材含图片处理参数，请先保存处理结果后再导出');
                        else $url=self::withoutFragment($url).(str_contains($url,'?')?'&':'?').$query.self::fragment($value);
                    }
                }
            }
            if(!preg_match('~^https?://~i',$url))throw new RuntimeException('素材无法转换为完整URL，请检查站点域名');
            if(!isset($resources[$url])){
                if(count($resources)>=self::MAX_FILES)throw new RuntimeException('模板素材数量不能超过128个');
                $progress && $progress('正在整理第'.(count($resources)+1).'个素材');
                $resources[$url]=['id'=>'asset_'.(count($resources)+1),'url'=>$url,'locations'=>[]];
                if(($asset&&self::privateAsset($asset,$tenant))||self::signedQuery($url)){
                    $remaining=$deadline-microtime(true);if($remaining<4)throw new RuntimeException('私有素材导出超时');
                    $body=OpenSafeHttp::get(self::withoutFragment($url),self::MAX_FILE_BYTES,max(1,min(10,(int)floor($remaining/4))))['body'];
                    [$body,$mime,$ext]=DecorationPortableMedia::validate($body);$hash=hash('sha256',$body);$entry='assets/'.$hash.'.'.$ext;
                    if(!isset($files[$entry])){$bytes+=strlen($body);if($bytes>20*1024*1024)throw new RuntimeException('私有素材与模板合计不能超过20MB');$files[$entry]=$body;}
                    $resources[$url]+=['entry'=>$entry,'sha256'=>$hash,'size'=>strlen($body),'mime'=>$mime];
                }
            }
            $resources[$url]['locations'][]=$location;$cache[$value]=$url;return $url;
        });
        return ['payload'=>$payload,'resources'=>['version'=>1,'count'=>count($resources),'items'=>array_values($resources)],'files'=>$files];
    }

    public function localize(array $payload,?callable $progress=null): array
    {
        $payload=DecorationPortableDocument::rewrite($payload,function(string $url,string $location)use($progress):string{
            try{
                $fresh=!isset($this->sources[$url]);
                if($fresh)$progress && $progress('正在处理第'.(count($this->sources)+1).'个素材');
                $copied=$this->copy($url);
                if($fresh)$progress && $progress('已处理'.count($this->sources).'个素材，已保存'.count($this->hashes).'个独立文件');
                return $copied;
            }catch(\Throwable $e){throw new RuntimeException('素材导入失败（'.$location.'）：'.$e->getMessage(),0,$e);}
        });
        // Fresh file identities are attached only to schema-declared image positions.
        foreach($payload['pages'] as &$page){
            foreach(['data','meta','draft_data','draft_meta','published_data','published_meta'] as $field){
                if(empty($page[$field]))continue;$original=json_decode($page[$field]);
                if(!is_array($original))continue;
                $array=json_decode($page[$field],true);$section=str_contains($field,'meta')?'meta':'data';
                $attached=References::attachMaterials([$section=>$array],array_values($this->materials),$this->tenant);
                $this->mergeManaged($original,$attached[$section]??[]);
                $page[$field]=json_encode($original,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            }
        }unset($page);
        $this->remaining();return $payload;
    }

    /** Only newly imported namespace paths enter this hydration/normalization policy. */
    public static function refreshPage(array $page,int $tenant): array
    {
        $transform=self::delivery($tenant,false);
        $page=DecorationPortableDocument::rewrite(['pages'=>[$page]],$transform,false)['pages'][0];
        if(isset($page['preview_settings']))$page['preview_settings']=DecorationPortableDocument::value($page['preview_settings'],$transform);
        return $page;
    }
    public static function refreshTemplate(array $template,int $tenant): array
    {return DecorationPortableDocument::rewrite(['template'=>$template],self::delivery($tenant,false),false)['template'];}
    public static function refreshValue(array $value,int $tenant): array
    {return DecorationPortableDocument::value($value,self::delivery($tenant,false));}
    public static function normalizePage(array $page,int $tenant): array
    {return DecorationPortableDocument::rewrite(['pages'=>[$page]],self::delivery($tenant,true),false)['pages'][0];}
    public static function normalizeValue(array $value,int $tenant): array
    {return DecorationPortableDocument::value($value,self::delivery($tenant,true));}

    private static function delivery(int $tenant,bool $stable): \Closure
    {
        $cache=[];
        return static function(string $value)use($tenant,$stable,&$cache):string{
            $path=(string)parse_url(html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8'),PHP_URL_PATH);
            if(!preg_match('~^/?uploads/decoration/([1-9][0-9]*)/[a-f0-9]{24}/[a-f0-9]{64}\.[a-z0-9]{2,5}$~D',$path,$match))return $value;
            $uri=ltrim($path,'/');
            if((int)$match[1]!==$tenant||$tenant<=0)throw new RuntimeException('装修导入素材不属于当前租户');
            if(isset($cache[$uri]))return $cache[$uri].self::fragment($value);
            $file=TenantFile::where(['tenant_id'=>$tenant,'source'=>FileEnum::SOURCE_ADMIN,'uri'=>$uri])->findOrEmpty();
            if($file->isEmpty()){
                // Missing media must not block lists, editor or published-page reads.
                // Writes still require a live owned file; never sign a deleted object.
                if($stable)throw new RuntimeException('装修导入素材已失效或无权读取');
                return '';
            }
            if($stable)return '/'.$uri.self::fragment($value);
            $request=request();$previous=$request->tenantId??null;$request->tenantId=$tenant;
            try{$cache[$uri]=FileService::getFileUrlByStorage($uri,$file['storage_scope'],$file['storage_engine'],$file['storage_domain']);return $cache[$uri].self::fragment($value);}
            finally{$request->tenantId=$previous;}
        };
    }

    public function summary(): array {return ['files'=>count($this->hashes),'references'=>count($this->sources),'bytes'=>$this->bytes];}

    public function rollback(): void
    {
        $failed=[];$deadline=microtime(true)+10;
        foreach(array_reverse($this->objects) as $object){
            if(microtime(true)>=$deadline-1){$failed[]=$object;continue;}
            $config=$this->config;$engine=$config['default'];$config['engine'][$engine]['operation_timeout']=max(1,min(2,(int)floor($deadline-microtime(true))));
            try{if(!(new Driver($config))->delete($object))$failed[]=$object;}catch(\Throwable $e){$failed[]=$object;}
        }
        $this->objects=[];
        if($failed){
            // Durable, credential-free repair record; cleanup is limited to this unique import prefix.
            $dir=runtime_path().'decorate_import/';if(!is_dir($dir))@mkdir($dir,0750,true);
            @file_put_contents($dir.'cleanup_'.basename($this->directory).'.json',json_encode(['tenant_id'=>$this->tenant,'directory'=>$this->directory,'storage_scope'=>$this->config['scope'],'storage_engine'=>$this->config['default'],'objects'=>$failed,'created_at'=>time()],JSON_UNESCAPED_SLASHES));
            throw new RuntimeException('模板未创建；部分新素材清理失败，请管理员检查装修导入清理记录');
        }
        if(($this->config['default']??'')==='local')@rmdir(public_path().$this->directory);
    }

    /** Also used by rollback-only integration fixtures after the DB transaction is rolled back. */
    public function createdObjects(): array {return $this->objects;}

    private function copy(string $url): string
    {
        $remaining=$this->remaining();if(isset($this->sources[$url]))return $this->sources[$url];
        if(count($this->sources)>=self::MAX_FILES)throw new RuntimeException('素材数量不能超过128个');
        $path=isset($this->embedded[$url])?null:self::trustedLocal($url,$this->tenant);
        if(isset($this->embedded[$url]))$bytes=$this->embedded[$url];
        elseif($path!==null){if(filesize($path)>self::MAX_FILE_BYTES)throw new RuntimeException('单个素材不能超过20MB');$bytes=file_get_contents($path);if($bytes===false)throw new RuntimeException('素材读取失败');}
        else{
            if(!preg_match('~^https?://~i',$url))throw new RuntimeException('旧模板相对素材无法在本站定位，请在来源站重新导出完整URL模板包');
            // OpenSafeHttp revalidates/pins every redirect; reserve at most four request budgets.
            $bytes=OpenSafeHttp::get(self::withoutFragment($url),min(self::MAX_FILE_BYTES,self::MAX_TOTAL_BYTES-$this->bytes),max(1,min(10,(int)floor($remaining/4))))['body'];
        }
        $this->bytes+=strlen($bytes);if($this->bytes>self::MAX_TOTAL_BYTES)throw new RuntimeException('模板素材总量不能超过100MB');
        [$bytes,$mime,$ext]=DecorationPortableMedia::validate($bytes);$hash=hash('sha256',$bytes);
        $this->remaining();
        if(isset($this->hashes[$hash]))return $this->sources[$url]=$this->hashes[$hash].self::fragment($url);
        $engine=(string)$this->config['default'];
        $temp=tempnam(sys_get_temp_dir(),'decorate_asset_');if($temp===false)throw new RuntimeException('素材暂存失败');$file=$temp.'.'.$ext;$key=$this->directory.'/'.$hash.'.'.$ext;
        try{
            if(!rename($temp,$file)||file_put_contents($file,$bytes)!==strlen($bytes))throw new RuntimeException('素材暂存失败');
            $config=$this->config;$config['engine'][$engine]['operation_timeout']=max(2,min(10,(int)floor($this->remaining())));
            $driver=new Driver($config);$driver->setUploadFileByReal($file,$hash.'.'.$ext);
            $this->objects[]=$key; // Track uncertain SDK outcomes as well as successful uploads.
            if(!$driver->upload($this->directory))throw new RuntimeException('素材转存失败，请检查当前租户存储配置');
        }finally{@unlink($temp);@unlink($file);}
        $this->remaining();
        $row=TenantFile::create(['tenant_id'=>$this->tenant,'cid'=>0,'source'=>FileEnum::SOURCE_ADMIN,'source_id'=>(int)(request()->adminId??0),'type'=>str_starts_with($mime,'image/')?FileEnum::IMAGE_TYPE:(str_starts_with($mime,'video/')?FileEnum::VIDEO_TYPE:FileEnum::FILE_TYPE),'name'=>'template_'.$hash.'.'.$ext,'uri'=>$key,'storage_scope'=>$this->config['scope'],'storage_engine'=>$engine,'storage_domain'=>StorageConfigService::getStorageDomain($this->config),'upload_origin'=>'','create_time'=>time()]);
        // Persist stable identity, never a private delivery signature. Every decoration read hydrates it.
        $delivery='/'.$key;
        $this->materials[$delivery]=['url'=>$delivery,'file_id'=>(int)$row['id']];
        $this->hashes[$hash]=$delivery;return $this->sources[$url]=$delivery.self::fragment($url);
    }

    private function remaining(): float
    { $remaining=$this->deadline-microtime(true);if($remaining<4)throw new RuntimeException('素材导入超时（总预算60秒），请减少素材后重试');return $remaining; }

    private static function fragment(string $url): string
    { $fragment=parse_url($url,PHP_URL_FRAGMENT);return $fragment!==null?'#'.$fragment:''; }
    private static function withoutFragment(string $url): string {return explode('#',$url,2)[0];}
    private static function signedQuery(string $url): bool
    {return (bool)preg_match('/(?:^|&)(?:token|e|expires|signature|ossaccesskeyid|x-oss-[^=]+|x-amz-[^=]+|q-sign-[^=]+)=/i',(string)parse_url($url,PHP_URL_QUERY));}
    private static function hasTransformQuery(string $query): bool
    {parse_str($query,$parts);foreach(array_keys($parts) as $key)if(!preg_match('/^(?:token|e|expires|signature|ossaccesskeyid|x-oss-.+|x-amz-.+|q-sign-.+)$/i',(string)$key))return true;return false;}
    private static function ownedExternal(string $value,int $tenant): ?array
    {
        $path=ltrim((string)parse_url($value,PHP_URL_PATH),'/');if($path==='')return null;
        $segments=explode('/',$path);$candidates=[];
        for($i=0;$i<min(count($segments),16);$i++){$candidate=implode('/',array_slice($segments,$i));$candidates[]=$candidate;$candidates[]='/'.$candidate;}
        $rows=TenantFile::where(['tenant_id'=>$tenant,'source'=>FileEnum::SOURCE_ADMIN])->whereIn('uri',$candidates)->select()->toArray();
        foreach($rows as $row){
            $asset=['image_uri'=>$row['uri'],'storage_scope'=>$row['storage_scope'],'storage_engine'=>$row['storage_engine'],'storage_domain'=>$row['storage_domain']];
            $canonical=FileService::getFileUrlByStorage($asset['image_uri'],$asset['storage_scope'],$asset['storage_engine'],$asset['storage_domain']);
            $a=parse_url($value);$b=parse_url($canonical);
            if(!preg_match('~^https?://~i',$value)||self::sameOrigin($value)||($a&&$b&&strtolower($a['scheme']??'')===strtolower($b['scheme']??'')&&strtolower($a['host']??'')===strtolower($b['host']??'')&&($a['port']??(($a['scheme']??'')==='https'?443:80))===($b['port']??(($b['scheme']??'')==='https'?443:80))&&($a['path']??'')===($b['path']??'')&&!isset($a['user'])&&!isset($a['pass'])))return $asset;
        }
        return null;
    }

    private static function privateAsset(array $asset,int $tenant): bool
    {
        $engine=(string)($asset['storage_engine']??'');if(!in_array($engine,['qiniu','aliyun','qcloud'],true))return false;
        $config=StorageConfigService::getStoredFileConfig($tenant,(string)($asset['storage_scope']??''),$engine);
        $storage=$config['engine'][$engine]??[];return !empty($storage['private_access'])||!empty($storage['private']);
    }

    private static function exportUrl(string $value,int $tenant): string
    {
        if(preg_match('~^(?:data|blob|file|javascript):~i',$value)||str_starts_with($value,'//'))throw new RuntimeException('素材地址必须是HTTP完整URL或本站资源路径');
        $path=(string)parse_url($value,PHP_URL_PATH);$isRelative=!preg_match('~^https?://~i',$value);$same=self::sameOrigin($value);
        if(($isRelative||$same)&&preg_match('~^/?uploads/~',$path)){
            $file=self::ownedUpload($path,$tenant);
            return FileService::getFileUrlByStorage($file['uri'],$file['storage_scope']??'',$file['storage_engine']??'',$file['storage_domain']??'').self::fragment($value);
        }
        if($isRelative||$same){DecorationPortableMedia::localPath($path,public_path());return rtrim(request()->domain(),'/').'/'.ltrim($path,'/').(parse_url($value,PHP_URL_QUERY)!==null?'?'.parse_url($value,PHP_URL_QUERY):'').self::fragment($value);}
        if(!preg_match('~^https?://~i',$value))throw new RuntimeException('素材地址无效');
        return $value;
    }

    private static function trustedLocal(string $url,int $tenant): ?string
    {
        $relative=!preg_match('~^https?://~i',$url);
        if(!$relative&&!self::sameOrigin($url))return null;
        if(preg_match('~^(?:data|blob|file|javascript):~i',$url)||str_starts_with($url,'//'))throw new RuntimeException('素材地址协议不支持');
        $path=(string)parse_url($url,PHP_URL_PATH);
        if(preg_match('~^/?uploads/~',$path)){
            $file=self::ownedUpload($path,$tenant);
            if(($file['storage_engine']??'local')!=='local')return null;
        }
        return DecorationPortableMedia::localPath($path,public_path());
    }

    private static function ownedUpload(string $path,int $tenant): array
    {
        $uri=ltrim($path,'/');
        $file=TenantFile::where(['tenant_id'=>$tenant,'source'=>FileEnum::SOURCE_ADMIN])->whereIn('uri',[$uri,'/'.$uri])->findOrEmpty();
        if($file->isEmpty())throw new RuntimeException('本站上传素材缺少当前租户后台文件归属，不能读取或签名');
        return $file->toArray();
    }

    private static function sameOrigin(string $url): bool
    {
        $a=parse_url($url);$b=parse_url(request()->domain());
        return $a&&$b&&isset($a['scheme'],$a['host'])&&strtolower($a['scheme'])===strtolower($b['scheme']??'')&&strtolower($a['host'])===strtolower($b['host']??'')&&($a['port']??($a['scheme']==='https'?443:80))===($b['port']??(($b['scheme']??'')==='https'?443:80))&&!isset($a['user'])&&!isset($a['pass']);
    }

    private static function managedAssets(array $payload,int $tenant): array
    {
        $managed=[];
        foreach($payload['pages']??[] as $page)foreach([['data','meta'],['draft_data','draft_meta'],['published_data','published_meta']] as [$data,$meta]){
            $pair=['data'=>json_decode($page[$data]??'[]',true)?:[],'meta'=>json_decode($page[$meta]??'[]',true)?:[]];
            foreach(References::captureIdentities($pair,$tenant) as $uri=>$asset){if(isset($managed[$uri])&&$managed[$uri]!==$asset)throw new RuntimeException('模板素材存储身份冲突');$managed[$uri]=$asset;}
        }
        return $managed;
    }

    private function mergeManaged(array &$original,array $attached): void
    {
        foreach($original as $i=>&$widget){if(!is_object($widget)||!isset($attached[$i]))continue;$row=$attached[$i];
            if(isset($row['_decoration_asset_refs'])){
                $widget->_decoration_asset_refs=json_decode(json_encode($row['_decoration_asset_refs']));
                foreach($row['_decoration_asset_refs'] as $field=>$ref){$parts=explode('.',$field);$cursor=&$widget;foreach($parts as $part){if(is_object($cursor))$cursor=&$cursor->$part;elseif(is_array($cursor))$cursor=&$cursor[(int)$part];else continue 2;}$cursor=$ref['expected_value'];unset($cursor);}
            }
            if(isset($widget->children)&&is_array($widget->children))$this->mergeManaged($widget->children,$row['children']??[]);
        }unset($widget);
    }
}
