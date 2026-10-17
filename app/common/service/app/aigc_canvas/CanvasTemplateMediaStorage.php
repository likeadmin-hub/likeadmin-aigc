<?php
namespace app\common\service\app\aigc_canvas;

use app\common\model\TenantConfig;
use app\common\model\file\TenantFile;
use app\common\service\FileService;
use app\common\service\storage\{Driver,StorageConfigService};
use app\common\service\open_platform\OpenSafeHttp;
use InvalidArgumentException;

/** Publication copies files into immutable, tenant-owned template storage. No provider calls. */
final class CanvasTemplateMediaStorage
{
    private int $bytes=0;
    private array $copied=[];
    public function __construct(private int $tenant) {}

    public function copy(string $url,string $type,array $input=[]): array
    {
        $cacheKey=$type.':'.$url;
        if (isset($this->copied[$cacheKey])) return $this->copied[$cacheKey];
        if (count($this->copied)>=100) throw new InvalidArgumentException('预设媒体最多100份');
        if (isset($input['template_file'])) {
            $cached=$input['template_file'];$key='media_'.substr((string)($cached['checksum'] ?? ''),0,48);
            $stored=json_decode((string)TenantConfig::where(['tenant_id'=>$this->tenant,'type'=>'aigc_canvas_template','name'=>$key])->value('value'),true);
            if (!$stored || ($stored['uri'] ?? null)!==($cached['uri'] ?? null) || !str_starts_with($stored['mime_type'] ?? '',$type.'/')) throw new InvalidArgumentException('预设素材不属于当前租户或媒体类型不匹配');
            return $this->copied[$cacheKey]=['url'=>self::resolve($stored),'file'=>$stored,'mimeType'=>$stored['mime_type'],'fileSize'=>$stored['file_size']]+array_intersect_key($input,array_flip(['width','height','duration','fileName']));
        }
        if (preg_match('#^(blob|data|file|javascript):#i',$url) || str_contains($url,"\0") || str_contains(rawurldecode($url),'..')) throw new InvalidArgumentException('请先完成素材上传，再发布预设');
        $path=ltrim((string)parse_url($url,PHP_URL_PATH),'/');
        $file=TenantFile::where(['tenant_id'=>$this->tenant,'uri'=>$path])->findOrEmpty();
        // Cloud object keys can contain an endpoint prefix, or be stored as a full remote URL.
        if ($file->isEmpty()) $file=TenantFile::where(['tenant_id'=>$this->tenant,'uri'=>$url])->findOrEmpty();
        $local=null;
        if (!$file->isEmpty()) {
            if (($file['storage_engine'] ?? '')==='local' || (($file['storage_engine'] ?? '')==='' && is_file(public_path().$path))) {
                $root=realpath(public_path()); $candidate=realpath(public_path().ltrim($file['uri'],'/'));
                if (!$candidate || !str_starts_with($candidate,$root.DIRECTORY_SEPARATOR)) throw new InvalidArgumentException('来源素材文件不存在');
                $local=$candidate;
            } else $url=FileService::getFileUrlByStorage($file['uri'],$file['storage_scope'],$file['storage_engine'],$file['storage_domain']);
        } else {
            if ($path!=='' && !TenantFile::whereIn('uri',[$path,$url])->where('tenant_id','<>',$this->tenant)->findOrEmpty()->isEmpty()) throw new InvalidArgumentException('预设素材不属于当前租户');
            if (!preg_match('#^https?://#i',$url)) throw new InvalidArgumentException('来源素材未保存或不属于当前租户');
            // Never fetch the application/private network to bypass file ownership.
            if (parse_url($url,PHP_URL_HOST)===parse_url(request()->domain(),PHP_URL_HOST)) throw new InvalidArgumentException('来源素材缺少当前租户的文件记录');
        }
        $limit=$type==='image'?20971520:104857600;
        try {
            if ($local) {
                if (filesize($local)>$limit) throw new InvalidArgumentException('预设媒体文件过大');
                $bytes=file_get_contents($local);
            } else $bytes=OpenSafeHttp::get($url,$limit,35)['body'];
            $this->bytes+=strlen($bytes);
            if ($this->bytes>268435456) throw new InvalidArgumentException('单个预设媒体总量不能超过256MB');
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/avif'=>'avif','video/mp4'=>'mp4','video/webm'=>'webm','video/quicktime'=>'mov','audio/mpeg'=>'mp3','audio/mp3'=>'mp3','audio/wav'=>'wav','audio/x-wav'=>'wav','audio/ogg'=>'ogg','audio/flac'=>'flac','audio/x-flac'=>'flac','audio/mp4'=>'m4a'];
            if (!isset($types[$mime]) || !str_starts_with($mime,$type.'/')) throw new InvalidArgumentException('预设素材内容与媒体类型不匹配');
            $checksum=hash('sha256',$bytes); $key='media_'.substr($checksum,0,48);
            $existing=TenantConfig::where(['tenant_id'=>$this->tenant,'type'=>'aigc_canvas_template','name'=>$key])->value('value');
            $stored=$existing?json_decode($existing,true):null;
            if (!$stored) {
                $config=StorageConfigService::getEffectiveConfig($this->tenant);
                $dir='uploads/aigc_canvas/templates/'.$this->tenant;
                $temp=tempnam(sys_get_temp_dir(),'canvas_template_');
                if ($temp===false) throw new InvalidArgumentException('预设媒体临时文件创建失败');
                $tempFile=$temp.'.'.$types[$mime];
                try {
                    if (!rename($temp,$tempFile) || file_put_contents($tempFile,$bytes)!==strlen($bytes)) throw new InvalidArgumentException('预设媒体保存失败');
                    $driver=new Driver($config); $driver->setUploadFileByReal($tempFile,$checksum.'.'.$types[$mime]);
                    if (!$driver->upload($dir)) throw new InvalidArgumentException('预设媒体存储失败，请检查当前存储配置');
                } finally { @unlink($temp); @unlink($tempFile); }
                $stored=['uri'=>$dir.'/'.$checksum.'.'.$types[$mime],'storage_scope'=>$config['scope'],'storage_engine'=>$config['default'],'storage_domain'=>StorageConfigService::getStorageDomain($config),'checksum'=>$checksum,'mime_type'=>$mime,'file_size'=>strlen($bytes)];
                TenantConfig::create(['tenant_id'=>$this->tenant,'type'=>'aigc_canvas_template','name'=>$key,'value'=>json_encode($stored,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
            }
            $result=['url'=>self::resolve($stored),'file'=>$stored,'mimeType'=>$mime,'fileSize'=>strlen($bytes),'fileName'=>mb_substr((string)($input['fileName'] ?? $input['name'] ?? basename($path)),0,120)];
            if ($type==='image') { $size=@getimagesizefromstring($bytes); if ($size) { $result['width']=$size[0]; $result['height']=$size[1]; } }
            foreach (['width','height','duration'] as $key) if (!isset($result[$key]) && is_numeric($input[$key] ?? null) && $input[$key]>0) $result[$key]=(float)$input[$key];
            return $this->copied[$cacheKey]=$result;
        } catch (InvalidArgumentException $e) { throw $e; }
        catch (\Throwable $e) { throw new InvalidArgumentException('预设媒体保存失败，请检查素材是否仍可访问后重新发布',0,$e); }
    }
    public static function resolve(array $file): string
    {
        return FileService::getFileUrlByStorage($file['uri'],$file['storage_scope'],$file['storage_engine'],$file['storage_domain']);
    }

    public static function refresh(array $metadata,int $tenant): array
    {
        return CanvasTemplateMedia::hydrate($metadata,static function(array $file) use ($tenant): string {
            // Client-written project JSON may not grant access to another storage object.
            $key='media_'.substr((string)($file['checksum'] ?? ''),0,48);
            $stored=json_decode((string)TenantConfig::where(['tenant_id'=>$tenant,'type'=>'aigc_canvas_template','name'=>$key])->value('value'),true);
            if (!$stored || ($stored['uri'] ?? null)!==($file['uri'] ?? null)) throw new InvalidArgumentException('预设素材记录无效或不属于当前租户');
            return self::resolve($stored);
        });
    }
}
