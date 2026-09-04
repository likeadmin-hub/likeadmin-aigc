<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

/**
 * Optional structured reranker for ambiguous turns. It is deliberately not
 * authorized to select tools, prices, or confirmation behavior.
 */
final class AgentIntentRouter
{
    private const INTENTS = ['chat', 'creative_plan', 'text_generation', 'generation', 'canvas_edit', 'research'];
    private const TURN_RELATIONS = ['new', 'continue', 'revise', 'confirm', 'retry', 'cancel', 'chat'];
    private const EXECUTION_MODES = ['reply', 'clarify', 'plan', 'execute'];
    private const MIN_VALID_CONFIDENCE = 0.55;
    private const MIN_ACTIONABLE_CONFIDENCE = 0.70;

    public static function resolve(
        int $tenantId,
        int $userId,
        int $projectId,
        int $threadId,
        int $messageId,
        string $request,
        array $context,
        array $candidates,
        array $taskContext = []
    ): array
    {
        $keys = array_values(array_filter(array_map(static fn(array $item): string => (string)($item['skill_key'] ?? ''), $candidates)));
        $execution = AgentExecutionContext::from([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'project_id' => $projectId,
            'thread_id' => $threadId,
            'message_id' => $messageId,
            'user_request' => $request,
            'canvas_context' => $context,
        ]);
        $result = AgentLlmGateway::json($execution, 'intent_router', self::systemPrompt(), [
            'request' => mb_substr($request, 0, 1200, 'UTF-8'),
            'candidate_skill_keys' => $keys,
            'candidate_skills' => array_map(static fn(array $item): array => [
                'skill_key' => (string)($item['skill_key'] ?? ''),
                'name' => mb_substr((string)($item['name'] ?? ''), 0, 80, 'UTF-8'),
                'description' => mb_substr((string)($item['description'] ?? ''), 0, 180, 'UTF-8'),
            ], $candidates),
            'has_selection' => !empty($context['selected_elements']) || !empty($context['selected_ids']),
            'has_uploaded_asset' => !empty($context['uploaded_references']),
            'output_schema' => [
                'turn_relation' => implode('|', self::TURN_RELATIONS),
                'target_delivery_item_id' => '0 unless continuing the supplied active item',
                'intent' => implode('|', self::INTENTS),
                'execution_mode' => implode('|', self::EXECUTION_MODES),
                'selected_skill_key' => 'one of candidate_skill_keys or empty',
                'workflow_template' => 'brand_planning or empty',
                'confidence' => 'number between 0 and 1',
            ],
            'task_context' => $taskContext,
            'allowed_target_delivery_item_ids' => array_values(array_filter(array_map(
                static fn(array $item): int => max(0, (int)($item['id'] ?? 0)),
                (array)($taskContext['active_items'] ?? [])
            ))),
            'max_tokens' => 240,
        ]);
        $intent = (string)($result['intent'] ?? '');
        $skillKey = (string)($result['selected_skill_key'] ?? '');
        $confidence = (float)($result['confidence'] ?? 0);
        $turnRelation = self::enum($result['turn_relation'] ?? '', self::TURN_RELATIONS);
        $executionMode = self::enum($result['execution_mode'] ?? '', self::EXECUTION_MODES);
        if (!in_array($intent, self::INTENTS, true)
            || $turnRelation === ''
            || $executionMode === ''
            || ($skillKey !== '' && !in_array($skillKey, $keys, true))) {
            return self::fallback();
        }
        if ($confidence < self::MIN_VALID_CONFIDENCE
            || ($intent !== 'chat' && $confidence < self::MIN_ACTIONABLE_CONFIDENCE)) {
            return self::lowConfidence($confidence);
        }
        return [
            'turn_relation' => $turnRelation,
            'target_delivery_item_id' => max(0, (int)($result['target_delivery_item_id'] ?? 0)),
            'intent' => $intent,
            'execution_mode' => $executionMode,
            'selected_skill_key' => $skillKey,
            'workflow_template' => (string)($result['workflow_template'] ?? '') === 'brand_planning' ? 'brand_planning' : '',
            'confidence' => max(0.0, min(1.0, $confidence)),
            'source' => 'llm',
        ];
    }

    private static function fallback(): array
    {
        return [
            'turn_relation' => '', 'target_delivery_item_id' => 0, 'intent' => '', 'execution_mode' => '',
            'selected_skill_key' => '', 'workflow_template' => '', 'confidence' => 0.0, 'source' => 'fallback',
        ];
    }

    /**
     * A plausible but non-actionable model classification is not a reason to
     * revive keyword routing. Keep the response contract valid and let the
     * decision service ask for the one missing clarification instead.
     */
    private static function lowConfidence(float $confidence): array
    {
        return [
            'turn_relation' => 'chat',
            'target_delivery_item_id' => 0,
            'intent' => 'chat',
            'execution_mode' => 'clarify',
            'selected_skill_key' => '',
            'workflow_template' => '',
            'confidence' => max(0.0, min(1.0, $confidence)),
            'source' => 'llm_low_confidence',
        ];
    }

    private static function enum($value, array $allowed): string
    {
        $value = trim((string)$value);
        return in_array($value, $allowed, true) ? $value : '';
    }

    private static function systemPrompt(): string
    {
        return 'You classify an AIGC canvas request. Return JSON only. Infer the requested deliverable and whether the user starts, continues, revises, confirms, retries, cancels, or chats. task_context.recent_messages is the only conversation-continuity context: use it before matching a Skill. For example, if a user asks for a video script and the next message is "product promotion", treat it as a subject or constraint for that same text deliverable, not as a new ecommerce-detail-page task. Select ecommerce_detail_page only when the user explicitly requests a product detail page, detail images, or a multi-screen product visual. Text deliverables such as scripts, copy, storyboards, strategy, and research are not media generation. Select generation only when the user explicitly asks for a rendered image, video, or audio asset. candidate_skill_keys are options, never evidence that the user requested that capability; selected_skill_key may be empty. Set target_delivery_item_id only to an ID in allowed_target_delivery_item_ids, otherwise use 0. If the request is ambiguous, set execution_mode to clarify and use a low confidence. Active task context is informational only: never authorize tools, prices, or state transitions.';
    }
}
