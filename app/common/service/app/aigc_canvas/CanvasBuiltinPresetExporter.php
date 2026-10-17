<?php
namespace app\common\service\app\aigc_canvas;

use app\common\model\file\TenantFile;
use app\common\service\CanvasPortalService;
use app\common\service\open_platform\OpenSafeHttp;
use app\common\service\storage\{Driver,StorageConfigService};
use InvalidArgumentException;

/** Exports a portable definition. Never writes the installed source catalog or tenant catalog. */
final class CanvasBuiltinPresetExporter
{
    private static function tenant(int $tenant): void
    {
        if ($tenant<1 || $tenant!==(int)request()->tenantId) throw new InvalidArgumentException('租户上下文不匹配');
    }

    private static function published(int $tenant,string $id): array
    {
        self::tenant($tenant);
        $card=null;
        foreach (CanvasPortalService::editor()['config']['cards'] as $row) if ($row['id']===$id) $card=$row;
        if (!$card || !$card['workflow_ready']) throw new InvalidArgumentException('请选择当前租户已经发布的工作流预设');
        $graph=CanvasTemplateService::publishedSnapshot($tenant,$id);
        if (empty($graph['media_schema'])) throw new InvalidArgumentException('请先从原画布重新发布，以保留媒体素材');
        return [$card,$graph];
    }

    private static function storage(int $tenant): array
    {
        $config=StorageConfigService::getEffectiveConfig($tenant);
        $engine=$config['engine'][$config['default']] ?? [];
        if ($config['default']==='local' || !empty($engine['private_access']) || !empty($engine['private'])) throw new InvalidArgumentException('内置示例媒体需要公开的对象存储/CDN，请先配置后导出');
        CanvasBuiltinPresetService::publicUrl(rtrim(StorageConfigService::getStorageDomain($config),'/').'/uploads/aigc_canvas/builtin/check');
        return $config;
    }

    /** HTTP entry: identity/version are computed here, never accepted from the browser. */
    public static function export(int $tenant,array $input): array
    {
        self::tenant($tenant);
        // The application Request's trim filter turns a JSON boolean true into "1".
        if (!in_array($input['confirm_public_media'] ?? false,[true,1,'1'],true)) throw new InvalidArgumentException('请确认所选媒体可作为公开示例后再导出');
        $title=trim((string)($input['title'] ?? ''));
        if ($title==='' || mb_strlen($title)>80) throw new InvalidArgumentException('请填写80字以内的预设名称');
        $mode=$input['target'] ?? 'new';
        if ($mode==='update') {
            $entry=CanvasBuiltinPresetService::all()[(string)($input['builtin_id'] ?? '')] ?? null;
            if (!$entry) throw new InvalidArgumentException('请选择要更新的内置预设');
            $id=$entry['id'];$version=$entry['version']+1;
        } elseif ($mode==='new') { $id='builtin-'.bin2hex(random_bytes(8));$version=1; }
        else throw new InvalidArgumentException('预设导出方式无效');
        $config=self::storage($tenant);
        $source=$input['source'] ?? 'canvas';
        if ($source==='template') [$card,$graph]=self::published($tenant,(string)($input['template_id'] ?? ''));
        elseif ($source==='canvas') {
            $graph=CanvasTemplateService::capturePage($tenant,(int)($input['source_project_id'] ?? 0),(string)($input['source_page_id'] ?? ''));
            $card=['media_type'=>'image','file_id'=>0,'poster_id'=>0];
        } else throw new InvalidArgumentException('请选择画布或已发布模板作为来源');
        $card['title']=$title;
        foreach (['category'=>30,'description'=>500] as $key=>$max) {
            $value=(string)($input[$key] ?? $card[$key] ?? '');
            if (mb_strlen($value)>$max) throw new InvalidArgumentException('分类或介绍超出长度限制');
            $card[$key]=$value;
        }
        if (array_key_exists('cover_file_id',$input)) { $card['media_type']='image';$card['file_id']=max(0,(int)$input['cover_file_id']); }
        return self::build($tenant,$card,$graph,$id,$version,$config);
    }

    /** CLI compatibility; choosing an explicit source ID does not install or replace anything. */
    public static function exportPublished(int $tenant,string $template,string $id,?string $title=null,int $version=1,bool $write=true): array
    {
        [$card,$graph]=self::published($tenant,$template);
        if ($title!==null) $card['title']=$title;
        if (!preg_match('/^builtin-[a-z0-9_-]{1,56}$/D',$id) || $version<1) throw new InvalidArgumentException('内置预设标识或版本无效');
        $config=self::storage($tenant);
        if (!$write) return ['id'=>$id,'title'=>$card['title'],'nodes'=>count($graph['nodes']),'storage'=>$config['default'],'ready'=>true];
        return self::build($tenant,$card,$graph,$id,$version,$config);
    }

