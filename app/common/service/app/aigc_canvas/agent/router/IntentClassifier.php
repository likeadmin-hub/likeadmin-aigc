<?php

namespace app\common\service\app\aigc_canvas\agent\router;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use Exception;

final class IntentClassifier
{
    use RouterSupport;

    public const STANDARD_INTENTS = [
        'chat',
        'planning',
        'copywriting',
        'generate_image',
        'generate_video',
        'generate_music',
        'canvas_design',
        'canvas_edit',
        'workflow_insert',
        'follow_up_revision',
        'asset_analysis',
    ];

    public static function classify(int $tenantId, int $userId, array $params, string $content, array $context): array
    {
        $explicit = trim((string)($params['skill_code'] ?? $params['skill'] ?? ''));
        if ($explicit !== '' && $explicit !== 'agent_auto' && in_array($explicit, ['creative_plan', 'generate_image', 'generate_video', 'generate_music'], true)) {
            return [
                'intent' => $explicit,
                'skill_code' => $explicit,
                'confidence' => 1,
                'reason' => 'explicit_skill',
                'next_action' => $explicit === 'creative_plan' ? 'chat' : 'execute_tool',
            ];
        }

        if (self::isSimpleChatRequest($content)) {
            return [
                'intent' => 'chat',
                'skill_code' => 'chat',
                'confidence' => 1,
                'reason' => 'simple_chat_rule',
                'next_action' => 'local_reply',
                'reply' => self::simpleChatReply($content),
            ];
        }

        if (self::isCompoundPlanningMediaRequest($content)) {
            $mediaSkill = self::containsAny($content, ['视频', '短片', '动画', 'video']) ? 'generate_video' : 'generate_image';
            return [
                'intent' => 'compound_plan_media',
                'skill_code' => 'creative_plan',
                'media_skill_code' => $mediaSkill,
                'confidence' => 0.92,
                'reason' => 'compound_planning_media_rule',
                'next_action' => $mediaSkill === 'generate_video' ? 'compound_plan_video' : 'compound_plan_image',
            ];
        }

        $routed = self::classifyWithLlm($tenantId, $userId, $content, $context);
        if ($routed !== []) {
            return $routed;
        }

        $skillCode = self::resolveByRules($content);
        return [
            'intent' => $skillCode,
            'skill_code' => $skillCode,
            'confidence' => 0.55,
            'reason' => 'intent_rule_fallback',
            'next_action' => $skillCode === 'creative_plan' ? 'chat' : 'execute_tool',
        ];
    }

    public static function isSimpleChatRequest(string $content): bool
    {
        $text = trim(mb_strtolower($content, 'UTF-8'));
        $normalized = preg_replace('/[\s，。！？?,.!;:]+/u', '', $text) ?? $text;
        if ($normalized === '') {
            return false;
        }
        if (mb_strlen($normalized, 'UTF-8') <= 8 && in_array($normalized, [
            '你好', '您好', 'hello', 'hi', '在吗', '谢谢', '多谢', '好的', 'ok',
        ], true)) {
            return true;
        }
        return mb_strlen($normalized, 'UTF-8') <= 12
            && self::containsAny($normalized, ['你是谁', '能做什么', '怎么用']);
    }

    public static function simpleChatReply(string $content): string
    {
        if (self::containsAny($content, ['谢谢', '多谢', 'thanks', 'thank'])) {
            return '不客气。需要继续生成图片、视频、音乐，或调整画布内容时，直接告诉我就可以。';
        }
        if (self::containsAny($content, ['你是谁', '能做什么', '怎么用'])) {
            return '我是无限画布的 AI Design Agent，可以帮你理解需求、匹配 Skill、补齐信息，并通过算力市场模型API和应用API生成图片、视频和音乐。';
        }
        return '你好，我是无限画布的 AI Design Agent。请告诉我想生成或调整什么内容。';
    }

