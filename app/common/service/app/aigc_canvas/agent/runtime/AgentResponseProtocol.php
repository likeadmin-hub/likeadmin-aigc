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
        // Providers may return tool plans or reasoning in the same text field as
        // a final answer. Only the user-facing projection may reach chat or the
        // presentation renderer; the original trace remains in runtime storage.
        $assistantReply = self::userFacingReply($result);
        $result['reply'] = $assistantReply;
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
            // Narrative is intentionally separate from workflow presentation.
            // Clients should render it as the primary conversation content.
            'assistant_reply' => $assistantReply,
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
                'delivery_plan_id' => (int)($result['delivery_plan']['id'] ?? 0),
                'delivery_item_id' => (int)($result['delivery_item']['id'] ?? 0),
            ],
        ];
    }

    /**
     * Keeps execution traces, tool contracts and model reasoning out of the
     * user-visible reply while retaining a concrete, non-invented fallback.
     */
    public static function userFacingReply(array $result): string
    {
        // assistant_reply is the model's intentionally written narrative. It
        // takes precedence, but a malformed model envelope must not suppress a
        // safe legacy reply that is available alongside it.
        $reply = self::firstUserFacingResultText($result, ['assistant_reply', 'reply']);
        if ($reply !== '') return $reply;

        $mediaTool = self::submittedMediaTool($result);
        if ($mediaTool !== '') {
            return self::mediaSubmissionReply($mediaTool, $result);
        }

        $nextAction = (string)($result['next_action'] ?? 'chat');
        $question = trim((string)(
            $result['clarification_question']
            ?? $result['selected_skill']['clarification_question']
            ?? ''
        ));
        if ($nextAction === 'clarify') {
            return $question !== '' && !self::isInternalTrace($question)
                ? $question
                : '请补充这次希望继续的内容或调整方向。';
        }

        $toolCodes = [];
        foreach ((array)($result['tool_calls'] ?? []) as $call) {
            if (!is_array($call)) continue;
            $toolCodes[] = (string)($call['tool_code'] ?? $call['code'] ?? $call['name'] ?? '');
        }
        if (in_array('generate_video', $toolCodes, true)) return self::mediaSubmissionReply('generate_video', $result);
        if (in_array('generate_music', $toolCodes, true)) return self::mediaSubmissionReply('generate_music', $result);
        if (in_array('generate_image', $toolCodes, true)) return self::mediaSubmissionReply('generate_image', $result);

        return self::fallbackReply($result);
    }

    /** Returns the first non-trace narrative without treating runtime errors as prose. */
    private static function firstUserFacingResultText(array $result, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($result[$key] ?? ''));
            if ($value !== '' && !self::isInternalTrace($value)) return $value;
        }
        return '';
    }

    /**
     * Keeps the fallback aligned with the user-visible interaction state. These
     * messages describe the next user decision, never the Skill or Tool used
     * internally to reach it.
     */
    private static function fallbackReply(array $result): string
    {
        return match (self::interactionMode($result)) {
            'clarify' => "\u{8BF7}\u{8865}\u{5145}\u{8FD9}\u{6B21}\u{5E0C}\u{671B}\u{7EE7}\u{7EED}\u{7684}\u{5185}\u{5BB9}\u{6216}\u{8C03}\u{6574}\u{65B9}\u{5411}\u{3002}",
            'plan' => "\u{6211}\u{5DF2}\u{6574}\u{7406}\u{597D}\u{6267}\u{884C}\u{65B9}\u{6848}\u{FF0C}\u{8BF7}\u{786E}\u{8BA4}\u{540E}\u{7EE7}\u{7EED}\u{3002}",
            'confirm' => "\u{5DF2}\u{51C6}\u{5907}\u{597D}\u{4E0B}\u{4E00}\u{6B65}\u{FF0C}\u{8BF7}\u{786E}\u{8BA4}\u{540E}\u{7EE7}\u{7EED}\u{3002}",
            // Running work is represented by the canvas task surface. Do not
            // add a chat bubble that only repeats an internal status.
            'execute' => '',
            'error' => "\u{8FD9}\u{6B21}\u{6CA1}\u{6709}\u{5904}\u{7406}\u{6210}\u{529F}\u{3002}\u{8BF7}\u{7A0D}\u{540E}\u{91CD}\u{8BD5}\u{FF0C}\u{6216}\u{8C03}\u{6574}\u{63CF}\u{8FF0}\u{540E}\u{7EE7}\u{7EED}\u{3002}",
            'out_of_scope' => "\u{8FD9}\u{4E2A}\u{8BF7}\u{6C42}\u{76EE}\u{524D}\u{65E0}\u{6CD5}\u{76F4}\u{63A5}\u{5728}\u{753B}\u{5E03}\u{4E2D}\u{5B8C}\u{6210}\u{3002}\u{8BF7}\u{8BF4}\u{660E}\u{5E0C}\u{671B}\u{751F}\u{6210}\u{3001}\u{5206}\u{6790}\u{6216}\u{8C03}\u{6574}\u{7684}\u{5185}\u{5BB9}\u{3002}",
            default => "\u{6211}\u{8FD8}\u{6CA1}\u{6709}\u{8DB3}\u{591F}\u{7684}\u{4FE1}\u{606F}\u{7ED9}\u{51FA}\u{53EF}\u{9760}\u{56DE}\u{590D}\u{3002}\u{8BF7}\u{8865}\u{5145}\u{5E0C}\u{671B}\u{8FBE}\u{6210}\u{7684}\u{7ED3}\u{679C}\u{6216}\u{8C03}\u{6574}\u{65B9}\u{5411}\u{3002}",
        };
    }

    private static function interactionMode(array $result): string
    {
        $nextAction = (string)($result['next_action'] ?? 'chat');
        if (self::isError($nextAction, $result)) return 'error';
        if (self::isOutOfScope($nextAction, $result)) return 'out_of_scope';
        if ($nextAction === 'clarify' || self::hasMissingRequiredSlots($result)) return 'clarify';
        if (in_array($nextAction, ['confirm_execution', 'confirm_canvas_mutation', 'confirm_next_batch'], true)) return 'confirm';
        if (in_array($nextAction, ['confirm_initial_batch', 'confirm_plan'], true) || !empty($result['planned_sections'])) return 'plan';
        if (in_array($nextAction, ['generation_submitted', 'execute_tool', 'execute_revision', 'subagents_pending'], true)
            || !empty($result['tool_calls']) || !empty($result['workspace_actions'])) return 'execute';
        return 'answer';
    }

    /**
     * A media submission is already represented by a canvas node. Project only
     * that durable fact, never a provider prompt, task id, or tool trace.
     */
    private static function submittedMediaTool(array $result): string
    {
        $nextAction = (string)($result['next_action'] ?? '');
        $submitted = $nextAction === 'generation_submitted';
        foreach ((array)($result['tool_calls'] ?? []) as $call) {
            if (!is_array($call)) continue;
            $code = (string)($call['tool_code'] ?? $call['code'] ?? $call['name'] ?? '');
            if (!in_array($code, ['generate_image', 'generate_video', 'generate_music'], true)) continue;
            $status = strtolower((string)($call['status'] ?? ''));
            if ($submitted || in_array($status, ['queued', 'running', 'success', 'completed'], true)) {
                return $code;
            }
        }
        return '';
    }

    private static function mediaSubmissionReply(string $toolCode, array $result): string
    {
        $type = match ($toolCode) {
            'generate_video' => "\u{89c6}\u{9891}",
            'generate_music' => "\u{97f3}\u{4e50}",
            default => "\u{56fe}\u{7247}",
        };
        $direction = self::mediaDirection($result);
        return self::hasRenderedMedia($result)
            ? "{$type}\u{5df2}\u{5b8c}\u{6210}\u{6e32}\u{67d3}\u{ff0c}\u{5df2}\u{6309}\u{201c}{$direction}\u{201d}\u{66f4}\u{65b0}\u{5230}\u{753b}\u{5e03}\u{3002}"
            : "\u{5df2}\u{6309}\u{201c}{$direction}\u{201d}\u{521b}\u{5efa}{$type}\u{751f}\u{6210}\u{8282}\u{70b9}\uff0c\u{6e32}\u{67d3}\u{7ed3}\u{679c}\u{4f1a}\u{81ea}\u{52a8}\u{66f4}\u{65b0}\u{5230}\u{753b}\u{5e03}\u{3002}";
    }

    private static function mediaDirection(array $result): string
    {
        $candidates = [(string)($result['original_user_request'] ?? $result['user_request'] ?? '')];
        foreach (array_merge((array)($result['workspace_actions'] ?? []), (array)($result['tool_calls'] ?? [])) as $item) {
            if (!is_array($item)) continue;
            $input = is_array($item['input'] ?? null) ? $item['input'] : [];
            $output = is_array($item['output'] ?? null) ? $item['output'] : [];
            $submitted = is_array($input['submitted_input'] ?? null)
                ? $input['submitted_input']
                : (is_array($output['submitted_input'] ?? null) ? $output['submitted_input'] : $input);
            foreach (['original_user_request', 'user_request', 'creative_intent'] as $key) {
                $candidates[] = (string)($submitted[$key] ?? $input[$key] ?? '');
            }
        }
        foreach ($candidates as $candidate) {
            $value = self::shortUserRequest($candidate);
            if ($value !== '') return $value;
        }
        return "\u{6309}\u{5df2}\u{786e}\u{8ba4}\u{7684}\u{521b}\u{4f5c}\u{8bf7}\u{6c42}\u{751f}\u{6210}";
    }

    private static function shortUserRequest(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '' || self::looksLikeCompiledPrompt($value)) return '';
        return self::limit(self::text($value), 96);
    }

    private static function hasRenderedMedia(array $result): bool
    {
        foreach (array_merge((array)($result['assets'] ?? []), (array)($result['workspace_actions'] ?? [])) as $item) {
            if (!is_array($item) || !empty($item['pending'])) continue;
            $asset = is_array($item['input']['asset'] ?? null) ? $item['input']['asset'] : $item;
            if (trim((string)($asset['url'] ?? $asset['image_url'] ?? $asset['video_url'] ?? $asset['audio_url'] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    public static function isInternalTrace(string $text): bool
    {
        $text = trim($text);
        if ($text === '') return false;
        $json = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text, true);
        if (is_array($json) && self::hasInternalKeys($json)) return true;

        if (self::looksLikeCompiledPrompt($text)) return true;

        $markers = [
            'selected_skill_contract', 'selected_skill_key', 'task_decision', 'project_memory',
            'retrieved_skills', 'binding_mode', 'allowed_tools', 'available_tools', 'tool_calls',
            'workspace_actions', 'runtime_allowed_tools', 'pending_skill_context', 'delivery_specs',
            'function_calls', 'output_contract', 'response_format', 'system_prompt', 'tool_choice',
            'enable_thinking', 'agent_trace', 'execution_mode',
            'compiled_prompt', 'submitted_prompt', 'prompt_spec', 'prompt_spec_json',
            'creative_spec', 'creative_spec_json', 'negative_prompt', 'prompt_hash',
            'tool_trace', 'provider_task_id', 'channel_task_id', 'channel_task',
        ];
        // A single labelled provider or channel identifier is still runtime
        // metadata. Do not require a second marker before hiding it.
        if (preg_match('/^\s*(?:compiled_prompt|submitted_prompt|prompt_spec(?:_json)?|creative_spec(?:_json)?|negative_prompt|prompt_hash|tool_trace|provider_task_id|channel_task_id|channel_task)\s*[:=]/im', $text) === 1) {
            return true;
        }
        $hits = 0;
        foreach ($markers as $marker) {
            if (stripos($text, $marker) !== false) $hits++;
        }
        if ($hits >= 2) return true;

        // A labelled reasoning/tool plan is internal when it also references a
        // runtime contract field. A single everyday word never hides a reply.
        $hasInternalHeading = preg_match('/(?:^|\n)\s*(?:分析|推理|路由|工具|执行计划|内部状态)\s*[:：]/u', $text) === 1;
        return $hasInternalHeading && $hits > 0;
    }

    private static function looksLikeCompiledPrompt(string $text): bool
    {
        $normalized = strtolower(trim($text));
        if (preg_match('/(?:compiled_prompt|prompt_spec_json|creative_spec_json)\s*[:=]/i', $normalized) === 1) {
            return true;
        }
        // The compiler's quality-token payload is never a user-facing answer.
        // Two quality markers are sufficient even when the provider output is short.
        $qualityCount = preg_match_all(
            '/\b(?:masterpiece|best quality|ultra[- ]?realistic|photorealistic|(?:4k|8k|16k) resolution|ray tracing|global illumination|highly detailed|sharp focus)\b/i',
            $text
        );
        if ($qualityCount >= 2) return true;
        return mb_strlen($text, 'UTF-8') > 160 && $qualityCount >= 1;
    }

    private static function hasInternalKeys(array $value): bool
    {
        $keys = array_map('strtolower', array_keys($value));
        $internal = [
            'selected_skill_contract', 'task_decision', 'project_memory', 'retrieved_skills',
            'allowed_tools', 'available_tools', 'tool_calls', 'workspace_actions', 'next_action',
            'pending_skill_context', 'delivery_specs', 'function_calls', 'output_contract',
            'compiled_prompt', 'submitted_prompt', 'prompt_spec', 'prompt_spec_json',
            'creative_spec', 'creative_spec_json', 'negative_prompt', 'prompt_hash',
            'tool_trace', 'provider_task_id', 'channel_task_id', 'channel_task',
        ];
        // A model must never place its raw execution contract in the visible
        // message. One recognized internal key is sufficient proof that this
        // JSON is a runtime artifact rather than a user-facing delivery.
        return count(array_intersect($keys, $internal)) >= 1;
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
            'assistant_reply' => (string)$content['welcome_text'],
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
            // Tool contracts and workspace mutations are retained on the turn
            // record and task surface. Keep these legacy keys for response
            // compatibility, but never project their runtime payload into the
            // user-facing conversation contract.
            return $base + [
                'tasks' => [],
                'tool_calls' => [],
                'workspace_actions' => [],
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
        return self::deliveryActions((array)($result['delivery_item'] ?? []));
    }

    /** @return array{title:string,summary:string,blocks:array,actions:array} */
    private static function presentation(string $kind, array $result, array $batch, array $creativeSummary, array $content, array $quickActions): array
    {
        $message = (string)($content['message'] ?? $result['welcome_text'] ?? $result['error'] ?? '');
        // Preserve provider line breaks in table cells before strip_tags().
        // They are normalized back to visible newlines by documentBlocks().
        $message = self::text(preg_replace('/<br\s*\/?>/iu', "\u{E000}", $message) ?? $message);
        $blocks = self::documentBlocks($message);
        $title = self::title($kind, $result, $content);
        $summary = self::summary($message, $kind);

        if ($kind === self::ONBOARDING) {
            $blocks = self::onboardingBlocks($message);
        } elseif ($kind === self::CLARIFY) {
            // Missing-slot keys are runtime contract data. The model's
            // natural-language question is the complete user-facing prompt.
            // Rendering slot keys as a list leaks implementation details and
            // duplicates the question without improving the next action.
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
            // A running task belongs on the canvas surface. A completed text
            // result or an accepted media submission may still carry a
            // user-facing delivery recap, however, and must remain readable
            // in the conversation without a status card.
            if ((string)($result['next_action'] ?? '') !== 'chat' && self::submittedMediaTool($result) === '') $blocks = [];
        } elseif ($kind === self::OUT_OF_SCOPE) {
            $blocks = self::documentBlocks($message !== ''
                ? $message
                : '这个请求目前不能直接处理。你可以告诉我想在画布中完成的图片、视频或文案任务。');
        } elseif ($kind === self::ERROR) {
            $blocks = self::documentBlocks($message !== ''
                ? $message
                : '这次没有处理成功。请稍后重试，或换一种更具体的说法。');
        }

        $deliveryBlocks = self::deliveryBlocks((array)($result['delivery_item'] ?? []));
        if ($deliveryBlocks !== []) {
            $blocks = $kind === self::EXECUTION_STATUS ? $deliveryBlocks : array_merge($blocks, $deliveryBlocks);
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
            if (!in_array($type, ['prompt', 'confirm_plan', 'fill_slot', 'select_option', 'choose_option', 'approve_strategy', 'approve_visual_generation', 'revise_item', 'retry_item', 'cancel_item'], true)) continue;
            $item = ['type' => $type, 'label' => self::text((string)($action['label'] ?? ''))];
            if ($type === 'prompt') $item['value'] = self::text((string)($action['value'] ?? ''));
            if ($type === 'confirm_plan') $item['batch_id'] = (int)($action['batch_id'] ?? 0);
            foreach (['delivery_item_id', 'action_id', 'required_input_schema', 'options', 'structured_value'] as $key) {
                if (array_key_exists($key, $action)) $item[$key] = $action[$key];
            }
            $actions[] = $item;
        }
        if ($kind === self::ERROR) $actions[] = ['type' => 'retry', 'label' => '重试'];
        return $actions;
    }

    private static function deliveryActions(array $item): array
    {
        $pending = (array)($item['pending_action'] ?? []);
        $type = (string)($pending['type'] ?? '');
        $itemId = (int)($item['id'] ?? 0);
        if ($itemId <= 0 || !in_array($type, ['fill_slot', 'select_option', 'choose_option', 'approve_strategy', 'approve_visual_generation', 'revise_item', 'retry_item'], true)) return [];
        $labels = [
            'fill_slot' => '补充信息', 'select_option' => '选择方案', 'choose_option' => '选择方案',
            'approve_strategy' => '确认策略', 'approve_visual_generation' => '确认生成',
            'revise_item' => '修改', 'retry_item' => '重试',
        ];
        return [[
            'type' => $type,
            'label' => $labels[$type] ?? '继续',
            'delivery_item_id' => $itemId,
            'action_id' => (string)($pending['action_id'] ?? ''),
            'required_input_schema' => (array)($pending['required_input_schema'] ?? []),
            'options' => array_values((array)($pending['options'] ?? [])),
        ]];
    }

    /** Projects durable delivery facts into the existing presentation envelope. */
    private static function deliveryBlocks(array $item): array
    {
        if ((int)($item['id'] ?? 0) <= 0) return [];
        $blocks = [[
            'type' => 'task_status',
            'title' => self::limit(self::text((string)($item['objective'] ?? '当前任务')), 120),
            'items' => [[
                'label' => '状态',
                'value' => self::canvasDeliveryState($item),
            ]],
        ]];
        $result = (array)($item['result'] ?? []);
        $research = (array)($result['research'] ?? []);
        if ($research !== []) {
            $blocks[] = [
                'type' => 'research_summary',
                'text' => (string)($research['basis'] ?? '') === 'tool_results'
                    ? self::text((string)($research['summary'] ?? ''))
                    : '基于已知信息的策略分析',
            ];
            $sources = [];
            foreach ((array)($research['sources'] ?? []) as $source) {
                if (!is_array($source) || trim((string)($source['url'] ?? '')) === '') continue;
                $sources[] = ['label' => self::text((string)($source['title'] ?? $source['url'])), 'url' => trim((string)$source['url'])];
            }
            if ($sources !== []) $blocks[] = ['type' => 'source_list', 'items' => $sources];
        }
        $assets = array_values(array_filter((array)($result['assets'] ?? []), static fn($asset): bool => is_array($asset) && trim((string)($asset['url'] ?? $asset['uri'] ?? '')) !== ''));
        if ($assets !== []) $blocks[] = ['type' => 'asset_gallery', 'items' => $assets];
        $pending = (array)($item['pending_action'] ?? []);
        $pendingType = (string)($pending['type'] ?? '');
        if (in_array($pendingType, ['select_option', 'choose_option'], true)) {
            $blocks[] = ['type' => 'choice', 'items' => array_values((array)($pending['options'] ?? []))];
        } elseif (in_array($pendingType, ['approve_strategy', 'approve_visual_generation'], true)) {
            $blocks[] = ['type' => 'confirm', 'text' => '等待确认'];
        } elseif (in_array((string)($item['status'] ?? ''), ['clarifying', 'failed'], true)) {
            $blocks[] = ['type' => 'blocked', 'text' => self::text((string)($item['error'] ?? '等待所需信息或后续操作'))];
        }
        return $blocks;
    }

    /**
     * Converts durable delivery state into a canvas-local user-facing recap.
     * The wording is selected from the actual item status and tool type; it
     * never instructs the user to continue work in another product surface.
     */
    private static function canvasDeliveryState(array $item): string
    {
        $status = (string)($item['status'] ?? 'draft');
        $kind = match ((string)($item['tool_code'] ?? '')) {
            'generate_image' => "\u{56fe}\u{7247}\u{521b}\u{4f5c}",
            'generate_video' => "\u{89c6}\u{9891}\u{521b}\u{4f5c}",
            'generate_music' => "\u{97f3}\u{4e50}\u{521b}\u{4f5c}",
            'canvas_mutation', 'selection_action' => "\u{753b}\u{5e03}\u{8c03}\u{6574}",
            'asset_analyze' => "\u{7d20}\u{6750}\u{5206}\u{6790}",
            default => "\u{521b}\u{4f5c}\u{4ea4}\u{4ed8}",
        };
        return match ($status) {
            'draft' => "\u{5df2}\u{5728}\u{5f53}\u{524d}\u{753b}\u{5e03}\u{5efa}\u{7acb}{$kind}\u{4ea4}\u{4ed8}\u{9879}\u{ff0c}\u{6b63}\u{5728}\u{6574}\u{7406}\u{9700}\u{6c42}",
            'clarifying' => "{$kind}\u{8fd8}\u{9700}\u{8865}\u{5145}\u{4fe1}\u{606f}\u{ff0c}\u{5f53}\u{524d}\u{753b}\u{5e03}\u{672a}\u{63d0}\u{4ea4}\u{751f}\u{6210}",
            'awaiting_confirmation' => "{$kind}\u{65b9}\u{6848}\u{5df2}\u{5c31}\u{7eea}\u{ff0c}\u{7b49}\u{5f85}\u{4f60}\u{5728}\u{5f53}\u{524d}\u{753b}\u{5e03}\u{786e}\u{8ba4}",
            'ready' => "{$kind}\u{5df2}\u{5c31}\u{7eea}\u{ff0c}\u{53ef}\u{5728}\u{5f53}\u{524d}\u{753b}\u{5e03}\u{5f00}\u{59cb}\u{6267}\u{884c}",
            'queued' => "{$kind}\u{5df2}\u{52a0}\u{5165}\u{5f53}\u{524d}\u{753b}\u{5e03}\u{ff0c}\u{7b49}\u{5f85}\u{5904}\u{7406}",
            'running' => "{$kind}\u{6b63}\u{5728}\u{5f53}\u{524d}\u{753b}\u{5e03}\u{4e2d}\u{63a8}\u{8fdb}",
            'completed' => "{$kind}\u{5df2}\u{66f4}\u{65b0}\u{5230}\u{5f53}\u{524d}\u{753b}\u{5e03}",
            'failed' => "{$kind}\u{6682}\u{672a}\u{5b8c}\u{6210}\u{ff0c}\u{53ef}\u{5728}\u{5f53}\u{524d}\u{753b}\u{5e03}\u{4e2d}\u{91cd}\u{8bd5}\u{6216}\u{8fd4}\u{5de5}",
            'canceled' => "{$kind}\u{5df2}\u{5728}\u{5f53}\u{524d}\u{753b}\u{5e03}\u{4e2d}\u{53d6}\u{6d88}",
            default => "{$kind}\u{6b63}\u{5728}\u{5f53}\u{524d}\u{753b}\u{5e03}\u{4e2d}\u{63a8}\u{8fdb}",
        };
    }

    /** Maps provider prose to the existing generic presentation block set. */
    private static function documentBlocks(string $message): array
    {
        // Preserve line breaks inside Markdown table cells while sanitizing the
        // rest of the message. They are restored only in user-visible values.
        $message = preg_replace('/<br\s*\/?>/iu', "\u{E000}", $message) ?? $message;
        // Some providers concatenate Markdown field labels into one paragraph.
        // Split only labelled fields so the existing presentation blocks remain
        // readable without changing ordinary prose.
        $message = preg_replace('/\s*(\*\*[^*\r\n]{1,48}\*\*\s*[：:])/u', "\n$1", $message) ?? $message;
        $lines = preg_split('/\R/u', self::text($message)) ?: [];
        $blocks = [];
        $paragraph = [];
        $bullets = [];
        $numbered = [];
        $keyValues = [];
        $tableLines = [];

        $flushParagraph = static function () use (&$blocks, &$paragraph): void {
            $text = trim(implode("\n", $paragraph));
            if ($text !== '') $blocks[] = ['type' => 'paragraph', 'text' => self::limit($text, 1600)];
            $paragraph = [];
        };
        $flushBullets = static function () use (&$blocks, &$bullets): void {
            if ($bullets !== []) $blocks[] = ['type' => 'bullets', 'items' => array_slice($bullets, 0, 8)];
            $bullets = [];
        };
        $flushNumbered = static function () use (&$blocks, &$numbered): void {
            if ($numbered !== []) $blocks[] = ['type' => 'numbered_list', 'items' => array_slice($numbered, 0, 12)];
            $numbered = [];
        };
        $flushKeyValues = static function () use (&$blocks, &$keyValues): void {
            if ($keyValues !== []) $blocks[] = ['type' => 'key_value', 'items' => array_slice($keyValues, 0, 10)];
            $keyValues = [];
        };
        $flushTable = static function () use (&$blocks, &$tableLines): void {
            if (count($tableLines) >= 2 && self::isMarkdownTableDivider((string)$tableLines[1])) {
                $columns = array_slice(self::markdownTableCells((string)$tableLines[0]), 0, 6);
                $rows = [];
                foreach (array_slice($tableLines, 2, 12) as $line) {
                    if (self::isMarkdownTableDivider((string)$line)) continue;
                    $cells = array_slice(self::markdownTableCells((string)$line), 0, count($columns));
                    if ($cells === []) continue;
                    $rows[] = array_pad($cells, count($columns), '');
                }
                if ($columns !== [] && $rows !== []) {
                    $blocks[] = ['type' => 'comparison_table', 'columns' => $columns, 'rows' => $rows];
                }
            } elseif ($tableLines !== []) {
                $blocks[] = ['type' => 'paragraph', 'text' => self::limit(implode("\n", $tableLines), 1600)];
            }
            $tableLines = [];
        };

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                $flushParagraph();
                $flushBullets();
                $flushNumbered();
                $flushKeyValues();
                $flushTable();
                continue;
            }
            if (self::isMarkdownTableLine($line)) {
                $flushParagraph();
                $flushBullets();
                $flushNumbered();
                $flushKeyValues();
                $tableLines[] = $line;
                continue;
            }
            $flushTable();
            $line = str_replace("\u{E000}", "\n", $line);
            // Markdown separators and bare list glyphs have no user-facing
            // meaning. Older streamed replies occasionally persisted them.
            if (preg_match('/^(?:[*+\x{2022}\x{00B7}]|-{3,}|_{3,})$/u', $line) === 1) {
                continue;
            }
            if (preg_match('/^#{1,6}\s+(.+)$/u', $line, $matches)) {
                $flushParagraph();
                $flushBullets();
                $flushNumbered();
                $flushKeyValues();
                $heading = self::markdownDocumentValue((string)$matches[1], 120);
                if ($heading !== '') $blocks[] = ['type' => 'heading', 'text' => $heading];
                continue;
            }
            if (preg_match('/^\d+[.)]\s+(?:\*\*|__)([^*_\r\n]{1,120})(?:\*\*|__)\s*$/u', $line, $matches)) {
                $flushParagraph();
                $flushBullets();
                $flushNumbered();
                $flushKeyValues();
                $heading = self::markdownDocumentValue((string)$matches[1], 120);
                if ($heading !== '') $blocks[] = ['type' => 'heading', 'text' => $heading];
                continue;
            }
            if (preg_match('/^(?:\*\*|__)([^*_\r\n]{1,120})(?:\*\*|__)\s*$/u', $line, $matches)) {
                $flushParagraph();
                $flushBullets();
                $flushNumbered();
                $flushKeyValues();
                $heading = self::markdownDocumentValue((string)$matches[1], 120);
                if ($heading !== '') $blocks[] = ['type' => 'heading', 'text' => $heading];
                continue;
            }
            if (preg_match('/^[-*+]\s+(.+)$/u', $line, $matches)) {
                $flushParagraph();
                $flushNumbered();
                $flushKeyValues();
                $item = self::markdownDocumentValue((string)$matches[1], 280);
                if ($item !== '') $bullets[] = $item;
                continue;
            }
            if (preg_match('/^\d+[.)]\s+(.+)$/u', $line, $matches)) {
                $flushParagraph();
                $flushBullets();
                $flushKeyValues();
                $item = self::markdownDocumentValue((string)$matches[1], 280);
                if ($item !== '') $numbered[] = $item;
                continue;
            }
            if (preg_match('/^(?:\*\*|__)?([^:：]{1,48}?)(?:\*\*|__)?\s*[：:]\s*(.+)$/u', $line, $matches)) {
                $flushParagraph();
                $flushBullets();
                $flushNumbered();
                $label = self::markdownDocumentValue((string)$matches[1], 80);
                $value = self::markdownDocumentValue((string)$matches[2], 360);
                if ($label !== '' && $value !== '') $keyValues[] = ['label' => $label, 'value' => $value];
                continue;
            }
            $flushBullets();
            $flushNumbered();
            $flushKeyValues();
            $paragraph[] = $line;
        }
        $flushParagraph();
        $flushBullets();
        $flushNumbered();
        $flushKeyValues();
        $flushTable();

        return array_slice($blocks, 0, 16);
    }

    private static function isMarkdownTableLine(string $line): bool
    {
        return substr_count($line, '|') >= 2 && count(self::markdownTableCells($line)) >= 2;
    }

    private static function isMarkdownTableDivider(string $line): bool
    {
        $cells = self::markdownTableCells($line);
        return $cells !== [] && count(array_filter($cells, static fn(string $cell): bool => preg_match('/^:?-{3,}:?$/', $cell) === 1)) === count($cells);
    }

    private static function markdownTableCells(string $line): array
    {
        $line = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $line);
        $line = trim($line);
        $line = trim($line, '|');
        if ($line === '') return [];
        return array_values(array_map(static fn(string $cell): string => self::markdownDocumentValue($cell, 360), explode('|', $line)));
    }

    private static function markdownDocumentValue(string $value, int $limit): string
    {
        $value = preg_replace('/(?:\*\*|__|`)/u', '', $value) ?? $value;
        $value = str_replace("\u{E000}", "\n", $value);
        return self::limit(self::text(trim($value)), $limit);
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
