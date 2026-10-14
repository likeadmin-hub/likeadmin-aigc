<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

use app\common\model\app\aigc_llm\AigcLlmConfig;
use app\common\service\app\AppDisplayConfigService as Display;
use app\common\service\FileService;

/** Editable skill presentation and draft templates; executable tools stay fixed. */
final class AgentSkillConfigService
{
    private const DEFAULTS=[
        'speech'=>['name'=>'文字配音','category'=>'音频','tool'=>'speech_synthesis','description'=>'把正文朗读为语音，支持短文本和长文本配音。','prompt'=>'帮我把下面的正文生成配音，使用默认音色。请先确认需要朗读的正文，保留原文，并说明预计费用。','materials'=>'提供需要朗读的正文，最多10000字符；系统按正文长度选择可用服务，暂不支持指定或克隆音色。'],
        'fitting'=>['name'=>'AI 试衣','category'=>'图片','tool'=>'aigc_fitting','description'=>'将服装穿到指定模特身上，生成试衣效果图。','prompt'=>'使用 AI 试衣功能，帮我把服装图片中的衣服穿到模特身上，保留模特的脸、姿势和背景，生成一张试衣效果图。请先确认服装图和模特图，缺少素材时引导我补充。','materials'=>'需要服装图片和一张模特照片，可通过输入框的“添加素材”上传。请说明每张图片的用途。'],
        'image'=>['name'=>'图片创作','category'=>'图片','tool'=>'aigc_image','description'=>'根据画面描述或参考图创作图片。','prompt'=>'帮我创作图片，请根据我的用途和画面想法完善提示词，使用我选择的图片模型生成。','materials'=>'描述想要的画面；参考图片可选。'],
        'video'=>['name'=>'视频创作','category'=>'视频','tool'=>'aigc_video','description'=>'把文字创意或参考图片制作成视频。','prompt'=>'帮我创作视频，使用我选择的视频模型。请先确认画面内容、人物动作、镜头和时长；如果需要参考图，请引导我添加。','materials'=>'描述画面、动作和时长；图生视频需要参考图片。'],
        'music'=>['name'=>'音乐创作','category'=>'音频','tool'=>'aigc_music','description'=>'创作歌曲或纯音乐，可指定曲风和使用场景。','prompt'=>'帮我创作一段音乐。请先确认用途、曲风、时长，以及需要歌曲还是纯音乐，并说明预计费用。','materials'=>'描述音乐用途、曲风和时长；创作歌曲时可以提供歌词。'],
        'document'=>['name'=>'文档创作','category'=>'文本','description'=>'整理方案、流程或报告，并导出 Word 文档。','prompt'=>'帮我撰写一份结构清晰的文档，并导出 Word 文件。请先确认文档主题、用途和需要包含的内容。','materials'=>'说明文档主题和用途，也可以在输入框补充正文或资料。'],
        'script'=>['name'=>'剧本创作','category'=>'文本','description'=>'构思故事、人物、对白和分镜脚本。','prompt'=>'帮我创作剧本，请先确认题材、目标受众、时长和风格，再整理故事梗概、人物设定及分镜内容。','materials'=>'说明题材和创作目标；已有故事或人物设定可直接补充。'],
        'plan'=>['name'=>'创作方案','category'=>'文本','description'=>'把创意整理成清晰、可执行的创作方案。','prompt'=>'帮我制定一份创作方案，请先了解目标、受众和交付形式，再给出内容结构与执行步骤。','materials'=>'描述你的目标、受众和预期交付内容。']
    ];
    private const APPS = ['aigc_llm'=>'文本','aigc_image'=>'图片','aigc_fitting'=>'图片','aigc_video'=>'视频','aigc_music'=>'音频'];
    private const EDITABLE = ['name','description','prompt','materials','enabled','sort','images','videos','preview_type','video_poster'];

    private static function stored(int $tenant): array
    {
        $config = AigcLlmConfig::where('tenant_id', $tenant)->findOrEmpty();
        return $config->isEmpty() ? [] : (array)($config->config_json['agent_skills'] ?? []);
    }

