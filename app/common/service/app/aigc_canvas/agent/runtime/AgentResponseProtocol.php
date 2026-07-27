<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

/** Stable, structured user-facing state for canvas Agent responses. */
final class AgentResponseProtocol
{
    public const SCHEMA_VERSION = 2;

    public const ONBOARDING = 'onboarding';
    public const CLARIFY = 'clarify';
    public const EVIDENCE_REVIEW = 'evidence_review';
    public const PLAN_REVIEW = 'plan_review';
    public const EXECUTION_STATUS = 'execution_status';
    public const FINAL = 'final';
    public const OUT_OF_SCOPE = 'out_of_scope';
    public const ERROR = 'error';

    /**
     * Returns both the v2 presentation contract and legacy content/quick_actions.
     * The latter remains until every saved historical message is rendered by v2 UI.
     */
    public static function fromResult(array $result, array $creativeSummary = []): array
    {
        $nextAction = (string)($result['next_action'] ?? 'chat');
        $batch = is_array($result['batch'] ?? null) ? $result['batch'] : [];
        $kind = self::kind($nextAction, $result, $creativeSummary);
        $content = self::content($kind, $result, $batch, $creativeSummary);
        $quickActions = self::quickActions($kind, $result, $batch);
        $presentation = self::presentation($kind, $result, $batch, $creativeSummary, $content, $quickActions);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'kind' => self::presentationKind($kind),
            'response_kind' => $kind,
            'next_action' => $nextAction,
            'title' => $presentation['title'],
            'summary' => $presentation['summary'],
            'blocks' => $presentation['blocks'],
            'actions' => $presentation['actions'],
            // Keep reply/content/quick_actions for saved messages and older clients.
            'reply' => (string)($content['message'] ?? ''),
            'content' => $content,
            'quick_actions' => $quickActions,
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
        $content = [
            'agent_name' => (string)($payload['agent_name'] ?? ''),
            'welcome_text' => (string)($payload['welcome_text'] ?? ''),
            'message' => (string)($payload['welcome_text'] ?? ''),
            'capabilities' => array_values((array)($payload['capabilities'] ?? [])),
        ];
        $quickActions = array_map(static fn(string $prompt): array => ['type' => 'prompt', 'label' => $prompt, 'value' => $prompt], $prompts);
        $presentation = self::presentation(self::ONBOARDING, $payload, [], [], $content, $quickActions);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'kind' => self::ONBOARDING,
            'response_kind' => self::ONBOARDING,
            'next_action' => 'choose_capability',
            'title' => $presentation['title'],
            'summary' => $presentation['summary'],
            'blocks' => $presentation['blocks'],
            'actions' => $presentation['actions'],
            'reply' => (string)$content['welcome_text'],
            'content' => $content,
            'quick_actions' => $quickActions,
            'task_context' => [],
        ];
    }

    private static function kind(string $nextAction, array $result, array $creativeSummary): string
    {
        $explicit = (string)($result['response_kind'] ?? $result['presentation_kind'] ?? '');
        if (in_array($explicit, [self::ONBOARDING, self::CLARIFY, self::EVIDENCE_REVIEW, self::PLAN_REVIEW, self::EXECUTION_STATUS, self::FINAL, self::OUT_OF_SCOPE, self::ERROR], true)) {
            return $explicit;
        }
        if (self::isError($nextAction, $result)) return self::ERROR;
        if (self::isOutOfScope($nextAction, $result)) return self::OUT_OF_SCOPE;
        if ($nextAction === 'clarify' || self::hasMissingRequiredSlots($result)) return self::CLARIFY;
        if (in_array($nextAction, ['confirm_initial_batch', 'confirm_execution', 'confirm_plan'], true) || !empty($result['planned_sections'])) return self::PLAN_REVIEW;
        if (in_array($nextAction, ['generation_submitted', 'execute_tool', 'execute_revision', 'subagents_pending'], true) || !empty($result['tool_calls']) || !empty($result['workspace_actions'])) return self::EXECUTION_STATUS;
        return $creativeSummary !== [] ? self::EVIDENCE_REVIEW : self::FINAL;
    }

    private static function isError(string $nextAction, array $result): bool
    {
        return in_array($nextAction, ['error', 'failed'], true)
            || !empty($result['error'])
            || in_array((string)($result['status'] ?? ''), ['error', 'failed'], true);
    }

    private static function isOutOfScope(string $nextAction, array $result): bool
    {
        return in_array($nextAction, ['out_of_scope', 'unsupported', 'not_supported'], true)
            || in_array((string)($result['task_decision']['status'] ?? ''), ['out_of_scope', 'unsupported', 'not_supported'], true)
            || !empty($result['out_of_scope']);
    }

    private static function hasMissingRequiredSlots(array $result): bool
    {
        $missingSlots = $result['task_decision']['missing_hard_slots']
            ?? $result['selected_skill']['missing_slots']
            ?? [];
        return is_array($missingSlots) && $missingSlots !== [];
    }

    private static function content(string $kind, array $result, array $batch, array $creativeSummary): array
    {
        $base = ['message' => (string)($result['reply'] ?? $result['error'] ?? '')];
        if ($kind === self::CLARIFY) {
            return $base + [
                'missing_slots' => array_values((array)($result['task_decision']['missing_hard_slots'] ?? $result['selected_skill']['missing_slots'] ?? [])),
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
                'tasks' => array_values((array)($result['subtasks'] ?? $batch['tasks'] ?? [])),
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

    /** @return array{title:string,summary:string,blocks:array,actions:array} */
    private static function presentation(string $kind, array $result, array $batch, array $creativeSummary, array $content, array $quickActions): array
    {
        $message = self::text((string)($content['message'] ?? $result['welcome_text'] ?? $result['error'] ?? ''));
        $blocks = $message === '' ? [] : [['type' => 'paragraph', 'text' => $message]];
        $title = self::title($kind, $result, $content);
        $summary = self::summary($message, $kind);

        if ($kind === self::ONBOARDING) {
            $capabilities = self::textList((array)($content['capabilities'] ?? []), 5);
            if ($capabilities !== []) $blocks[] = ['type' => 'bullets', 'items' => $capabilities];
        } elseif ($kind === self::CLARIFY) {
            $fields = self::slotFields((array)($content['missing_slots'] ?? []));
            if ($fields !== []) $blocks[] = ['type' => 'fields', 'items' => $fields];
        } elseif ($kind === self::EVIDENCE_REVIEW) {
            $facts = self::textList((array)($creativeSummary['visible_facts'] ?? []), 5);
            if ($facts !== []) $blocks[] = ['type' => 'bullets', 'items' => $facts];
        } elseif ($kind === self::PLAN_REVIEW) {
            $steps = self::planSteps((array)($content['sections'] ?? []));
            if ($steps !== []) $blocks[] = ['type' => 'steps', 'items' => $steps];
            $claims = self::textList((array)($content['claims'] ?? []), 5);
            if ($claims !== []) $blocks[] = ['type' => 'bullets', 'items' => $claims];
        } elseif ($kind === self::OUT_OF_SCOPE) {
            $blocks = [];
            $blocks[] = ['type' => 'notice', 'tone' => 'info', 'text' => $message !== '' ? $message : '当前请求不在已启用能力范围内。'];
        } elseif ($kind === self::ERROR) {
            $blocks = [];
            $blocks[] = ['type' => 'notice', 'tone' => 'error', 'text' => $message !== '' ? $message : '本次处理未完成，请调整输入后重试。'];
        }

        return [
            'title' => $title,
            'summary' => $summary,
            'blocks' => $blocks,
            'actions' => self::actions($kind, $quickActions),
        ];
    }

    private static function presentationKind(string $kind): string
    {
        return match ($kind) {
            self::PLAN_REVIEW => 'plan',
            self::EXECUTION_STATUS => 'execution',
            self::EVIDENCE_REVIEW => 'final',
            default => $kind,
        };
    }

    private static function title(string $kind, array $result, array $content): string
    {
        return match ($kind) {
            self::ONBOARDING => self::text((string)($content['agent_name'] ?? '')) !== '' ? '欢迎使用 ' . self::text((string)$content['agent_name']) : '欢迎使用画布助手',
            self::CLARIFY => '请补充创作信息',
            self::EVIDENCE_REVIEW => '创作依据',
            self::PLAN_REVIEW => '请确认创作方案',
            self::EXECUTION_STATUS => '',
            self::OUT_OF_SCOPE => '当前暂不支持该请求',
            self::ERROR => '本次处理未完成',
            default => self::text((string)($result['title'] ?? '')),
        };
    }

    private static function summary(string $message, string $kind): string
    {
        return match ($kind) {
            self::CLARIFY => '确认必要信息后即可继续。',
            self::PLAN_REVIEW => '确认后将开始执行。',
            self::EXECUTION_STATUS => '',
            self::OUT_OF_SCOPE => '请改用当前已启用的创作能力。',
            self::ERROR => '请调整输入后重试。',
            default => '',
        };
    }

    private static function actions(string $kind, array $quickActions): array
    {
        $actions = [];
        foreach ($quickActions as $action) {
            $type = (string)($action['type'] ?? '');
            if (!in_array($type, ['prompt', 'confirm_plan'], true)) continue;
            $item = ['type' => $type, 'label' => self::text((string)($action['label'] ?? ''))];
            if ($type === 'prompt') $item['value'] = self::text((string)($action['value'] ?? ''));
            if ($type === 'confirm_plan') $item['batch_id'] = (int)($action['batch_id'] ?? 0);
            $actions[] = $item;
        }
        if ($kind === self::ERROR) $actions[] = ['type' => 'retry', 'label' => '重试'];
        return $actions;
    }

    private static function slotFields(array $slots): array
    {
        $fields = [];
        foreach (array_slice(array_values(array_unique(array_filter(array_map('strval', $slots)))), 0, 3) as $slot) {
            $fields[] = [
                'key' => self::limit($slot, 80),
                'label' => self::slotLabel($slot),
                'hint' => '请补充' . self::slotLabel($slot),
                'required' => true,
            ];
        }
        return $fields;
    }

    private static function slotLabel(string $slot): string
    {
        $labels = [
            'visual_subject' => '主题/内容', 'subject' => '主题/内容', 'product' => '商品/主体',
            'style' => '风格', 'visual_style' => '风格', 'ratio' => '比例', 'aspect_ratio' => '比例',
            'target' => '目标对象', 'duration' => '时长', 'audio' => '音频要求', 'script' => '脚本内容',
        ];
        return $labels[$slot] ?? str_replace('_', ' ', self::limit($slot, 80));
    }

    private static function planSteps(array $sections): array
    {
        $steps = [];
        foreach (array_slice($sections, 0, 5) as $section) {
            if (!is_array($section)) continue;
            $title = self::text((string)($section['title'] ?? $section['name'] ?? $section['section_key'] ?? ''));
            if ($title === '') continue;
            $steps[] = ['title' => self::limit($title, 120), 'description' => self::limit(self::text((string)($section['description'] ?? $section['purpose'] ?? '')), 240)];
        }
        return $steps;
    }

    private static function taskProgress(array $tasks): array
    {
        $items = [];
        foreach (array_slice($tasks, 0, 6) as $task) {
            if (!is_array($task)) continue;
            $title = self::text((string)($task['title'] ?? $task['role'] ?? $task['tool_code'] ?? $task['task_key'] ?? ''));
            if ($title === '') continue;
            $status = (string)($task['status'] ?? 'pending');
            $items[] = [
                'title' => self::limit($title, 120),
                'status' => in_array($status, ['pending', 'queued', 'running', 'success', 'failed', 'canceled'], true) ? $status : 'pending',
            ];
        }
        return $items;
    }

    private static function textList(array $values, int $limit): array
    {
        $items = [];
        foreach ($values as $value) {
            $text = self::text(is_scalar($value) ? (string)$value : '');
            if ($text !== '') $items[] = self::limit($text, 280);
            if (count($items) >= $limit) break;
        }
        return $items;
    }

    private static function text(string $value): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
        return strip_tags($value);
    }

    private static function limit(string $value, int $length): string
    {
        return mb_strlen($value, 'UTF-8') > $length ? mb_substr($value, 0, $length, 'UTF-8') . '…' : $value;
    }
}
