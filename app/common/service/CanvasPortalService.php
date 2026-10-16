<?php
namespace app\common\service;

use app\common\model\file\TenantFile;
use app\common\model\tenant\Tenant;
use InvalidArgumentException;
use think\facade\Db;

/** Presentation only. Files are explicitly published from the current tenant's material centre. */
final class CanvasPortalService
{
    private const TYPE = 'pc_canvas_portal';
    private static function tenant(): int
    {
        $id = (int)request()->tenantId;
        if ($id <= 0) throw new InvalidArgumentException('缺少租户信息');
        return $id;
    }
    public static function defaults(): array
    {
        $cards = [];
        foreach ([['创意情绪板','品牌设计'],['电影级镜头','电影'],['草图转效果图','设计'],['虚拟摄影棚','摄影'],['时尚人像','时尚服饰'],['商品场景','电商广告'],['二维转三维','设计'],['角色设计','电影'],['广告创意','电商广告'],['表情变化','摄影'],['场景设计','电影'],['创意海报','品牌设计']] as $i => $item) {
            $cards[] = ['id'=>'template-'.($i+1),'section'=>'template','title'=>$item[0],'category'=>$item[1], 'description'=>'连接素材、提示词与生成节点，探索可复用的创作流程。','enabled'=>1] + self::media();
        }
        return ['hero'=>self::media(), 'cards'=>$cards];
    }
    private static function media(): array { return ['media_type'=>'image','file_id'=>0,'poster_id'=>0]; }
    private static function text($value, int $limit): string
    {
        if (!is_string($value) || mb_strlen($value) > $limit) throw new InvalidArgumentException('文案格式或长度不正确');
        return trim(strip_tags($value));
    }
    private static function normalizeMedia(array $row): array
    {
        if (!in_array($row['media_type'] ?? 'image', ['image','video'], true)) throw new InvalidArgumentException('仅支持图片和视频素材');
        return ['media_type'=>$row['media_type'] ?? 'image', 'file_id'=>max(0,(int)($row['file_id'] ?? 0)), 'poster_id'=>max(0,(int)($row['poster_id'] ?? 0))];
    }
    public static function normalize(array $input): array
    {
        if (!is_array($input['hero'] ?? null) || !is_array($input['cards'] ?? null) || count($input['cards']) > 80) throw new InvalidArgumentException('首页配置格式不正确，最多80张卡片');
        $cards = []; $ids = [];
        foreach ($input['cards'] as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('卡片格式不正确');
            $id = (string)($row['id'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id) || isset($ids[$id])) throw new InvalidArgumentException('卡片标识不正确或重复');
            $ids[$id] = true;
            $title = self::text($row['title'] ?? '', 80);
            $section = $row['section'] ?? 'template';
            if (!$title || !in_array($section, ['template','inspiration'],true)) throw new InvalidArgumentException('请选择栏目并填写卡片标题');
            $cards[] = ['id'=>$id, 'section'=>$section, 'title'=>$title, 'category'=>self::text($row['category'] ?? '', 30), 'description'=>self::text($row['description'] ?? '',500), 'enabled'=>empty($row['enabled'])?0:1] + self::normalizeMedia($row);
        }
        return ['hero'=>self::normalizeMedia($input['hero']), 'cards'=>$cards];
    }
    private static function material(int $id, string $type, bool $strict): string
    {
        if (!$id) return '';
        $file = TenantFile::where(['tenant_id'=>self::tenant(),'id'=>$id,'type'=>$type==='video'?20:10])->findOrEmpty();
        if ($file->isEmpty()) {
            if ($strict) throw new InvalidArgumentException('素材不存在、已删除、类型不符或不属于当前租户');
            return '';
        }
        return FileService::getFileUrlByStorage((string)$file['uri'],(string)$file['storage_scope'],(string)$file['storage_engine'],(string)$file['storage_domain']);
    }
    private static function resolve(array $config, bool $strict = false, bool $public = false): array
    {
        if ($public) $config['cards'] = array_values(array_filter($config['cards'],fn($row)=>!empty($row['enabled'])));
        foreach ($config['cards'] as &$row) self::resolveMedia($row,$strict);
        unset($row);
        self::resolveMedia($config['hero'],$strict);
        return $config;
    }
    private static function resolveMedia(array &$row, bool $strict): void
    {
        $row['url'] = self::material($row['file_id'],$row['media_type'],$strict);
        $row['poster'] = self::material($row['poster_id'],'image',$strict);
    }
    public static function editor(): array
    {
        self::tenant();
        $state = ConfigService::get(self::TYPE,'state',[]);
        return ['config'=>self::resolve($state['config'] ?? self::defaults()),'revision'=>(int)($state['revision'] ?? 0)];
    }
    public static function public(): array
    {
        if ((int)request()->tenantId <= 0) return ['hero'=>self::media()+['url'=>'','poster'=>''],'cards'=>[]];
        $state = ConfigService::get(self::TYPE,'state',[]);
        return self::resolve($state['config'] ?? self::defaults(),false,true);
    }
    public static function sectionEditor(string $section): array
    {
        if (!in_array($section, ['hero', 'cards'], true)) throw new InvalidArgumentException('配置栏目不正确');
        $state = self::editor();
        return ['config'=>[$section=>$state['config'][$section]], 'revision'=>$state['revision']];
    }
    /** Each editor can change only its own section, under the same revision lock. */
    public static function saveSection(string $section, array $input): array
    {
        if (!in_array($section, ['hero', 'cards'], true) || !is_array($input['config'][$section] ?? null)) throw new InvalidArgumentException('配置栏目不正确');
        return Db::transaction(function () use ($section, $input) {
            if (Tenant::where('id', self::tenant())->lock(true)->findOrEmpty()->isEmpty()) throw new InvalidArgumentException('租户不存在');
            $state = ConfigService::get(self::TYPE, 'state', []);
            if ((int)($input['revision'] ?? -1) !== (int)($state['revision'] ?? 0)) throw new InvalidArgumentException('配置已被其他管理员更新，请刷新后重试');
            $changed = self::normalize(['hero'=>self::media(), 'cards'=>[], $section=>$input['config'][$section]]);
            self::resolve($changed, true);
            $config = $state['config'] ?? self::defaults();
            $config[$section] = $changed[$section];
            ConfigService::set(self::TYPE, 'state', ['config'=>$config, 'revision'=>(int)($state['revision'] ?? 0)+1]);
            return self::sectionEditor($section);
        });
    }
    public static function save(array $input): array
    {
        self::tenant();
        if (!is_array($input['config'] ?? null)) throw new InvalidArgumentException('配置格式不正确');
        $config = self::normalize($input['config']);
        self::resolve($config,true);
        return Db::transaction(function () use ($config,$input) {
            if (Tenant::where('id',self::tenant())->lock(true)->findOrEmpty()->isEmpty()) throw new InvalidArgumentException('租户不存在');
            $state = ConfigService::get(self::TYPE,'state',[]);
            if ((int)($input['revision'] ?? -1) !== (int)($state['revision'] ?? 0)) throw new InvalidArgumentException('配置已被其他管理员更新，请刷新后重试');
            ConfigService::set(self::TYPE,'state',['config'=>$config,'revision'=>(int)($state['revision'] ?? 0)+1]);
            return self::editor();
        });
    }
}
