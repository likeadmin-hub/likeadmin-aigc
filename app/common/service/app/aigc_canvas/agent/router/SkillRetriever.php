<?php

namespace app\common\service\app\aigc_canvas\agent\router;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\AigcCanvasSkillService;
use Exception;

final class SkillRetriever
{
    use RouterSupport;

    public static function resolveManualSkill(int $tenantId, array $params): array
    {
        $skillKey = trim((string)($params['skill_key'] ?? ''));
        $skillId = (int)($params['skill_id'] ?? 0);
        if ($skillKey === '' && $skillId <= 0) {
            return [];
        }
        try {
            $skill = AigcCanvasSkillService::resolveSkill($tenantId, $skillKey, $skillId);
            return !empty($skill) && (int)($skill['status'] ?? 0) === 1 ? AigcCanvasSkillService::formatSkill($skill, true) : [];
        } catch (Exception) {
            return [];
        }
    }

    public static function resolvePendingSkill(int $tenantId, array $pendingContext): array
    {
        $skillKey = (string)($pendingContext['skill_key'] ?? '');
        if ($skillKey === '') {
            return [];
        }
        $skill = AigcCanvasSkillService::resolveSkill($tenantId, $skillKey);
        return !empty($skill) && (int)($skill['status'] ?? 0) === 1 ? AigcCanvasSkillService::formatSkill($skill, true) : [];
    }

    public static function retrieve(int $tenantId, int $userId, string $content, array $context, array $pendingContext = []): array
    {
        $skills = AigcCanvasSkillService::routerSkills($tenantId);
        if ($skills === []) {
            return [];
        }

        $routerJson = self::matchWithLlm($tenantId, $userId, $content, $context, $skills, $pendingContext);
        $skillKey = (string)($routerJson['skill_key'] ?? '');
        $confidence = (float)($routerJson['confidence'] ?? 0);
        $matched = in_array($routerJson['matched'] ?? false, [true, 1, '1', 'true'], true);
        if ($matched && $skillKey !== '' && $confidence >= 0.72) {
            foreach ($skills as $skill) {
                if ((string)($skill['skill_key'] ?? '') === $skillKey && self::isCandidatePlausible($skill, $content, $context)) {
                    return ['skill' => $skill, 'router_json' => $routerJson];
                }
            }
        }

        return [];
    }

    public static function matchByRules(array $skills, string $content, array $context): array
    {
        $hasSelected = !empty($context['selected_elements']);
        $preferred = '';

        if ($hasSelected && self::containsAny($content, ['分析', '参考', '素材分析', '看一下', 'analysis', 'reference'])) {
            $preferred = 'asset_analysis';
        } elseif (self::containsAny($content, ['详情页', '详情图', '详情长图', '淘宝', '天猫', '京东', '亚马逊', '电商', '商品主图', '商品图', '卖点图', '主图', 'A+页面', 'a+页面', 'detail page'])) {
            $preferred = 'ecommerce_detail_page';
        } elseif (self::containsAny($content, ['脚本', '策划', '方案', '流程', '文案', '发布会', '计划', '提示词', 'copy', 'plan', 'proposal', 'script'])) {
            $preferred = 'script_planning';
        } elseif (self::containsAny($content, ['视频', '短片', '动画', '运镜', '镜头', 'video'])) {
            $preferred = 'video_generation';
        } elseif (self::containsAny($content, ['音乐', '音频', '配乐', '歌曲', 'bgm', 'audio', 'music'])) {
            $preferred = 'music_generation';
        } elseif (self::containsAny($content, ['海报', '活动海报', '招生海报', '促销海报', '大促', '横版海报', '竖版海报', 'poster'])) {
            $preferred = 'poster_design';
        } elseif (self::containsAny($content, ['图片', '图像', '插画', '照片', '封面', '视觉', '生图', '白底图', '品牌kv', 'kv', 'logo', '画', 'image'])) {
            $preferred = 'general_image';
        } elseif (self::containsAny($content, ['画布', '落地页', '直播间背景', '模块', '长图'])) {
            $preferred = 'creative_plan';
        } else {
            $preferred = 'general_chat';
        }

        foreach ($skills as $skill) {
            if ((string)($skill['skill_key'] ?? '') === $preferred) {
                return $skill;
            }
        }
        return [];
    }

