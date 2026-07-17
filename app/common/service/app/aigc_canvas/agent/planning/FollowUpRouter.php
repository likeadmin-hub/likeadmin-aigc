<?php

namespace app\common\service\app\aigc_canvas\agent\planning;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_llm\AigcLlmService;
use Exception;

final class FollowUpRouter
{
    private const INTENTS = [
        'chat', 'analyze_delivery', 'modify_delivery', 'regenerate', 'create_variation',
        'continue_batch', 'extend_delivery', 'change_copy', 'change_style', 'change_layout',
        'change_media_config', 'convert_to_video', 'create_script', 'new_task', 'clarify',
    ];

    public static function decide(int $tenantId, int $userId, string $content, array $deliveryContext): array
    {
        if (empty($deliveryContext['last_delivery']) && empty($deliveryContext['active_batch'])) {
            return [];
        }
        try {
            $result = AigcLlmService::generateText($tenantId, $userId, [
                'content' => json_encode([
                    'task' => 'resolve_continuous_design_follow_up',
                    'user_message' => $content,
                    'conversation_delivery_context' => $deliveryContext,
                    'rules' => [
                        'Classify the semantic request using the full delivery context, never a single keyword.',
                        'A follow-up may modify, continue, analyze, convert, extend, or start a new unrelated task.',
                        'Resolve target sections from explicit selections, section numbers/names, conversational references, then last logical delivery.',
                        'Do not call image tools for advice, analysis, planning, copywriting, or scripts.',
                        'If the target is genuinely ambiguous, choose clarify and ask one concise question.',
                        'Return no hidden reasoning. decision_trace_summary is a short user-facing explanation only.',
                    ],
                    'output_schema' => [
                        'conversation_mode' => 'follow_up|new_task',
                        'intent' => implode('|', self::INTENTS),
                        'operation' => 'analyze|modify|regenerate|variation|continue|extend|convert|script|none',
                        'target_scope' => [
                            'source' => 'selected_elements|explicit_sections|last_delivery|active_batch|none',
                            'batch_id' => 'integer',
                            'section_keys' => ['string'],
                        ],
                        'changes' => [['type' => 'string', 'instruction' => 'string']],
                        'media_config_patch' => [
                            'ratio' => 'string', 'quality' => 'string', 'duration' => 'number',
                            'model' => 'string', 'channel' => 'string', 'mode' => 'string',
                        ],
                        'preserve' => ['string'],
                        'requires_tool' => 'boolean',
                        'tool' => 'generate_image|generate_video|generate_text|none',
                        'next_action' => 'execute_revision|continue_batch|chat|clarify|route_new_task',
                        'confidence' => 'number',
                        'clarify_question' => 'string',
                        'decision_trace_summary' => 'string',
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'system_prompt' => 'You are the Master Agent for a continuous AI design conversation. Return one compact JSON object only.',
                'response_format' => ['type' => 'json_object'],
                'max_tokens' => 2200,
                'source_app_code' => AigcCanvasService::APP_CODE,
                'source_type' => 'canvas_follow_up_router',
            ]);
            $decision = self::parseJson((string)($result['content'] ?? ''));
        } catch (Exception) {
            $decision = [];
        }
        return self::normalize($decision, $deliveryContext);
    }

    private static function normalize(array $decision, array $context): array
    {
        $intent = (string)($decision['intent'] ?? '');
        $confidence = (float)($decision['confidence'] ?? 0);
        if (!in_array($intent, self::INTENTS, true) || $confidence < 0.62) {
            return [
                'conversation_mode' => 'follow_up',
                'intent' => 'clarify',
                'operation' => 'none',
                'target_scope' => ['source' => 'none', 'batch_id' => 0, 'section_keys' => []],
                'changes' => [],
                'media_config_patch' => [],
                'preserve' => [],
                'requires_tool' => false,
                'tool' => 'none',
                'next_action' => 'clarify',
                'confidence' => $confidence,
                'clarify_question' => '你希望修改刚才的设计结果，继续生成剩余内容，还是开始一个新任务？',
                'decision_trace_summary' => '当前指令的目标范围还不够明确。',
            ];
        }
        if ($intent === 'new_task') {
            return ['conversation_mode' => 'new_task', 'intent' => 'new_task', 'next_action' => 'route_new_task', 'confidence' => $confidence];
        }
        $scope = is_array($decision['target_scope'] ?? null) ? $decision['target_scope'] : [];
        $batchId = (int)($scope['batch_id'] ?? 0);
        if ($batchId <= 0) {
            $batchId = (int)($context['last_delivery']['batch_id'] ?? $context['active_batch']['batch_id'] ?? 0);
        }
        $available = self::availableSectionKeys($context, $batchId);
        $requested = array_values(array_unique(array_filter(array_map('strval', (array)($scope['section_keys'] ?? [])))));
        $sectionKeys = empty($requested) ? $available : array_values(array_intersect($available, $requested));
        $requiresTool = !empty($decision['requires_tool']);
        if ($requiresTool && empty($sectionKeys) && !in_array($intent, ['continue_batch', 'extend_delivery'], true)) {
            return [
                'conversation_mode' => 'follow_up', 'intent' => 'clarify', 'operation' => 'none',
                'target_scope' => ['source' => 'none', 'batch_id' => $batchId, 'section_keys' => []],
                'changes' => [], 'preserve' => [], 'requires_tool' => false, 'tool' => 'none',
                'media_config_patch' => [],
                'next_action' => 'clarify', 'confidence' => $confidence,
                'clarify_question' => '请告诉我需要修改哪一张或哪几张设计图。',
                'decision_trace_summary' => '没有找到可安全修改的目标图片。',
            ];
        }
        return [
            'conversation_mode' => 'follow_up',
            'intent' => $intent,
            'operation' => (string)($decision['operation'] ?? 'none'),
            'target_scope' => [
                'source' => (string)($scope['source'] ?? 'last_delivery'),
                'batch_id' => $batchId,
                'section_keys' => $sectionKeys,
            ],
            'changes' => array_values(array_filter((array)($decision['changes'] ?? []), 'is_array')),
            'media_config_patch' => self::mediaConfigPatch((array)($decision['media_config_patch'] ?? [])),
            'preserve' => array_values(array_filter(array_map('strval', (array)($decision['preserve'] ?? [])))),
            'requires_tool' => $requiresTool,
            'tool' => (string)($decision['tool'] ?? 'none'),
            'next_action' => (string)($decision['next_action'] ?? ($requiresTool ? 'execute_revision' : 'chat')),
            'confidence' => $confidence,
            'clarify_question' => trim((string)($decision['clarify_question'] ?? '')),
            'decision_trace_summary' => mb_substr(trim((string)($decision['decision_trace_summary'] ?? '')), 0, 600, 'UTF-8'),
        ];
    }

    private static function availableSectionKeys(array $context, int $batchId): array
    {
        foreach (['last_delivery', 'active_batch'] as $key) {
            $delivery = (array)($context[$key] ?? []);
            if ($batchId > 0 && (int)($delivery['batch_id'] ?? 0) !== $batchId) {
                continue;
            }
            $keys = array_values(array_filter(array_map('strval', (array)($delivery['section_keys'] ?? []))));
            if (!empty($keys)) {
                return $keys;
            }
        }
        return [];
    }

    private static function mediaConfigPatch(array $source): array
    {
        $result = [];
        foreach (['ratio', 'quality', 'model', 'channel', 'mode'] as $key) {
            $value = trim((string)($source[$key] ?? ''));
            if ($value !== '') {
                $result[$key] = mb_substr($value, 0, 120, 'UTF-8');
            }
        }
        if (isset($source['duration']) && is_numeric($source['duration'])) {
            $result['duration'] = max(1, min(60, (int)$source['duration']));
        }
        return $result;
    }

    private static function parseJson(string $content): array
    {
        $text = trim($content);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $json = json_decode($text, true);
        if (!is_array($json) && preg_match('/\{.*\}/s', $text, $match)) {
            $json = json_decode($match[0], true);
        }
        return is_array($json) ? $json : [];
    }
}