    public static function detail(int $tenant): array
    {
        $saved = self::stored($tenant);
        $defaults = self::DEFAULTS;
        foreach ($saved as $id => $value) {
            if (!isset($defaults[$id]) && str_starts_with($id, 'custom_') && isset(self::APPS[$value['app_code'] ?? ''])) {
                $app = $value['app_code'];
                $defaults[$id] = ['name'=>'','description'=>'','prompt'=>'','materials'=>'','category'=>self::APPS[$app]];
                if ($app !== 'aigc_llm') $defaults[$id]['tool'] = $app;
            }
        }
        $items = []; $displays = [];
        foreach ($defaults as $id => $default) {
            $value = $saved[$id] ?? [];
            if (!empty($value['deleted'])) continue;
            $app = ($default['tool'] ?? '') === 'speech_synthesis' ? 'aigc_llm' : ($default['tool'] ?? 'aigc_llm');
            $display = $displays[$app] ??= Display::detail($tenant, $app);
            $item = ['id'=>$id,'app_code'=>$app,'builtin'=>isset(self::DEFAULTS[$id]),'enabled'=>1,'sort'=>0] + $default;
            if (isset(self::DEFAULTS[$id]['tool']) && $app !== 'aigc_llm') {
                $item['name'] = $display['title'] ?: $default['name'];
                $item['description'] = $display['description'] ?: $default['description'];
            }
            foreach (['name','description','prompt','materials','enabled','sort'] as $field) {
                if (array_key_exists($field, $value)) $item[$field] = $value[$field];
            }
            $item['images'] = $value['images'] ?? (isset(self::DEFAULTS[$id]) ? $display['skill_preview_images'] : []);
            $item['videos'] = $value['videos'] ?? (isset(self::DEFAULTS[$id]) ? $display['skill_preview_videos'] : []);
            $item['image_urls'] = array_map(static fn($uri)=>FileService::getFileUrl($uri), $item['images']);
            $item['video_urls'] = array_map(static fn($uri)=>FileService::getFileUrl($uri), $item['videos']);
            $item['cover_url'] = $display['cover_url'];
            $item['preview_type'] = $value['preview_type'] ?? ($item['videos'] ? 'video' : 'image');
            $item['video_poster'] = $value['video_poster'] ?? ($item['images'][0] ?? $display['cover_uri'] ?? '');
            $item['video_poster_url'] = $item['video_poster'] ? FileService::getFileUrl($item['video_poster']) : $display['cover_url'];
            $items[] = $item;
        }
        return $items;
    }

    public static function apply(int $tenant, array $params): ?array
    {
        $commands = array_intersect(['skill_configs','skill_create','skill_delete_id'], array_keys($params));
        if (!$commands) return null;
        if (count($commands) !== 1) throw new \InvalidArgumentException('请一次提交一个技能操作');
        if (array_key_exists('skill_configs', $params)) return self::normalize($tenant, $params['skill_configs']);
        if (array_key_exists('skill_create', $params)) {
            if (!is_array($params['skill_create']) || array_key_exists('id', $params['skill_create'])) throw new \InvalidArgumentException('新增技能格式不正确');
            if (count(self::detail($tenant)) >= 100) throw new \InvalidArgumentException('最多添加 100 个技能');
            $id = 'custom_' . bin2hex(random_bytes(12));
            return self::normalize($tenant, [['id'=>$id] + $params['skill_create']], $id);
        }
        $id = $params['skill_delete_id'];
        if (!is_string($id) || !in_array($id, array_column(self::detail($tenant),'id'), true)) throw new \InvalidArgumentException('技能不存在');
        $result = self::stored($tenant);
        if (isset(self::DEFAULTS[$id])) $result[$id] = ['deleted'=>true];
        else unset($result[$id]);
        return $result;
    }

