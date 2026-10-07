<?php
namespace app\common\service;

use app\common\model\file\TenantFile;
use app\common\service\app\AppRegistryService;
use app\common\service\app\AppDisplayConfigService;
use InvalidArgumentException;
use think\facade\Db;
use app\common\model\tenant\Tenant;

/** Fixed seven-card configuration for the Imagine home only. No DIY or navigation mutations. */
final class PcHomeShortcutsService
{
    private const TYPE = 'pc_home_shortcuts';
    public const DEFAULT_CODES = ['aigc_short_drama', 'aigc_canvas', 'aigc_image', 'aigc_video', 'aigc_music', 'image_human', 'smart_clip'];
    private const PRESETS = ['drama', 'canvas', 'image', 'video', 'audio', 'avatar', 'edit'];
    private const ICONS = ['drama','canvas','image','video','audio','avatar','edit','skills','search'];

    private static function tenant(): int
    {
        $id = (int)request()->tenantId;
        if ($id <= 0) throw new InvalidArgumentException('缺少租户信息');
        return $id;
    }

    public static function catalog(): array
    {
        $tenant = self::tenant();
        $rows = [];
        foreach (AppRegistryService::frontendEntries($tenant, 'pc') as $entry) {
            $path = (string)($entry['path'] ?? '');
            if (!preg_match('~^/(?!/)[a-zA-Z0-9_/?.=&%-]+$~D', $path)) continue;
            $code = (string)$entry['app_code'];
            $display = AppDisplayConfigService::detail($tenant, $code);
            $rows[] = ['app_code'=>$code, 'entry_key'=>(string)$entry['entry_key'], 'path'=>$path,
                'title'=>(string)($display['title'] ?: $entry['name']), 'description'=>(string)($display['description'] ?? '')];
        }
        return $rows;
    }

