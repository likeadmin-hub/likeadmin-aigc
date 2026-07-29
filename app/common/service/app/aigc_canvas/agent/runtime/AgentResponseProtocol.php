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
        $presentation['actions'] = array_merge($presentation['actions'], self::deliveryActions((array)($result['delivery_item'] ?? [])));

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
                'intent' => (string)($result['task_decision']['intent'] ?? ''),
                'batch_id' => (int)($result['batch_id'] ?? $batch['id'] ?? 0),
                'section_count' => (int)($result['total_count'] ?? $batch['total_count'] ?? 0),
                'completed_count' => (int)($result['completed_count'] ?? $batch['completed_count'] ?? 0),
                'remaining_count' => (int)($result['remaining_count'] ?? $batch['remaining_count'] ?? 0),
            ],
        ];
    }

    public static function onboarding(array $payload): array
    {
        $prompts = array_slice(array_values(array_filter(array_map('strval', (array)($payload['quick_prompts'] ?? [])))), 0, 3);
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
                'label' => '开始生成',
                'batch_id' => (int)($result['batch_id'] ?? $batch['id'] ?? 0),
            ]];
        }
        return [];
    }

    /** @return array{title:string,summary:string,blocks:array,actions:array} */
    private static function presentation(string $kind, array $result, array $batch, array $creativeSummary, array $content, array $quickActions): array
    {
        $message = self::text((string)($content['message'] ?? $result['welcome_text'] ?? $result['error'] ?? ''));
        $blocks = self::documentBlocks($message);
        $title = self::title($kind, $result, $content);
        $summary = self::summary($message, $kind);

        if ($kind === self::ONBOARDING) {
            $blocks = self::onboardingBlocks($message);
        } elseif ($kind === self::CLARIFY) {
            $items = self::slotItems((array)($content['missing_slots'] ?? []));
            if ($items !== []) $blocks[] = ['type' => 'bullets', 'items' => $items];
        } elseif ($kind === self::EVIDENCE_REVIEW) {
            $facts = self::textList((array)($creativeSummary['visible_facts'] ?? []), 5);
            if ($blocks === []) $blocks[] = ['type' => 'paragraph', 'text' => '我先按画布中的素材整理了这些可见信息。'];
            if ($facts !== []) $blocks[] = ['type' => 'bullets', 'title' => '已确认', 'items' => $facts];
            $claims = self::textList((array)($content['claims'] ?? []), 3);
            if ($claims !== []) $blocks[] = ['type' => 'bullets', 'title' => '可用于创作', 'items' => $claims];
        } elseif ($kind === self::PLAN_REVIEW) {
            $steps = self::planSteps((array)($content['sections'] ?? []));
            if ($steps !== []) $blocks[] = ['type' => 'steps', 'title' => '创作步骤', 'items' => $steps];
            $claims = self::textList((array)($content['claims'] ?? []), 3);
            if ($claims !== []) $blocks[] = ['type' => 'bullets', 'title' => '创作重点', 'items' => $claims];
        } elseif ($kind === self::EXECUTION_STATUS) {
            // Execution is represented by canvas output, never by a chat status or progress card.
            $blocks = [];
        } elseif ($kind === self::OUT_OF_SCOPE) {
            $blocks = self::documentBlocks($message !== ''
                ? $message
                : '这个请求目前不能直接处理。你可以告诉我想在画布中完成的图片、视频或文案任务。');
        } elseif ($kind === self::ERROR) {
            $blocks = self::documentBlocks($message !== ''
                ? $message
                : '这次没有处理成功。请稍后重试，或换一种更具体的说法。');
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
            self::ONBOARDING => '',
            self::CLARIFY => '先确认这几项',
            self::EVIDENCE_REVIEW => '',
            self::PLAN_REVIEW => '建议这样做',
            self::EXECUTION_STATUS => '',
            self::OUT_OF_SCOPE, self::ERROR => '',
            default => self::contentTitle((string)($result['title'] ?? '')),
        };
    }

    private static function summary(string $message, string $kind): string
    {
        return match ($kind) {
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

    /** Small, user-safe actions; never expose the delivery graph or runtime parameters. */
    private static function deliveryActions(array $item): array
    {
        $itemId = (int)($item['id'] ?? 0);
        if ($itemId <= 0) return [];
        $pending = (array)($item['pending_action'] ?? []);
        $type = (string)($pending['type'] ?? '');
        $actionId = (string)($pending['action_id'] ?? '');
        $actions = [];
        if (in_array($type, ['fill_slot', 'choose_option', 'confirm_execution', 'revise_item', 'retry_item'], true)) {
            $actions[] = [
                'type' => $type, 'delivery_item_id' => $itemId, 'action_id' => $actionId,
                'structured_value' => [], 'required_input_schema' => (array)($pending['required_input_schema'] ?? []),
                'options' => array_values((array)($pending['options'] ?? [])), 'label' => self::actionLabel($type),
            ];
        }
        if (in_array((string)($item['status'] ?? ''), ['ready', 'awaiting_confirmation', 'failed', 'completed'], true) && $type !== 'revise_item') {
            $actions[] = [
                'type' => 'revise_item', 'delivery_item_id' => $itemId, 'action_id' => 'revise_item:' . $itemId,
                'structured_value' => [], 'label' => self::actionLabel('revise_item'),
            ];
        }
        if (in_array((string)($item['status'] ?? ''), ['ready', 'awaiting_confirmation', 'queued', 'running', 'failed'], true)) {
            $actions[] = ['type' => 'cancel_item', 'delivery_item_id' => $itemId, 'action_id' => 'cancel_item:' . $itemId, 'structured_value' => [], 'label' => '取消'];
        }
        return $actions;
    }

    private static function actionLabel(string $type): string
    {
        return match ($type) {
            'fill_slot' => '补充信息', 'choose_option' => '选择方案', 'confirm_execution' => '开始生成',
            'revise_item' => '修改', 'retry_item' => '重试', default => '继续',
        };
    }

    /**
     * Maps provider prose to the small block set rendered by the chat UI.
     * Markdown is retained in legacy reply, while v2 receives only safe text blocks.
     */
    private static function documentBlocks(string $message): array
    {
        $lines = preg_split('/\R/u', self::text($message)) ?: [];
        $blocks = [];
        $paragraph = [];
        $bullets = [];

        $flushParagraph = static function () use (&$blocks, &$paragraph): void {
            $text = trim(implode("\n", $paragraph));
            if ($text !== '') $blocks[] = ['type' => 'paragraph', 'text' => self::limit($text, 1600)];
            $paragraph = [];
        };
        $flushBullets = static function () use (&$blocks, &$bullets): void {
            if ($bullets !== []) $blocks[] = ['type' => 'bullets', 'items' => array_slice($bullets, 0, 8)];
            $bullets = [];
        };

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                $flushParagraph();
                $flushBullets();
                continue;
            }
            if (preg_match('/^#{1,6}\s+(.+)$/u', $line, $matches)) {
                $flushParagraph();
                $flushBullets();
                $heading = self::limit(self::text($matches[1]), 120);
                if ($heading !== '') $blocks[] = ['type' => 'paragraph', 'title' => $heading];
                continue;
            }
            if (preg_match('/^(?:[-*+]\s+|\d+[.)]\s+)(.+)$/u', $line, $matches)) {
                $flushParagraph();
                $item = self::limit(self::text($matches[1]), 280);
                if ($item !== '') $bullets[] = $item;
                continue;
            }
            $flushBullets();
            $paragraph[] = $line;
        }
        $flushParagraph();
        $flushBullets();

        return array_slice($blocks, 0, 8);
    }

    private static function onboardingBlocks(string $message): array
    {
        $message = self::text($message);
        if ($message === '') {
            return [['type' => 'paragraph', 'text' => '告诉我想创作什么，或从下方示例开始。']];
        }
        $introLines = [];
        foreach (preg_split('/\R/u', $message) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/^(?:[-*+]\s+|\d+[.)]\s+|#{1,6}\s+)/u', $line)) continue;
            $introLines[] = $line;
            if (count($introLines) >= 2) break;
        }
        $intro = self::text(implode("\n", $introLines));
        return $intro === ''
            ? [['type' => 'paragraph', 'text' => '告诉我想创作什么，或从下方示例开始。']]
            : [['type' => 'paragraph', 'text' => self::limit($intro, 240)]];
    }

    private static function contentTitle(string $title): string
    {
        $title = self::text($title);
        return in_array(mb_strtolower($title, 'UTF-8'), ['已完成', '完成', 'complete', 'completed', 'final'], true)
            ? ''
            : self::limit($title, 120);
    }

    private static function slotItems(array $slots): array
    {
        $items = [];
        foreach (array_slice(array_values(array_unique(array_filter(array_map('strval', $slots)))), 0, 3) as $slot) {
            $items[] = [
                'label' => self::slotLabel($slot),
                'description' => '请补充' . self::slotLabel($slot),
                'key' => self::limit($slot, 80),
            ];
        }
        return $items;
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
