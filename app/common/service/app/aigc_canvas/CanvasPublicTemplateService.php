<?php
namespace app\common\service\app\aigc_canvas;

use app\common\enum\AdminTerminalEnum;
use app\common\model\Config;
use app\common\model\app\App;
use app\common\model\file\File;
use app\common\model\tenant\Tenant;
use app\common\service\CanvasPortalService;
use app\common\service\FileService;
use InvalidArgumentException;
use think\facade\Db;

/** Platform publication is separate from tenant configuration and private source projects. */
final class CanvasPublicTemplateService
{
    private const TYPE='aigc_canvas_template';
    private static function authorize(): void
    {
        if (!AdminTerminalEnum::isPlatform()) throw new InvalidArgumentException('仅平台管理员可管理公共模板');
    }
    public static function lockCatalog(): void
    {
        if (App::where('code','aigc_canvas')->lock(true)->findOrEmpty()->isEmpty()) throw new InvalidArgumentException('无限画布应用未安装');
    }
    private static function state(): array
    {
        return json_decode((string)Config::where(['type'=>self::TYPE,'name'=>'catalog'])->value('value'),true) ?: ['cards'=>[],'revision'=>0];
    }
    private static function material(int $id, string $type, bool $strict): string
    {
        if (!$id) return '';
        $file=File::where(['id'=>$id,'type'=>$type==='video'?20:10])->findOrEmpty();
        if ($file->isEmpty() || !in_array((string)($file['storage_scope'] ?? ''),['','platform'],true)) {
            if ($strict) throw new InvalidArgumentException('请选择平台素材中心的有效素材');
            return '';
        }
        return FileService::getFileUrlByStorage((string)$file['uri'],(string)($file['storage_scope'] ?? 'platform'),(string)($file['storage_engine'] ?? ''),(string)($file['storage_domain'] ?? ''));
    }
    private static function resolve(array $cards, bool $public, bool $strict=false): array
    {
        if ($public) $cards=array_values(array_filter($cards,fn($card)=>!empty($card['enabled'])));
        foreach ($cards as &$card) {
            $card['url']=self::material((int)$card['file_id'],$card['media_type'],$strict);
            $card['poster']=self::material((int)$card['poster_id'],'image',$strict);
        }
        unset($card);
        return CanvasTemplateService::decorate($cards,$public,0);
    }
    public static function publicCards(): array { return self::resolve(self::state()['cards'],true); }
    public static function editor(): array
    {
        self::authorize();
        $state=self::state();
        return ['config'=>['cards'=>self::resolve($state['cards'],false)],'revision'=>(int)$state['revision']];
    }
    public static function sources(array $params): array
    {
        self::authorize();
        if (!empty($params['tenants'])) {
            $query=Tenant::where('disable',0);
            $keyword=trim((string)($params['keyword'] ?? ''));
            if ($keyword!=='') $query->whereLike('name|sn','%'.$keyword.'%');
            $count=(clone $query)->count();
            $page=max(1,(int)($params['page_no'] ?? 1));
            return ['lists'=>$query->field('id,name,sn')->order('id','desc')->page($page,20)->select()->toArray(),'count'=>$count];
        }
        $tenant=(int)($params['source_tenant_id'] ?? 0);
        if ($tenant<=0 || Tenant::where(['id'=>$tenant,'disable'=>0])->findOrEmpty()->isEmpty()) throw new InvalidArgumentException('请选择有效的来源租户');
        return CanvasTemplateService::sources($tenant,$params);
    }
    public static function publish(array $input): array
    {
        self::authorize();
        $rows=$input['config']['cards'] ?? null;
        if (!is_array($rows)) throw new InvalidArgumentException('模板配置格式不正确');
        $cards=CanvasPortalService::normalize(['hero'=>[],'cards'=>$rows])['cards'];
        foreach ($cards as $card) if ($card['section']!=='template') throw new InvalidArgumentException('公共模板仅支持工作流模板栏目');
        self::resolve($cards,false,true);
        return Db::transaction(function () use ($input,$rows,$cards) {
            self::lockCatalog();
            $state=self::state();
            if ((int)($input['revision'] ?? -1)!==(int)$state['revision']) throw new InvalidArgumentException('配置已被其他管理员更新，请刷新后重试');
            foreach ($rows as $row) if (!empty($row['refresh_workflow'])) {
                $tenant=(int)($row['source_tenant_id'] ?? 0);
                if ($tenant<=0 || Tenant::where(['id'=>$tenant,'disable'=>0])->findOrEmpty()->isEmpty()) throw new InvalidArgumentException('请选择有效的来源租户');
            }
            CanvasTemplateService::publish($rows,true);
            $value=json_encode(['cards'=>$cards,'revision'=>(int)$state['revision']+1],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if (strlen($value)>60000) throw new InvalidArgumentException('模板列表内容过大，请减少介绍文字');
            $where=['type'=>self::TYPE,'name'=>'catalog'];
            $row=Config::where($where)->findOrEmpty();
            if ($row->isEmpty()) Config::create($where+['value'=>$value]);
            else $row->save(['value'=>$value]);
            return self::editor();
        });
    }
}