    private static function build(int $tenant,array $card,array $graph,string $id,int $version,array $config): array
    {
        $copies=[];$total=0;
        $copy=static function(array $file) use (&$copies,&$total,$config,$id): string {
            $checksum=(string)($file['checksum'] ?? '');
            if (!preg_match('/^[a-f0-9]{64}$/D',$checksum)) throw new InvalidArgumentException('预设素材记录不完整，请从画布重新导出');
            if (isset($copies[$checksum])) return $copies[$checksum];
            if (count($copies)>=100) throw new InvalidArgumentException('内置预设最多包含100份素材');
            $image=str_starts_with($file['mime_type'] ?? '','image/');
            $bytes=OpenSafeHttp::get(CanvasTemplateMediaStorage::resolve($file),$image?20971520:104857600,35)['body'];
            $total+=strlen($bytes);
            if ($total>268435456) throw new InvalidArgumentException('内置预设媒体总量超过256MB');
            if (!hash_equals($checksum,hash('sha256',$bytes))) throw new InvalidArgumentException('预设素材内容已改变，请从画布重新制作后导出');
            $ext=strtolower(pathinfo($file['uri'],PATHINFO_EXTENSION));
            if (!preg_match('/^(png|jpg|jpeg|webp|gif|avif|mp4|webm|mov|mp3|wav|ogg|flac|m4a)$/D',$ext)) throw new InvalidArgumentException('示例媒体类型不受支持');
            $dir='uploads/aigc_canvas/builtin/'.$id;$name=$checksum.'.'.$ext;
            $url=CanvasBuiltinPresetService::publicUrl(rtrim(StorageConfigService::getStorageDomain($config),'/').'/'.$dir.'/'.$name);
            $temp=tempnam(sys_get_temp_dir(),'canvas_builtin_');
            if ($temp===false) throw new InvalidArgumentException('无法创建素材临时文件');
            $local=$temp.'.'.$ext;
            try {
                if (!rename($temp,$local) || file_put_contents($local,$bytes)!==strlen($bytes)) throw new InvalidArgumentException('示例媒体临时保存失败');
                $driver=new Driver($config);$driver->setUploadFileByReal($local,$name);
                if (!$driver->upload($dir)) throw new InvalidArgumentException('内置示例媒体上传失败，请检查存储配置');
            } finally { @unlink($temp);@unlink($local); }
            // A public-storage setting alone does not prove the bucket/object permits anonymous reads.
            $public=OpenSafeHttp::get($url,$image?20971520:104857600,35)['body'];
            if (!hash_equals($checksum,hash('sha256',$public))) throw new InvalidArgumentException('示例媒体无法公开访问，请检查对象存储/CDN配置');
            return $copies[$checksum]=$url;
        };
        try {
            $coverId=(int)($card['media_type']==='video'?($card['poster_id'] ?? 0):($card['file_id'] ?? 0));
            if ($coverId) {
                $file=TenantFile::where(['tenant_id'=>$tenant,'id'=>$coverId,'type'=>10])->findOrEmpty();
                if ($file->isEmpty()) throw new InvalidArgumentException('封面不存在或不属于当前租户');
                $retained=(new CanvasTemplateMediaStorage($tenant))->copy($file['uri'],'image');
                $card['cover']=$copy($retained['file']);
            }
            $preset=self::definition($card,$graph,$id,$version,$copy);
            return ['filename'=>$id.'.json','preset'=>$preset,'media_count'=>count($copies)];
        } catch (InvalidArgumentException $e) { throw $e; }
        catch (\Throwable $e) { throw new InvalidArgumentException('导出失败，请确认素材和公开对象存储可访问后重试',0,$e); }
    }

    /** Portable whitelist conversion shared by the HTTP and CLI export paths. */
    public static function definition(array $card,array $graph,string $id,int $version,callable $copy): array
    {
        $walk=static function(array $value) use (&$walk,$copy): array {
            if (isset($value['template_file'])) $value['url']=$copy($value['template_file']);
            if (isset($value['template_poster'])) $value['poster']=$copy($value['template_poster']);
            foreach (['template_file','template_poster','model_id','market_model_id','market_sku_id','sku_id','fileId','file_id','asset_id'] as $key) unset($value[$key]);
            foreach ($value as $key=>$item) if (is_array($item)) $value[$key]=$walk($item);
            return $value;
        };
        foreach ($graph['nodes'] as &$node) {
            $node['metadata']=CanvasTemplateMedia::hydrate($walk($node['metadata']),static fn($file)=>throw new InvalidArgumentException('素材尚未导出'));
            foreach (['model','channel','model_code'] as $key) if (isset($node['metadata'][$key]) && ctype_digit((string)$node['metadata'][$key])) unset($node['metadata'][$key]);
            foreach (array_keys($node['metadata']['generationParameters'] ?? []) as $key) if (ctype_digit((string)$key)) unset($node['metadata']['generationParameters'][$key]);
        }
        unset($node);
        $cover=$card['cover'] ?? '';
        if (!$cover) foreach ($graph['nodes'] as $node) {
            if ($node['type']==='image' && !empty($node['metadata']['url'])) { $cover=$node['metadata']['url'];break; }
            if ($node['type']==='video' && !empty($node['metadata']['poster'])) $cover=$node['metadata']['poster'];
        }
        return CanvasBuiltinPresetService::validate(['schema_version'=>1,'id'=>$id,'version'=>$version,'title'=>$card['title'],'category'=>$card['category'] ?? '','description'=>$card['description'] ?? '','cover'=>$cover,'graph'=>$graph]);
    }
}
