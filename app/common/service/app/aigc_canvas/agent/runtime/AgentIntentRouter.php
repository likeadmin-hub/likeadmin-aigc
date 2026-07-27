<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

/**
 * Optional structured reranker for ambiguous turns. It is deliberately not
 * authorized to select tools, prices, or confirmation behavior.
 */
final class AgentIntentRouter
{
    private const INTENTS = ['chat', 'creative_plan', 'generation', 'canvas_edit', 'research', 'revision'];

    public static function resolve(int $tenantId, int $userId, int $projectId, int $threadId, int $messageId, string $request, array $context, array $candidates): array
    {
        $keys = array_values(array_filter(array_map(static fn(array $item): string => (string)($item['skill_key'] ?? ''), $candidates)));
        if ($keys === []) {
            return self::fallback();
        }
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
            'has_selection' => !empty($context['selected_elements']) || !empty($context['selected_ids']),
            'has_uploaded_asset' => !empty($context['uploaded_references']),
            'output_schema' => [
                'intent' => implode('|', self::INTENTS),
                'selected_skill_key' => 'one of candidate_skill_keys or empty',
                'confidence' => 'number between 0 and 1',
            ],
            'max_tokens' => 240,
        ]);
        $intent = (string)($result['intent'] ?? '');
        $skillKey = (string)($result['selected_skill_key'] ?? '');
        $confidence = (float)($result['confidence'] ?? 0);
        if (!in_array($intent, self::INTENTS, true) || ($skillKey !== '' && !in_array($skillKey, $keys, true)) || $confidence < 0.55) {
            return self::fallback();
        }
        return [
            'intent' => $intent,
            'selected_skill_key' => $skillKey,
            'confidence' => max(0.0, min(1.0, $confidence)),
            'source' => 'llm',
        ];
    }

    private static function fallback(): array
    {
        return ['intent' => '', 'selected_skill_key' => '', 'confidence' => 0.0, 'source' => 'fallback'];
    }

    private static function systemPrompt(): string
    {
        return 'You classify an AIGC canvas request. Return JSON only. Choose an intent and at most one supplied candidate skill key. Never create content, invoke tools, estimate cost, or decide whether an operation may execute.';
    }
}
