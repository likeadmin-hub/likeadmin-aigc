<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

/** Stable, structured user-facing state for canvas Agent responses. */
final class AgentResponseProtocol
{
    public const ONBOARDING = 'onboarding';
    public const CLARIFY = 'clarify';
    public const EVIDENCE_REVIEW = 'evidence_review';
    public const PLAN_REVIEW = 'plan_review';
    public const EXECUTION_STATUS = 'execution_status';
    public const FINAL = 'final';

    public static function fromResult(array $result, array $creativeSummary = []): array
    {
        $nextAction = (string)($result['next_action'] ?? 'chat');
        $batch = is_array($result['batch'] ?? null) ? $result['batch'] : [];
        $kind = self::kind($nextAction, $result, $creativeSummary);
        return [
            'response_kind' => $kind,
            'next_action' => $nextAction,
            'content' => self::content($kind, $result, $batch, $creativeSummary),
            'quick_actions' => self::quickActions($kind, $result, $batch),
            'task_context' => [
                'skill_key' => (string)($result['selected_skill']['skill_key'] ?? $result['task_decision']['selected_skill_key'] ?? ''),
                'intent' => (string)($result['task_decision']['intent'] ?? ''),
                'batch_id' => (int)($result['batch_id'] ?? $batch['id'] ?? 0),
                'section_count' => (int)($result['total_count'] ?? $batch['total_count'] ?? 0),
                'completed_count' => (int)($result['completed_count'] ?? $batch['completed_count'] ?? 0),
                'remaining_count' => (int)($result['remaining_count'] ?? $batch['remaining_count'] ?? 0),
                'turn_id' => (int)($result['turn_id'] ?? 0),
            ],
        ];
    }

    public static function onboarding(array $payload): array
    {
        $prompts = array_values(array_filter(array_map('strval', (array)($payload['quick_prompts'] ?? []))));
        return [
            'response_kind' => self::ONBOARDING,
            'next_action' => 'choose_capability',
            'content' => [
                'agent_name' => (string)($payload['agent_name'] ?? ''),
                'welcome_text' => (string)($payload['welcome_text'] ?? ''),
                'capabilities' => array_values((array)($payload['capabilities'] ?? [])),
            ],
            'quick_actions' => array_map(static fn(string $prompt): array => ['type' => 'prompt', 'label' => $prompt, 'value' => $prompt], $prompts),
            'task_context' => [],
        ];
    }

    private static function kind(string $nextAction, array $result, array $creativeSummary): string
    {
        if ($nextAction === 'clarify') return self::CLARIFY;
        if (in_array($nextAction, ['confirm_initial_batch', 'confirm_execution'], true) || !empty($result['planned_sections'])) return self::PLAN_REVIEW;
        if (in_array($nextAction, ['generation_submitted', 'execute_tool', 'execute_revision'], true) || !empty($result['tool_calls']) || !empty($result['workspace_actions'])) return self::EXECUTION_STATUS;
        return $creativeSummary !== [] ? self::EVIDENCE_REVIEW : self::FINAL;
    }

    private static function content(string $kind, array $result, array $batch, array $creativeSummary): array
    {
        $base = ['message' => (string)($result['reply'] ?? '')];
        if ($kind === self::CLARIFY) {
            return $base + [
                'missing_slots' => array_values((array)($result['task_decision']['missing_hard_slots'] ?? [])),
                'slot_state' => (array)($result['selected_skill']['slot_state'] ?? []),
            ];
        }
        if ($kind === self::EVIDENCE_REVIEW) {
            return $base + [
                'evidence' => $creativeSummary,
                'claims' => array_values((array)($result['creative_brief']['copy_plan']['claims'] ?? [])),
            ];
        }
        if ($kind === self::PLAN_REVIEW) {
            return $base + [
                'sections' => array_values((array)($result['planned_sections'] ?? $batch['planned_sections'] ?? [])),
                'claims' => array_values((array)($batch['claims'] ?? [])),
                'analysis' => (array)($result['design_analysis'] ?? $batch['analysis'] ?? []),
            ];
        }
        if ($kind === self::EXECUTION_STATUS) {
            return $base + [
                'tasks' => array_values((array)($batch['tasks'] ?? [])),
                'tool_calls' => array_values((array)($result['tool_calls'] ?? [])),
                'workspace_actions' => array_values((array)($result['workspace_actions'] ?? [])),
            ];
        }
        return $base;
    }

    private static function quickActions(string $kind, array $result, array $batch): array
    {
        if ($kind === self::PLAN_REVIEW) {
            return [[
                'type' => 'confirm_plan',
                'label' => 'Confirm generation',
                'batch_id' => (int)($result['batch_id'] ?? $batch['id'] ?? 0),
            ]];
        }
        return [];
    }
}