    public static function defaults(): array
    {
        $cards = [];
        foreach (self::DEFAULT_CODES as $i => $code) {
            $cards[] = ['id'=>'card-'.($i+1), 'app_code'=>$code, 'entry_key'=>'', 'preset'=>self::PRESETS[$i],
                'copy'=>[], 'icon_name'=>self::PRESETS[$i], 'icon_id'=>0,
                'backgrounds'=>['common'=>self::background(), 'light'=>self::background('inherit'), 'dark'=>self::background('inherit')]];
        }
        return ['version'=>1, 'cards'=>$cards];
    }
    private static function background(string $mode = 'default'): array
    {
        return ['mode'=>$mode,'file_id'=>0,'poster_id'=>0,'position'=>'center','text_tone'=>'auto'];
    }
    private static function text($value, int $limit): string
    {
        if (!is_string($value) || mb_strlen($value) > $limit) throw new InvalidArgumentException('文案格式或长度不正确');
        return trim(strip_tags($value));
    }
    public static function normalize(array $input): array
    {
        $cards = $input['cards'] ?? null;
        if (!is_array($cards) || count($cards) !== 7 || array_keys($cards) !== range(0,6)) throw new InvalidArgumentException('金刚区必须保留 7 张卡片');
        $result = [];
        $ids = [];
        foreach ($cards as $card) {
            if (!is_array($card)) throw new InvalidArgumentException('卡片格式不正确');
            if (!is_array($card['copy'] ?? []) || !is_array($card['backgrounds'] ?? [])) throw new InvalidArgumentException('卡片配置格式不正确');
            $id = (string)($card['id'] ?? '');
            if (!preg_match('/^card-[1-7]$/D', $id) || in_array($id,$ids,true)) throw new InvalidArgumentException('卡片标识不正确');
            $ids[] = $id;
            $code = (string)($card['app_code'] ?? '');
            $key = (string)($card['entry_key'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]{0,100}$/D',$code) || !preg_match('/^[a-zA-Z0-9_-]{0,100}$/D',$key)) throw new InvalidArgumentException('请选择有效工具');
            $copy = [];
            foreach (['zh-CN','zh-TW','en'] as $locale) {
                if (!is_array($card['copy'][$locale] ?? [])) throw new InvalidArgumentException('文案格式不正确');
                foreach (['title'=>60,'description'=>180,'badge'=>16,'button'=>30] as $field=>$limit) {
                    $copy[$locale][$field] = self::text($card['copy'][$locale][$field] ?? '', $limit);
                }
            }
            $backgrounds = [];
            foreach (['common','light','dark'] as $theme) {
                $bg = $card['backgrounds'][$theme] ?? self::background($theme === 'common' ? 'default' : 'inherit');
                if (!is_array($bg) || !in_array($bg['mode'] ?? '', $theme === 'common' ? ['default','none','image','video'] : ['inherit','default','none','image','video'],true)) throw new InvalidArgumentException('背景类型不正确');
                $position = $bg['position'] ?? 'center';
                $tone = $bg['text_tone'] ?? 'auto';
                if (!in_array($position,['center','top','bottom','left','right'],true) || !in_array($tone,['auto','light','dark'],true)) throw new InvalidArgumentException('背景展示设置不正确');
                $backgrounds[$theme] = ['mode'=>$bg['mode'],'file_id'=>max(0,(int)($bg['file_id'] ?? 0)), 'poster_id'=>max(0,(int)($bg['poster_id'] ?? 0)), 'position'=>$position,'text_tone'=>$tone];
                if (in_array($bg['mode'],['image','video'],true) && !$backgrounds[$theme]['file_id']) throw new InvalidArgumentException('请选择背景素材');
            }
            $icon = $card['icon_name'] ?? 'skills';
            if (!in_array($icon,self::ICONS,true)) throw new InvalidArgumentException('图标不正确');
            $preset = $card['preset'] ?? '';
            $result[] = ['id'=>$id,'app_code'=>$code,'entry_key'=>$key,'preset'=>in_array($preset,self::PRESETS,true)?$preset:'', 'copy'=>$copy,'icon_name'=>$icon,'icon_id'=>max(0,(int)($card['icon_id'] ?? 0)), 'backgrounds'=>$backgrounds];
        }
        return ['version'=>1,'cards'=>$result];
    }
    private static function material(int $id, string $kind, bool $strict): string
    {
        if (!$id) return '';
        $file = TenantFile::where(['tenant_id'=>self::tenant(),'id'=>$id,'type'=>$kind === 'video' ? 20 : 10])->findOrEmpty();
        if ($file->isEmpty()) {
            if ($strict) throw new InvalidArgumentException('素材不存在、已删除或不属于当前租户');
            return '';
        }
        return FileService::getFileUrlByStorage((string)$file['uri'],(string)$file['storage_scope'],(string)$file['storage_engine'],(string)$file['storage_domain']);
    }
    private static function resolve(array $config, bool $strict = false): array
    {
        $catalog = self::catalog();
        foreach ($config['cards'] as &$card) {
            $entry = null;
            foreach ($catalog as $candidate) {
                if ($candidate['app_code'] === $card['app_code'] && ($card['entry_key'] === '' || $candidate['entry_key'] === $card['entry_key'])) { $entry = $candidate; break; }
            }
            // Inactive tools remain in their slot so taking an app off shelf never shifts the layout.
            $card['available'] = $entry !== null;
            $card['path'] = $entry['path'] ?? '';
            $card['tool_title'] = $entry['title'] ?? '';
            $card['tool_description'] = $entry['description'] ?? '';
            $card['icon_url'] = self::material($card['icon_id'],'image',$strict);
            foreach ($card['backgrounds'] as &$bg) {
                $bg['url'] = in_array($bg['mode'],['image','video'],true) ? self::material($bg['file_id'],$bg['mode'],$strict) : '';
                $bg['poster'] = $bg['mode'] === 'video' ? self::material($bg['poster_id'],'image',$strict) : '';
            }
            unset($bg);
        }
        unset($card);
        return $config;
    }
    public static function editor(): array
    {
        self::tenant();
        $stored = ConfigService::get(self::TYPE,'state',[]);
        return ['draft'=>self::resolve($stored['draft'] ?? $stored['published'] ?? self::defaults()),
            'published_at'=>(int)($stored['published_at'] ?? 0),'revision'=>(int)($stored['revision'] ?? 0),'catalog'=>self::catalog()];
    }
    public static function public(): ?array
    {
        self::tenant();
        $stored = ConfigService::get(self::TYPE,'state',[]);
        return isset($stored['published']) ? self::resolve($stored['published']) : null;
    }
    public static function save(array $input, bool $publish): array
    {
        self::tenant();
        if (!is_array($input['config'] ?? null)) throw new InvalidArgumentException('配置格式不正确');
        $config = self::normalize($input['config']);
        $resolved = self::resolve($config,true);
        return Db::transaction(function () use ($input, $publish, $config, $resolved) {
            // Serialize even the first write, before a config row exists.
            if (Tenant::where('id',self::tenant())->lock(true)->findOrEmpty()->isEmpty()) throw new InvalidArgumentException('租户不存在');
            $stored = ConfigService::get(self::TYPE,'state',[]);
            if ((int)($input['revision'] ?? -1) !== (int)($stored['revision'] ?? 0)) throw new InvalidArgumentException('配置已被其他管理员更新，请刷新后重试');
            // Allow legacy unavailable defaults to remain; newly selected tools must be on shelf.
            $previous = $stored['draft'] ?? $stored['published'] ?? self::defaults();
            foreach ($resolved['cards'] as $card) {
                if ($card['available']) continue;
                $old = array_values(array_filter($previous['cards'],fn($item)=>$item['id']===$card['id']))[0] ?? [];
                if (($old['app_code'] ?? '') !== $card['app_code'] || ($old['entry_key'] ?? '') !== $card['entry_key']) throw new InvalidArgumentException('所选工具未开通或已下架，请重新选择');
            }
            $stored['draft'] = $config;
            $stored['revision'] = (int)($stored['revision'] ?? 0)+1;
            if ($publish) { $stored['published']=$config; $stored['published_at']=time(); }
            ConfigService::set(self::TYPE,'state',$stored);
            return self::editor();
        });
    }
}