    public static function normalize(int $tenant, mixed $rows, string $newId = ''): array
    {
        if (!is_array($rows) || count($rows)>100) throw new \InvalidArgumentException('技能配置格式不正确');
        $result = self::stored($tenant);
        $current = array_column(self::detail($tenant), null, 'id');
        foreach ($current as $id=>$item) $result[$id] = array_intersect_key($item, array_flip(array_merge(self::EDITABLE,['app_code'])));
        $seen = [];
        foreach ($rows as $row) {
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            if (!is_string($id) || (!isset($current[$id]) && $id !== $newId) || isset($seen[$id])) throw new \InvalidArgumentException('技能不存在或重复');
            $seen[$id] = true;
            if (array_diff(array_keys($row), array_merge(['id','app_code'],self::EDITABLE))) throw new \InvalidArgumentException('不支持修改此技能设置');
            if ($id === $newId) $result[$id] = ['name'=>'','description'=>'','prompt'=>'','materials'=>'','enabled'=>1,'sort'=>0,'images'=>[],'videos'=>[]];
            if (array_key_exists('app_code',$row)) {
                if (!is_string($row['app_code']) || !isset(self::APPS[$row['app_code']])) throw new \InvalidArgumentException('请选择已接入的应用');
                if (isset(self::DEFAULTS[$id]) && $row['app_code'] !== $current[$id]['app_code']) throw new \InvalidArgumentException('内置技能关联应用不可修改');
                $result[$id]['app_code'] = $row['app_code'];
            }
            foreach (['name'=>80,'description'=>500,'prompt'=>4000,'materials'=>1000] as $field=>$limit) {
                if (!array_key_exists($field,$row)) continue;
                if (!is_string($row[$field]) || mb_strlen($row[$field])>$limit || (in_array($field,['name','prompt'],true) && trim($row[$field])==='')) throw new \InvalidArgumentException('技能名称、提示词或说明格式不正确');
                $result[$id][$field] = trim($row[$field]);
            }
            if (array_key_exists('enabled',$row)) {
                if (!in_array($row['enabled'],[0,1,'0','1'],true)) throw new \InvalidArgumentException('技能状态无效');
                $result[$id]['enabled'] = (int)$row['enabled'];
            }
            if (array_key_exists('sort',$row)) {
                if ((!is_int($row['sort']) && !(is_string($row['sort']) && preg_match('/^-?[0-9]{1,4}$/D', $row['sort']))) || abs((int)$row['sort'])>9999) throw new \InvalidArgumentException('技能排序无效');
                $result[$id]['sort'] = (int)$row['sort'];
            }
            foreach (['images'=>5,'videos'=>3] as $field=>$limit) if (array_key_exists($field,$row)) $result[$id][$field] = Display::normalizeSkillMedia($row[$field],$limit);
            if (array_key_exists('preview_type',$row)) {
                if (!in_array($row['preview_type'],['image','video'],true)) throw new \InvalidArgumentException('请选择图片或视频展示类型');
                $result[$id]['preview_type'] = $row['preview_type'];
            }
            if (array_key_exists('video_poster',$row)) {
                if (!is_string($row['video_poster'])) throw new \InvalidArgumentException('视频占位图格式不正确');
                $result[$id]['video_poster'] = Display::normalizeSkillMedia([$row['video_poster']],1)[0] ?? '';
            }
            if (isset($row['preview_type'])) {
                if ($row['preview_type'] === 'video') {
                    if (!$result[$id]['videos'] || empty($result[$id]['video_poster'])) throw new \InvalidArgumentException('请选择展示视频和视频加载占位图');
                    $result[$id]['images'] = [];
                } else {
                    $result[$id]['videos'] = [];
                    $result[$id]['video_poster'] = '';
                }
            }
            if ($id === $newId && (!$result[$id]['name'] || !$result[$id]['prompt'] || !isset($result[$id]['app_code']))) throw new \InvalidArgumentException('请填写技能名称、关联应用和回填提示词');
        }
        return $result;
    }

    public static function forTools(int $tenant, array $tools): array
    {
        return array_values(array_filter(self::detail($tenant), static fn($skill)=>!isset($skill['tool']) || in_array($skill['tool'],$tools,true)));
    }
}