    private static function matchWithLlm(int $tenantId, int $userId, string $content, array $context, array $skills, array $pendingContext): array
    {
        try {
            $result = AigcCanvasService::llmText($tenantId, $userId, [
                'content' => self::skillRouterPrompt($content, $context, $skills, $pendingContext),
                'system_prompt' => 'You are a router for an infinite-canvas creation agent. Output only compact JSON. Do not write creative content.',
                'max_tokens' => 1200,
                'source_app_code' => AigcCanvasService::APP_CODE,
                'source_type' => 'skill_router',
            ]);
            return self::parseJson((string)($result['content'] ?? ''));
        } catch (Exception) {
            return [];
        }
    }

    private static function skillRouterPrompt(string $content, array $context, array $skills, array $pendingContext): string
    {
        $items = array_map(static function (array $skill): array {
            return [
                'skill_key' => (string)($skill['skill_key'] ?? ''),
                'name' => (string)($skill['name'] ?? ''),
                'description' => (string)($skill['description'] ?? ''),
                'category' => (string)($skill['category'] ?? ''),
                'skill_type' => (string)($skill['skill_type'] ?? ''),
                'trigger_description' => (string)($skill['trigger_description'] ?? ''),
                'examples' => is_array($skill['examples_json'] ?? null) ? $skill['examples_json'] : [],
                'negative_examples' => is_array($skill['negative_examples_json'] ?? null) ? $skill['negative_examples_json'] : [],
                'required_slots' => is_array($skill['required_slots_json'] ?? null) ? $skill['required_slots_json'] : [],
                'optional_slots' => is_array($skill['optional_slots_json'] ?? null) ? $skill['optional_slots_json'] : [],
                'defaults' => is_array($skill['defaults_json'] ?? null) ? $skill['defaults_json'] : [],
                'tool_policy' => is_array($skill['tool_policy_json'] ?? null) ? $skill['tool_policy_json'] : [],
            ];
        }, $skills);

        return json_encode([
            'task' => 'match_skill_extract_slots',
            'output_schema' => [
                'matched' => 'boolean',
                'skill_key' => 'one skill_key from skills, or empty when matched=false',
                'intent' => implode('|', IntentClassifier::STANDARD_INTENTS),
                'confidence' => '0-1',
                'slots' => new \stdClass(),
                'missing_slots' => [],
                'next_action' => 'clarify|execute_tool|insert_workflow|chat',
                'clarify_question' => '',
                'reason' => 'short reason',
            ],
            'rules' => [
                'Never select a skill only because it is the sole available skill.',
                'For greetings, capability questions, normal conversation, planning, and copywriting without a matching listed skill, return matched=false.',
                'Do not default to image generation for planning, copywriting, analysis, or normal questions.',
                'If core required information is missing, set next_action=clarify and ask at most 3 concise questions.',
                'Do not ask for information already present in slots, defaults, pending_skill_context, selected_elements, or uploaded_references.',
            ],
            'skills' => $items,
            'pending_skill_context' => $pendingContext,
            'user_request' => $content,
            'context_used' => !empty($context['context_used']),
            'selected_elements_count' => count((array)($context['selected_elements'] ?? [])),
            'uploaded_references' => self::referenceSummaryForPrompt((array)($context['uploaded_references'] ?? [])),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function intentForRule(array $skill, string $content, array $context): string
    {
        $skillKey = (string)($skill['skill_key'] ?? '');
        if (self::containsAny($content, ['选中', '替换', '换成', '改成', '扩展', '背景']) && in_array($skillKey, ['general_image', 'poster_design'], true)) {
            return 'canvas_edit';
        }
        if (in_array($skillKey, ['general_image', 'poster_design', 'ecommerce_detail_page'], true)) {
            return 'generate_image';
        }
        if ($skillKey === 'video_generation') {
            return 'generate_video';
        }
        if ($skillKey === 'music_generation') {
            return 'generate_music';
        }
        if ($skillKey === 'creative_plan') {
            return 'canvas_design';
        }
        if ($skillKey === 'script_planning') {
            return self::containsAny($content, ['文案']) ? 'copywriting' : 'planning';
        }
        if ($skillKey === 'asset_analysis') {
            return 'asset_analysis';
        }
        return 'chat';
    }

    private static function isCandidatePlausible(array $skill, string $content, array $context): bool
    {
        if ((string)($skill['skill_key'] ?? '') !== 'ecommerce_detail_page') {
            return true;
        }
        if (self::containsAny($content, ['淘宝详情', '天猫详情', '电商详情', '商品详情', '产品详情', '详情长图', '详情页', '详情图', '主图', '卖点图', 'A+页面', 'detail page'])) {
            return true;
        }
        return !empty($context['uploaded_references']) && self::containsAny($content, ['电商', '商品视觉', '产品视觉', '整套视觉']);
    }
}