    private static function isCompoundPlanningMediaRequest(string $content): bool
    {
        $hasPlanning = self::containsAny($content, ['策划', '方案', '流程', '文案', '脚本', '计划', 'proposal', 'plan', 'copy', 'script']);
        if (!$hasPlanning) {
            return false;
        }
        $hasExplicitMediaAction = self::containsAny($content, ['并生成', '再生成', '同时生成', '顺便生成', '生成一张', '生成两张', '生成图片', '生成图', '生成视频']);
        $hasMediaTarget = self::containsAny($content, ['主视觉', '海报', 'kv', 'banner', '封面', '图片', '图像', '一张图', '视频', '短片', 'image', 'poster', 'video']);
        return $hasExplicitMediaAction && $hasMediaTarget;
    }

    private static function classifyWithLlm(int $tenantId, int $userId, string $content, array $context): array
    {
        try {
            $agentConfig = AigcCanvasService::agentConfig($tenantId);
            if (empty($agentConfig['router_enabled']) || empty($agentConfig['router_available']) || empty($agentConfig['router_model_code'])) {
                return [];
            }
            $result = AigcCanvasService::llmText($tenantId, $userId, [
                'content' => self::agentRouterPrompt($content, $context),
                'model_code' => (string)$agentConfig['router_model_code'],
                'system_prompt' => 'You are an intent router for an infinite-canvas creation agent. Output JSON only.',
                'max_tokens' => 1024,
                'source_app_code' => AigcCanvasService::APP_CODE,
                'source_type' => 'agent_router',
            ]);
            $json = self::parseJson((string)($result['content'] ?? ''));
            $intent = (string)($json['intent'] ?? '');
            $confidence = (float)($json['confidence'] ?? 0);
            if ($confidence < 0.55 || !in_array($intent, ['creative_plan', 'generate_image', 'generate_video', 'generate_music'], true)) {
                return [];
            }
            return [
                'intent' => $intent,
                'skill_code' => $intent,
                'confidence' => $confidence,
                'reason' => (string)($json['reason'] ?? 'llm_intent_router'),
                'next_action' => $intent === 'creative_plan' ? 'chat' : 'execute_tool',
            ];
        } catch (Exception) {
            return [];
        }
    }

    private static function resolveByRules(string $content): string
    {
        if (self::containsAny($content, ['视频', '短片', '动画', '分镜', '运镜', 'video'])) {
            return 'generate_video';
        }
        if (self::containsAny($content, ['音乐', '音频', '配乐', '歌曲', '旁白', 'audio', 'music', 'bgm'])) {
            return 'generate_music';
        }
        if (self::containsAny($content, ['方案', '策划', '文案', '脚本', '流程', '分析', '建议', 'plan', 'copy', 'script'])) {
            return 'creative_plan';
        }
        if (self::containsAny($content, ['图片', '图像', '海报', '插画', '照片', '封面', '视觉', '生图', '绘制', '设计', 'image', 'poster'])) {
            return 'generate_image';
        }
        return 'creative_plan';
    }

    private static function agentRouterPrompt(string $content, array $context): string
    {
        $selected = [];
        foreach (array_slice((array)($context['selected_elements'] ?? []), 0, 6) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $selected[] = [
                'type' => (string)($item['type'] ?? ''),
                'title' => (string)($item['title'] ?? ''),
                'prompt' => mb_substr((string)($item['prompt'] ?? $item['content'] ?? ''), 0, 120, 'UTF-8'),
            ];
        }
        return json_encode([
            'task' => 'route_canvas_agent_intent',
            'allowed_intents' => ['creative_plan', 'generate_image', 'generate_video', 'generate_music'],
            'rules' => [
                'creative_plan is for planning, copywriting, analysis, scripts, suggestions, and normal conversation.',
                'generate_image is for image/poster/photo/illustration/visual generation.',
                'generate_video is for video/animation/shot/camera-motion generation.',
                'generate_music is for music/audio/BGM/song generation.',
            ],
            'output_schema' => ['intent' => 'string', 'confidence' => '0-1', 'reason' => 'short Chinese reason'],
            'user_request' => $content,
            'canvas_summary' => mb_substr(\app\common\service\app\aigc_canvas\agent\memory\CanvasSnapshotBuilder::summaryText($context), 0, 400, 'UTF-8'),
            'selected_elements' => $selected,
            'uploaded_references' => self::referenceSummaryForPrompt((array)($context['uploaded_references'] ?? [])),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
