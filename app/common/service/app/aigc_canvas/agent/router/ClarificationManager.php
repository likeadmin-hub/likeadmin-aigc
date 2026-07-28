<?php

namespace app\common\service\app\aigc_canvas\agent\router;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\agent\planning\EcommerceDetailSectionPlanner;
use Exception;

final class ClarificationManager
{
    use RouterSupport;

    public static function normalizePendingSkillContext($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }
        return [
            'skill_key' => (string)($value['skill_key'] ?? ''),
            'skill_id' => (int)($value['skill_id'] ?? 0),
            'intent' => (string)($value['intent'] ?? ''),
            'slots' => is_array($value['slots'] ?? null) ? $value['slots'] : [],
            'missing_slots' => is_array($value['missing_slots'] ?? null) ? $value['missing_slots'] : [],
            'confirmation_required' => !empty($value['confirmation_required']),
            'detail_sections' => is_array($value['detail_sections'] ?? null) ? $value['detail_sections'] : [],
            'section_count' => (int)($value['section_count'] ?? 0),
            'count_reason' => (string)($value['count_reason'] ?? ''),
            'original_request' => (string)($value['original_request'] ?? ''),
            'uploaded_references' => is_array($value['uploaded_references'] ?? null) ? $value['uploaded_references'] : [],
        ];
    }

    public static function buildPendingContext(array $skill, array $slots, array $missing, bool $needsConfirmation, string $content, array $context, array $pendingContext): array
    {
        if (empty($missing) && !$needsConfirmation) {
            return [];
        }
        return [
            'skill_key' => (string)($skill['skill_key'] ?? ''),
            'skill_id' => (int)($skill['id'] ?? 0),
            'intent' => SlotFiller::inferIntentFromSkill($skill),
            'slots' => $slots,
            'missing_slots' => $missing,
            'confirmation_required' => $needsConfirmation,
            'detail_sections' => (array)($slots['detail_sections'] ?? []),
            'section_count' => (int)($slots['section_count'] ?? 0),
            'count_reason' => (string)($slots['count_reason'] ?? ''),
            'original_request' => trim((string)($pendingContext['original_request'] ?? '')) ?: $content,
            'uploaded_references' => array_values(array_merge(
                (array)($pendingContext['uploaded_references'] ?? []),
                (array)($context['uploaded_references'] ?? [])
            )),
        ];
    }

    public static function resolveQuestion(int $tenantId, int $userId, array $skill, array $missing, string $content, array $slots, array $context, array $pendingContext = [], array $routerJson = []): string
    {
        $contextualQuestion = self::contextualQuestion($skill, $missing, $slots, $context);
        $routerQuestion = self::normalizeQuestion((string)($routerJson['clarify_question'] ?? ''));
        if (self::isUsefulQuestion($routerQuestion)) {
            return $routerQuestion;
        }

        try {
            $params = [
                'content' => self::questionPrompt($skill, $missing, $content, $slots, $context, $pendingContext),
                'system_prompt' => 'You write one contextual clarification for a Chinese infinite-canvas creation agent. Output only user-facing text.',
                'max_tokens' => 500,
                'source_app_code' => AigcCanvasService::APP_CODE,
                'source_type' => 'skill_clarification',
            ];
            $agentConfig = AigcCanvasService::agentConfig($tenantId);
            if (!empty($agentConfig['router_available']) && !empty($agentConfig['router_model_code'])) {
                $params['model_code'] = (string)$agentConfig['router_model_code'];
            }
            $result = AigcCanvasService::llmText($tenantId, $userId, $params);
            $question = self::normalizeQuestion((string)($result['content'] ?? ''));
            if (self::isUsefulQuestion($question)) {
                return $question;
            }
        } catch (Exception) {
        }

        return $contextualQuestion !== '' ? $contextualQuestion : self::policyQuestion($skill, $missing);
    }

    public static function confirmationMessage(array $slots, array $context = []): string
    {
        $sections = EcommerceDetailSectionPlanner::normalizeSections((array)($slots['detail_sections'] ?? []));
        $count = count($sections);
        $reason = (string)($slots['count_reason'] ?? 'agent_recommended');
        $reasonText = $reason === 'explicit_numbered_sections'
            ? '已按你提供的分段识别'
            : ($reason === 'explicit_requested_count' ? '已按你指定的数量规划' : '已根据商品信息自动规划');
        $lines = ["{$reasonText} {$count} 张独立详情图："];
        foreach ($sections as $section) {
            $index = (int)($section['section_index'] ?? 0);
            $title = (string)($section['title'] ?? '详情区块');
            $summary = mb_substr(trim((string)($section['purpose'] ?? $section['narrative'] ?? '')), 0, 54, 'UTF-8');
            $lines[] = "{$index}. {$title}" . ($summary !== '' ? "：{$summary}..." : '');
        }
        $referenceCount = count((array)($context['uploaded_references'] ?? []));
        if ($referenceCount > 0) {
            $lines[] = "参考素材：{$referenceCount} 个，会作为每个任务的独立参考参数传入。";
        }
        $lines[] = '确认后开始生成，同时最多提交 5 张。请回复“确认生成”，或继续说明需要调整的内容。';
        return implode("\n", $lines);
    }

    private static function contextualQuestion(array $skill, array $missing, array $slots, array $context = []): string
    {
        $key = (string)($skill['skill_key'] ?? '');
        if ($key === 'ecommerce_detail_page' && in_array('product_info', $missing, true)) {
            return self::ecommerceProductQuestion($slots, $context);
        }
        if ($key === 'ecommerce_detail_page' && in_array('selling_points', $missing, true)) {
            return '我已经收到商品信息。请再补充至少一个核心卖点，例如功能优势、材质特点、适用人群或使用效果；确认后我再生成详情页，不会提前提交任务。';
        }
        if ($key === 'general_image' && in_array('visual_subject', $missing, true)) {
            return '还差画面主体：你想生成什么内容？可以补充主体、场景、风格和用途，我再继续生成。';
        }
        if ($key === 'video_generation' && in_array('video_subject', $missing, true)) {
            return '还差视频内容：你想生成什么画面或动作？如果是图生视频，请选择或上传参考图。';
        }
        if ($key === 'music_generation' && in_array('music_subject', $missing, true)) {
            return '还差音乐方向：请补充用途、情绪、风格或参考类型，我再继续生成。';
        }
        return '';
    }

    private static function ecommerceProductQuestion(array $slots, array $context): string
    {
        $platform = self::labelForSlotValue('platform', (string)($slots['platform'] ?? 'taobao'));
        $quantity = max(1, (int)($slots['section_count'] ?? $slots['quantity'] ?? 5));
        $hasReferences = !empty($context['uploaded_references']) || !empty($context['selected_elements']);
        $referenceLine = $hasReferences
            ? '- 参考素材如何使用？我已经收到参考图，也可以继续说明要保留的产品外观、角度或风格。'
            : '- 是否有参考图或风格要求？有商品图可以直接上传，也可以描述想要的视觉风格。';
        return implode("\n", [
            "我理解你想设计一套包含 {$quantity} 个区块的{$platform}商品详情页，但还缺少核心商品信息。请补充：",
            '',
            '- 产品是什么？例如蓝牙耳机、护肤品、服装、家居用品等。',
            '- 需要展示哪些内容？例如卖点、功能特点、细节特写、使用场景、规格说明或对比图。',
            $referenceLine,
            '- 如果有品牌色、目标人群或风格要求，也可以一起说明。',
            '',
            '你补充这些信息后，我再提交生成任务并自动插入画布。',
        ]);
    }

    private static function questionPrompt(array $skill, array $missing, string $content, array $slots, array $context, array $pendingContext): string
    {
        return json_encode([
            'task' => 'write_contextual_clarification',
            'language' => 'Simplified Chinese',
            'rules' => [
                'Ask only for missing core information. Do not create a generation task.',
                'Mention what is already understood when helpful.',
                'Do not ask for values already present in extracted_slots, defaults, pending_skill_context, or selected_elements.',
                'Ask at most 3 short questions. No JSON, table, title, or code fence.',
            ],
            'skill' => [
                'skill_key' => (string)($skill['skill_key'] ?? ''),
                'name' => (string)($skill['name'] ?? ''),
                'description' => (string)($skill['description'] ?? ''),
                'required_slots' => is_array($skill['required_slots_json'] ?? null) ? $skill['required_slots_json'] : [],
                'optional_slots' => is_array($skill['optional_slots_json'] ?? null) ? $skill['optional_slots_json'] : [],
                'defaults' => is_array($skill['defaults_json'] ?? null) ? $skill['defaults_json'] : [],
            ],
            'user_request' => $content,
            'extracted_slots' => $slots,
            'missing_slots' => array_values($missing),
            'pending_skill_context' => $pendingContext,
            'selected_elements_count' => count((array)($context['selected_elements'] ?? [])),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function normalizeQuestion(string $question): string
    {
        $question = trim($question);
        $question = preg_replace('/^```(?:json|markdown)?\s*/i', '', $question) ?? $question;
        $question = preg_replace('/\s*```$/', '', $question) ?? $question;
        if (preg_match('/^\{.*\}$/s', $question) || preg_match('/^\[.*\]$/s', $question)) {
            $json = json_decode($question, true);
            if (is_array($json)) {
                $question = trim((string)($json['clarify_question'] ?? $json['question'] ?? ''));
            }
        }
        return mb_substr(trim(strip_tags($question)), 0, 500, 'UTF-8');
    }

    private static function isUsefulQuestion(string $question): bool
    {
        if (mb_strlen(trim($question), 'UTF-8') < 6) {
            return false;
        }
        $lower = mb_strtolower($question, 'UTF-8');
        foreach (['clarify_question', 'missing_slots', 'json', '```'] as $noise) {
            if (str_contains($lower, $noise)) {
                return false;
            }
        }
        return true;
    }

    private static function policyQuestion(array $skill, array $missing): string
    {
        $policy = is_array($skill['clarification_policy_json'] ?? null) ? $skill['clarification_policy_json'] : [];
        $questions = is_array($policy['questions'] ?? null) ? $policy['questions'] : [];
        $parts = [];
        foreach (array_slice($missing, 0, 3) as $slot) {
            $parts[] = !empty($questions[$slot]) ? (string)$questions[$slot] : "请补充 {$slot}。";
        }
        return implode("\n", array_values(array_unique($parts))) ?: '请补充必要信息后我再继续。';
    }

    private static function labelForSlotValue(string $slot, string $value): string
    {
        $labels = [
            'platform' => ['taobao' => '淘宝', 'jd' => '京东', 'xiaohongshu' => '小红书'],
            'image_type' => ['main' => '主图', 'detail' => '详情图', 'poster' => '海报'],
        ];
        return (string)($labels[$slot][$value] ?? $value);
    }
}
