<?php
namespace app\common\service\app\aigc_canvas;

use InvalidArgumentException;

/** Source-delivered presets. Contains no tenant database rows or platform editing UI. */
final class CanvasBuiltinPresetService
{
    public static function directory(): string { return dirname(__DIR__,4).'/apps/aigc_canvas/data/presets'; }
    public static function all(): array
    {
        $result=[];
        foreach (glob(self::directory().'/*.json') ?: [] as $path) {
            $entry=self::validate(json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR));
            if (isset($result[$entry['id']])) throw new InvalidArgumentException('内置预设标识重复');
            $result[$entry['id']]=$entry;
        }
        return $result;
    }
    public static function cards(): array
    {
        return array_values(array_map(static fn($entry)=>[
            'id'=>$entry['id'],'section'=>'template','title'=>$entry['title'],'category'=>$entry['category'],'description'=>$entry['description'],
            'enabled'=>1,'media_type'=>$entry['media_type'],'file_id'=>0,'poster_id'=>0,'url'=>$entry['media_type']==='video'?$entry['preview']:$entry['cover'],'poster'=>$entry['media_type']==='video'?$entry['cover']:'',
            'source'=>'builtin','readonly'=>true,'workflow_ready'=>true,'workflow_version'=>$entry['version'],'node_count'=>count($entry['graph']['nodes'])
        ],self::all()));
    }
    public static function graph(string $id): array
    {
        return self::all()[$id]['graph'] ?? throw new InvalidArgumentException('内置预设不存在，请更新预设目录');
    }
    public static function publicUrl(string $url): string
    {
        $p=parse_url($url);$host=strtolower($p['host'] ?? '');
        if (($p['scheme'] ?? '')!=='https' || !$host || isset($p['query']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass']) || isset($p['port']) || preg_match('/[\x00-\x20\\\\]/',$url)) throw new InvalidArgumentException('内置预设必须使用无临时签名的公网 HTTPS 媒体');
        if (in_array($host,['localhost','s.cn','x.cn','z.cn'],true) || preg_match('/\.(local|test|invalid|internal)$/',$host) || (filter_var($host,FILTER_VALIDATE_IP) && !\app\common\service\open_platform\OpenPolicy::isPublicIp($host))) throw new InvalidArgumentException('内置预设不能引用本机或内网媒体');
        return $url;
    }
    public static function validate(array $entry): array
    {
        if (($entry['schema_version'] ?? 0)!==1 || !preg_match('/^builtin-[a-z0-9_-]{1,56}$/D',(string)($entry['id'] ?? '')) || (int)($entry['version'] ?? 0)<1 || empty($entry['title']) || !is_array($entry['graph'] ?? null)) throw new InvalidArgumentException('内置预设定义无效');
        $mediaType=$entry['media_type'] ?? 'image';
        if (!in_array($mediaType,['image','video'],true) || ($mediaType==='video' && empty($entry['preview']))) throw new InvalidArgumentException('请选择图片或视频展示素材，视频预览不能为空');
        $graph=CanvasTemplateService::sanitize($entry['graph']['nodes'] ?? [],$entry['graph']['edges'] ?? [],static fn($type,$meta)=>CanvasTemplateMedia::capture($type,$meta,static fn($url,$kind,$row)=>['url'=>self::publicUrl($url),'file'=>[]]+array_intersect_key($row,array_flip(['width','height','duration','fileSize','mimeType','fileName']))));
        $clean=static function($value) use (&$clean) {
            if (!is_array($value)) return $value;
            foreach (['template_file','template_poster','model_id','market_model_id','market_sku_id','sku_id','file_id','fileId','asset_id'] as $key) unset($value[$key]);
            foreach ($value as $key=>$item) $value[$key]=$clean($item);
            return $value;
        };
        return ['schema_version'=>1,'id'=>$entry['id'],'version'=>(int)$entry['version'],'title'=>mb_substr(trim($entry['title']),0,80),'category'=>mb_substr((string)($entry['category'] ?? ''),0,30),'description'=>mb_substr((string)($entry['description'] ?? ''),0,500),'media_type'=>$mediaType,'preview'=>$mediaType==='video'?self::publicUrl($entry['preview']):'','cover'=>empty($entry['cover'])?'':self::publicUrl($entry['cover']),'graph'=>$clean($graph)];
    }
}
