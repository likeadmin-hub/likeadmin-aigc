<?php
namespace app\common\service\app\aigc_canvas;

use app\common\enum\AdminTerminalEnum;
use InvalidArgumentException;

/** Explicit installation-owner authoring; ordinary tenant template APIs remain isolated. */
final class CanvasBuiltinPresetSourceService
{
    public static function capability(int $tenant): array
    {
        $admin=request()->adminInfo ?? [];
        $allowed=(int)config('canvas_presets.source_author_tenant_id',0);
        if ($tenant<1 || $tenant!==(int)request()->tenantId || !AdminTerminalEnum::isTenant() || $allowed<1 || $allowed!==$tenant || (int)($admin['tenant_id'] ?? 0)!==$tenant || (int)($admin['root'] ?? 0)!==1 || (int)($admin['admin_id'] ?? 0)<1) return ['enabled'=>false,'reason'=>'一键保存仅对安装维护者指定的制作租户超级管理员开放'];
        if (getenv('LIKEADMIN_IMMUTABLE_ASSETS')==='1') return ['enabled'=>false,'reason'=>'当前安装使用只读源码，请使用导出下载'];
        // The catalog may never be redirected to another filesystem location by a client or symlink.
        if (realpath(CanvasBuiltinPresetService::directory())!==realpath(root_path()).'/app/apps/aigc_canvas/data/presets') return ['enabled'=>false,'reason'=>'内置预设源码目录不可写，请检查安装目录'];
        try { (new CanvasBuiltinPresetSourceStore(CanvasBuiltinPresetService::directory(),root_path().'runtime/canvas-presets/source-write'))->assertWritable(); }
        catch (InvalidArgumentException $e) { return ['enabled'=>false,'reason'=>$e->getMessage()]; }
        return ['enabled'=>true,'reason'=>''];
    }

    public static function save(int $tenant,array $input): array
    {
        $capability=self::capability($tenant);
        if (!$capability['enabled']) throw new InvalidArgumentException($capability['reason']);
        $requestId=(string)($input['request_id'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_-]{16,96}$/D',$requestId)) throw new InvalidArgumentException('保存请求标识无效，请重新打开制作窗口');
        $normalized=[];
        foreach (['source','template_id','source_project_id','source_page_id','target','builtin_id','title','category','description','cover_file_id','preview_media_type','preview_file_id','preview_poster_id','expected_version','confirm_public_media'] as $key) $normalized[$key]=$input[$key] ?? '';
        foreach (['source_project_id','cover_file_id','preview_file_id','preview_poster_id','expected_version'] as $key) $normalized[$key]=(int)$normalized[$key];
        $normalized['confirm_public_media']=in_array($normalized['confirm_public_media'],[true,1,'1'],true);
        $admin=(int)request()->adminInfo['admin_id'];
        $key=hash('sha256',$tenant.':'.$admin.':'.$requestId);
        $fingerprint=hash('sha256',json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $store=new CanvasBuiltinPresetSourceStore(CanvasBuiltinPresetService::directory(),root_path().'runtime/canvas-presets/source-write');
        return $store->save($key,$fingerprint,(string)($input['target'] ?? 'new'),(string)($input['builtin_id'] ?? ''),(int)($input['expected_version'] ?? 0),static fn()=>CanvasBuiltinPresetExporter::export($tenant,$input));
    }
}
