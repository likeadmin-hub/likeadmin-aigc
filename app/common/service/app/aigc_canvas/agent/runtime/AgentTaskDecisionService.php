<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use app\common\model\app\aigc_canvas\AigcCanvasAgentMessage;
use app\common\model\app\aigc_canvas\AigcCanvasAgentWorkspaceAction;
use app\common\service\app\aigc_canvas\AigcCanvasSkillService;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryPlanService;
use app\common\service\app\aigc_canvas\agent\memory\ConversationMemoryBuilder;
use app\common\service\app\aigc_canvas\agent\delivery\PendingActionProtocol;
use app\common\service\app\aigc_canvas\agent\model\CanvasGenerationPricingService;

/**
 * Selects how much a retrieved Skill constrains an Agent turn. Retrieval is a
 * hint by default; only an explicit selection or a risky action becomes a
 * delivery contract.
 */
final class AgentTaskDecisionService
{
    public static function decide(int $tenantId, string $request, array $context, array $selectedSkill = [], array $params = []): array
    {
        $pending = self::pendingContext($params['agent_decision_context'] ?? []);
        $explicit = $selectedSkill !== [];
        $bindingEnabled = !array_key_exists('skill_binding_mode_enabled', $params) || !empty($params['skill_binding_mode_enabled']);
        $skill = $selectedSkill;
        $effectiveRequest = $request;
        $confirmed = !empty($params['execution_confirmation']) || !empty($params['confirm_execution']);

        if (!$explicit && !empty($pending['skill_key'])) {
            $skill = AigcCanvasSkillService::resolveSkill($tenantId, (string)$pending['skill_key']);
            $effectiveRequest = (string)($pending['original_request'] ?? $request);
            $confirmed = $confirmed || !empty($pending['confirmed']);
        }
        $candidates = self::candidates($tenantId, $request, $context);
        // Retrieval narrows the prompt when it has a match. If it does not,
        // the model still receives the active catalog instead of falling back
        // to keyword classification.
        $semanticCandidates = self::semanticCandidates($tenantId, $candidates, $selectedSkill);
        $router = ['intent' => '', 'selected_skill_key' => '', 'confidence' => 0.0, 'source' => 'fallback'];
        $textRetry = !$explicit ? self::textRetryContext($tenantId, $request, $params) : [];
        if ($textRetry !== []) {
            $retrySkill = AigcCanvasSkillService::resolveSkill($tenantId, (string)$textRetry['skill_key']);
            if ($retrySkill !== []) $skill = $retrySkill;
            $effectiveRequest = (string)$textRetry['source_request'];
            $router = [
                'turn_relation' => 'retry',
                'target_delivery_item_id' => 0,
                'intent' => 'text_generation',
                'execution_mode' => 'execute',
                'selected_skill_key' => (string)$textRetry['skill_key'],
                'workflow_template' => '',
                'confidence' => 0.96,
                'source' => 'text_retry_context',
            ];
            $params['_text_retry_context'] = $textRetry;
        }
        $reusedRouter = !$explicit && empty($pending['skill_key'])
            ? self::reusedRouter($params, $semanticCandidates)
            : [];
        if ($textRetry !== []) {
            // The actual previous delivery is more trustworthy than a stale
            // media item that may have been inferred from an earlier routing error.
        } elseif ($reusedRouter !== []) {
            $router = $reusedRouter;
        } elseif (!empty($params['agent_intent_router_enabled'])) {
            $router = AgentIntentRouter::resolve(
                $tenantId,
                (int)($params['user_id'] ?? 0),
                (int)($params['project_id'] ?? 0),
                (int)($params['thread_id'] ?? 0),
                (int)($params['message_id'] ?? 0),
                $request,
                $context,
                $semanticCandidates,
                self::semanticTaskContext($tenantId, $params)
            );
        }
        $params['_semantic_request'] = $request;
        $taskTarget = self::taskTarget($tenantId, $params, $router);
        $params['_delivery_task_target'] = $taskTarget;
        $deliveryItemId = (int)($taskTarget['id'] ?? $params['delivery_item_id'] ?? $pending['delivery_item_id'] ?? 0);
        if (!empty($taskTarget['item']) && (string)($router['turn_relation'] ?? '') !== 'new') {
            $continuedSkill = AigcCanvasSkillService::resolveSkill($tenantId, (string)($taskTarget['item']['skill_key'] ?? ''));
            if ($continuedSkill !== []) {
                $skill = $continuedSkill;
                $explicit = true;
            }
        }
        if (!$explicit && !empty($router['selected_skill_key'])) {
            foreach ($semanticCandidates as $candidate) {
                if ((string)($candidate['skill_key'] ?? '') === (string)$router['selected_skill_key']) {
                    $skill = $candidate;
                    break;
                }
            }
        }
        $workflowHint = self::workflowTemplate($selectedSkill, [], $router);
        // Ranked Skill candidates are only hints for the semantic router. A
        // missing/low-confidence router decision must not silently bind the
        // first keyword-ranked domain Skill to an ordinary conversation.
        if (!$explicit && empty($pending['skill_key']) && $textRetry === []
            && empty($taskTarget['item']) && empty($router['selected_skill_key'])) {
            $skill = [];
        }
        if (self::requiresSemanticClarification($router, !empty($params['agent_intent_router_enabled'])) && empty($taskTarget['item'])) {
            return self::semanticClarificationDecision($bindingEnabled, $context, $deliveryItemId, $request, $params, $router);
        }
        if ($skill === []) {
            if ($workflowHint !== '') {
                return self::decorate([
                    'delivery_item_id' => $deliveryItemId,
                    'intent' => 'creative_plan',
                    'action_mode' => 'plan',
                    'binding_mode' => 'none',
                    'target' => self::target($context),
                    'risk' => 'low',
                    'selected_skill_key' => '',
                    'selected_skill_name' => '',
                    'skill_candidates' => [],
                    'router_source' => 'workflow_rule',
                    'semantic_turn_relation' => (string)($router['turn_relation'] ?? 'new'),
                    'semantic_authoritative' => self::authorizesSemanticDecision($router),
                    'explicit_skill' => false,
                    'confidence' => 0.92,
                    'missing_hard_slots' => [],
                    'inferred_slots' => [],
                    'default_slots' => [],
                    'default_sources' => [],
                    'clarification_question' => '',
                    'next_action' => 'reply',
                    'upgrade_reasons' => [],
                    'requires_confirmation' => false,
                    'estimated_tools' => [],
                    'pending_context' => [],
                    'runtime_allowed_tools' => ['ask_user', 'generate_text'],
                    'binding_rollout_enabled' => $bindingEnabled,
                    'skill_contract' => [],
                    'workflow_template' => $workflowHint,
                ], $request, $context, $params);
            }
            if (!empty($params['agent_generic_capability_fallback_enabled'])) {
                $generic = GenericCapabilityContractFactory::create($tenantId, $effectiveRequest, $context);
                if ($generic !== []) {
                    return self::genericDecision($tenantId, $effectiveRequest, $context, $generic, $confirmed, $bindingEnabled, $deliveryItemId, $params);
                }
            }
            return self::noneDecision($bindingEnabled, $context, $deliveryItemId, $request, $params);
        }

        $contract = AigcCanvasSkillService::compileForAgent($skill, $effectiveRequest, $context, $explicit);
        $policy = (array)($contract['binding_policy'] ?? []);
        $mode = self::bindingMode($bindingEnabled, $explicit, $policy);
        $upgrades = self::upgradeReasons($request, $context, $contract);
        if ($upgrades !== []) {
            $mode = 'contract';
        }

        $missing = $mode === 'contract' ? (array)($contract['missing_hard_slots'] ?? []) : [];
        // A contract controls slots and permissions. Confirmation remains an
        // action-level guard, reserved for paid, batch, or canvas-write work.
        // A multi-item batch is the only Agent-level confirmation. Canvas
        // changes retain their own confirmation at the action-application UI.
        $requiresConfirmation = $mode === 'contract' && in_array('batch', $upgrades, true);
        $capabilities = (array)($contract['capability_tools'] ?? []);
        $allowed = $mode === 'contract' && $upgrades !== []
            ? (array)($contract['allowed_tools'] ?? [])
            : array_values(array_unique(array_merge((array)($capabilities['read'] ?? []), (array)($capabilities['plan'] ?? []))));
        if ($allowed === []) {
            $allowed = ['ask_user', 'retrieve_skills', 'generate_text'];
        }
        $contract['binding_mode'] = $mode;
        $contract['allowed_tools'] = $allowed;
        $contract['execution_confirmed'] = $confirmed;
        $runtimeAllowedTools = $mode === 'contract'
            ? (array)($contract['allowed_tools'] ?? [])
            : array_values(array_unique(array_merge(
                self::lowRiskTools(),
                (array)($capabilities['read'] ?? []),
                (array)($capabilities['plan'] ?? [])
            )));

        $workflowTemplate = self::workflowTemplate($selectedSkill, $contract, $router) ?: $workflowHint;
        $intent = $workflowTemplate !== '' ? 'creative_plan' : (!empty($taskTarget['item']) && !self::isExplicitNewTask($request)
            ? self::itemIntent((array)$taskTarget['item'])
            : ((string)($router['intent'] ?? '') ?: self::intent($request, $context, $contract)));
        // A model may recommend a Skill, but only its validated semantic intent
        // may authorize media delivery. This prevents "write a video script"
        // from inheriting video-generation tools through a broad Skill contract.
        if ($intent === 'generation' && !self::authorizesMedia($router, $explicit)) {
            $intent = 'chat';
            $runtimeAllowedTools = self::lowRiskTools();
            $contract['allowed_tools'] = $runtimeAllowedTools;
        } elseif ($intent === 'generation') {
            // A high-confidence semantic media decision is the bounded
            // authorization for a direct generation turn. The previous
            // advisory contract exposed no costly tools even after the model
            // had resolved a concrete image/video/music request, causing its
            // valid tool call to be rejected by the executor.
            $mode = 'contract';
            $missing = (array)($contract['missing_hard_slots'] ?? []);
            $requiresConfirmation = in_array('batch', $upgrades, true);
            $allowed = array_values(array_unique(array_merge(
                $allowed,
                (array)($capabilities['costly'] ?? [])
            )));
            $contract['binding_mode'] = $mode;
            $contract['allowed_tools'] = $allowed;
            $runtimeAllowedTools = $allowed;
        } elseif ($intent !== 'generation') {
            $runtimeAllowedTools = self::withoutMediaTools($runtimeAllowedTools);
            $contract['allowed_tools'] = self::withoutMediaTools((array)$contract['allowed_tools']);
        }
        $risk = self::risk($upgrades);
        $actionMode = self::constrainActionMode(
            self::actionMode($intent, $risk, $missing, $requiresConfirmation, $confirmed),
            $intent,
            $router
        );
        $nextAction = match ($actionMode) {
            'clarify' => 'clarify',
            'confirm' => 'confirm_execution',
            default => 'reply',
        };
        $pendingContext = $nextAction === 'confirm_execution' ? [
            'skill_key' => (string)($contract['skill_key'] ?? ''),
            'original_request' => $effectiveRequest,
            'binding_mode' => $mode,
            'inferred_slots' => (array)($contract['inferred_slots'] ?? []),
            'default_slots' => (array)($contract['default_slots'] ?? []),
            'upgrade_reasons' => $upgrades,
        ] : [];
        $estimatedTools = $requiresConfirmation ? self::estimateTools($tenantId, $request, $contract) : [];

        return self::decorate([
            'delivery_item_id' => $deliveryItemId,
            'intent' => $intent,
            'action_mode' => $actionMode,
            'binding_mode' => $mode,
            'target' => self::target($context),
            'risk' => $risk,
            'selected_skill_key' => (string)($contract['skill_key'] ?? ''),
            'selected_skill_name' => (string)($contract['name'] ?? ''),
            'skill_candidates' => array_values(array_filter(array_map(static fn(array $item): string => (string)($item['skill_key'] ?? ''), $candidates))),
            'router_source' => (string)($router['source'] ?? ($explicit || !empty($pending['skill_key']) ? 'rules' : 'fallback')),
            'semantic_turn_relation' => (string)($router['turn_relation'] ?? ''),
            'semantic_authoritative' => self::authorizesSemanticDecision($router),
            'explicit_skill' => $explicit,
            'confidence' => $explicit ? 1.0 : ((float)($router['confidence'] ?? 0) > 0 ? (float)$router['confidence'] : self::confidence($request, $contract)),
            'missing_hard_slots' => $missing,
            'inferred_slots' => (array)($contract['inferred_slots'] ?? []),
            'default_slots' => (array)($contract['default_slots'] ?? []),
            'default_sources' => (array)($contract['default_sources'] ?? []),
            'clarification_question' => (string)($contract['clarification_question'] ?? ''),
            'next_action' => $nextAction,
            'upgrade_reasons' => $upgrades,
            'requires_confirmation' => $requiresConfirmation,
            'estimated_tools' => $estimatedTools,
            'pending_context' => $pendingContext,
            'runtime_allowed_tools' => $runtimeAllowedTools,
            'binding_rollout_enabled' => $bindingEnabled,
            'skill_contract' => $contract,
            'workflow_template' => $workflowTemplate,
        ], $request, $context, $params);
    }

    private static function noneDecision(bool $bindingEnabled = true, array $context = [], int $deliveryItemId = 0, string $request = '', array $params = []): array
    {
        return self::decorate([
            'delivery_item_id' => $deliveryItemId, 'intent' => 'chat', 'action_mode' => 'reply', 'binding_mode' => 'none', 'target' => self::target($context), 'risk' => 'low', 'skill_candidates' => [], 'router_source' => 'fallback', 'selected_skill_key' => '', 'selected_skill_name' => '',
            'confidence' => 0.0, 'missing_hard_slots' => [], 'inferred_slots' => [], 'default_slots' => [],
            'default_sources' => [], 'clarification_question' => '', 'next_action' => 'reply',
            'upgrade_reasons' => [], 'requires_confirmation' => false, 'estimated_tools' => [], 'pending_context' => [],
            'runtime_allowed_tools' => self::lowRiskTools(), 'binding_rollout_enabled' => $bindingEnabled, 'skill_contract' => [],
        ], $request, $context, $params);
    }

    /**
     * Model uncertainty is handled before generic capabilities or ranked
     * Skill candidates. Candidates can inform LLM routing but cannot turn an
     * ambiguous request into a billable or mutating action.
     */
    private static function semanticClarificationDecision(
        bool $bindingEnabled,
        array $context,
        int $deliveryItemId,
        string $request,
        array $params,
        array $router
    ): array {
        return self::decorate([
            'delivery_item_id' => $deliveryItemId,
            'intent' => 'chat',
            'action_mode' => 'clarify',
            'binding_mode' => 'none',
            'target' => self::target($context),
            'risk' => 'low',
            'selected_skill_key' => '',
            'selected_skill_name' => '',
            'skill_candidates' => [],
            'router_source' => (string)($router['source'] ?? 'llm_low_confidence'),
            'semantic_turn_relation' => (string)($router['turn_relation'] ?? 'chat'),
            'semantic_authoritative' => false,
            'explicit_skill' => false,
            'confidence' => (float)($router['confidence'] ?? 0.0),
            'missing_hard_slots' => ['creative_goal'],
            'inferred_slots' => [],
            'default_slots' => [],
            'default_sources' => [],
            'clarification_question' => '我还不确定你希望完成什么。请说明要制作的内容、期望结果或要修改的对象。',
            'next_action' => 'clarify',
            'upgrade_reasons' => [],
            'requires_confirmation' => false,
            'estimated_tools' => [],
            'pending_context' => [],
            'runtime_allowed_tools' => ['ask_user'],
            'binding_rollout_enabled' => $bindingEnabled,
            'skill_contract' => [],
            'workflow_template' => '',
        ], $request, $context, $params);
    }

    private static function genericDecision(int $tenantId, string $request, array $context, array $contract, bool $confirmed, bool $bindingEnabled, int $deliveryItemId = 0, array $params = []): array
    {
        $capability = (string)$contract['capability'];
        $isMedia = in_array($capability, ['generate_image', 'generate_video', 'generate_music'], true);
        $risk = $isMedia ? 'costly' : ($capability === 'canvas_mutation' ? 'canvas_write' : 'low');
        $missing = (array)($contract['missing_hard_slots'] ?? []);
        // A generic capability is only a tool-safety boundary. It must not
        // produce a synthetic confirmation reply with invented defaults.
        $requiresConfirmation = false;
        $actionMode = self::actionMode($isMedia ? 'generation' : ($capability === 'canvas_mutation' ? 'canvas_edit' : 'text_generation'), $risk, $missing, $requiresConfirmation, $confirmed);
        $contract['execution_confirmed'] = $confirmed;
        $nextAction = $actionMode === 'clarify' ? 'clarify' : ($actionMode === 'confirm' ? 'confirm_execution' : ($actionMode === 'execute' ? 'execute' : 'reply'));
        return self::decorate([
            'delivery_item_id' => $deliveryItemId,
            'intent' => $isMedia ? 'generation' : ($capability === 'canvas_mutation' ? 'canvas_edit' : 'text_generation'),
            'action_mode' => $actionMode,
            'binding_mode' => 'generic_contract',
            'capability' => $capability,
            'artifact_type' => (string)($contract['artifact_type'] ?? ''),
            'target' => self::target($context),
            'risk' => $risk,
            'selected_skill_key' => '',
            'selected_skill_name' => '',
            'skill_candidates' => [],
            'router_source' => 'generic_fallback',
            'confidence' => 0.85,
            'missing_hard_slots' => $missing,
            'inferred_slots' => [],
            'default_slots' => (array)($contract['default_slots'] ?? []),
            'default_sources' => (array)($contract['default_sources'] ?? []),
            'clarification_question' => (string)($contract['clarification_question'] ?? ''),
            'next_action' => $nextAction,
            'upgrade_reasons' => $requiresConfirmation ? [$risk] : [],
            'requires_confirmation' => $requiresConfirmation,
            'estimated_tools' => $isMedia ? self::estimateTools($tenantId, $request, $contract) : [],
            'pending_context' => $requiresConfirmation && !$confirmed ? ['original_request' => $request, 'confirmed' => false] : [],
            'runtime_allowed_tools' => (array)$contract['allowed_tools'],
            'binding_rollout_enabled' => $bindingEnabled,
            'skill_contract' => $contract,
        ], $request, $context, $params);
    }

    /**
     * Normalizes the decision consumed by task resolution and the Agent loop.
     * The resolver receives this exact result and does not reclassify a turn.
     */
    private static function decorate(array $decision, string $request, array $context, array $params): array
    {
        $explicitId = (int)($params['delivery_item_id'] ?? 0);
        $hasExplicitTarget = $explicitId > 0;
        if ($explicitId <= 0) $explicitId = (int)($decision['delivery_item_id'] ?? 0);
        $target = (array)($params['_delivery_task_target'] ?? []);
        if ((int)($target['id'] ?? 0) > 0) $explicitId = (int)$target['id'];
        $relation = self::turnRelation($request, $decision, $explicitId, $target);
        $decision['turn_relation'] = $relation;
        $decision['target_delivery_item_id'] = in_array($relation, ['continue', 'revise', 'confirm', 'retry', 'cancel'], true) ? $explicitId : 0;
        if ($relation === 'new') {
            $decision = self::requireMediaBrief($decision, $request);
        }
        $decision['execution_mode'] = match ((string)($decision['action_mode'] ?? 'reply')) {
            'clarify' => 'clarify',
            'plan', 'confirm' => 'plan',
            'execute' => 'execute',
            default => 'reply',
        };
        $targetItem = (array)($target['item'] ?? []);
        if ($targetItem !== [] && $relation !== 'new') {
            $targetPending = (array)($targetItem['pending_action'] ?? []);
            $pendingType = (string)($targetPending['type'] ?? '');
            $decision['execution_mode'] = match ($pendingType) {
                'fill_slot' => 'clarify',
                'select_option', 'choose_option', 'revise_item' => 'plan',
                'cancel_item' => 'reply',
                'confirm_execution', 'approve_visual_generation', 'approve_strategy', 'retry_item' => 'execute',
                default => in_array((string)($targetItem['status'] ?? ''), ['draft', 'ready'], true) ? 'execute' : $decision['execution_mode'],
            };
        }
        // Direct image/video/music requests are submitted by the existing
        // prompt and generation-task path. Delivery items are reserved for
        // explicit professional workflows compiled above.
        $decision['delivery_specs'] = [];
        $decision['task_target_source'] = (string)($target['source'] ?? '');
        if (!empty($params['_text_retry_context'])) {
            $decision['retry_source'] = [
                'message_id' => (int)($params['_text_retry_context']['message_id'] ?? 0),
                'request' => (string)($params['_text_retry_context']['source_request'] ?? ''),
            ];
        }
        return $decision;
    }

    /**
     * Media capabilities require a concrete subject before the Agent may call
     * a provider. This is deliberately independent of a retrieved Skill: a
     * broad image/video Skill cannot turn "generate an image" into a billable
     * task or a synthetic delivery card.
     */
    private static function requireMediaBrief(array $decision, string $request): array
    {
        if ((string)($decision['intent'] ?? '') !== 'generation' || !self::mediaBriefMissing($request)) {
            return $decision;
        }

        $missing = array_values(array_unique(array_merge(
            (array)($decision['missing_hard_slots'] ?? []),
            ['prompt_or_brief']
        )));
        $question = self::mediaBriefQuestion($decision);
        $decision['missing_hard_slots'] = $missing;
        $decision['action_mode'] = 'clarify';
        $decision['next_action'] = 'clarify';
        $decision['clarification_question'] = $question;
        $decision['requires_confirmation'] = false;
        $decision['estimated_tools'] = [];
        $decision['runtime_allowed_tools'] = ['ask_user'];

        $contract = (array)($decision['skill_contract'] ?? []);
        if ($contract !== []) {
            $contract['missing_slots'] = $missing;
            $contract['missing_hard_slots'] = $missing;
            $contract['clarification_question'] = $question;
            $contract['execution_confirmed'] = false;
            $contract['allowed_tools'] = ['ask_user'];
            $decision['skill_contract'] = $contract;
        }
        return $decision;
    }

    private static function mediaBriefMissing(string $request): bool
    {
        $subject = preg_replace(
            '/(?:我想|想要|需要|请|帮我|给我|生成|制作|创建|渲染|画一张|画个|做一张|做个|出图|一张|一个|一段|图片|图像|海报|插画|封面|logo|视频|短片|动画|音乐|音频|配乐|image|poster|video|music|generate|make|create|render)/iu',
            '',
            $request
        );
        $subject = preg_replace('/[\s，。！？、,.!?:：；;]+/u', '', (string)$subject);
        return mb_strlen((string)$subject, 'UTF-8') < 2;
    }

    private static function mediaBriefQuestion(array $decision): string
    {
        return match (self::mediaTool($decision)) {
            'generate_video' => '可以。你想生成什么视频？请告诉我主体、场景或想表达的内容。',
            'generate_music' => '可以。你想生成什么音乐？请告诉我风格、情绪或使用场景。',
            default => '可以。你想生成什么图片？请告诉我主体、场景或风格。',
        };
    }

    private static function turnRelation(string $request, array $decision, int $explicitId, array $target = []): string
    {
        $semanticRelation = (string)($decision['semantic_turn_relation'] ?? '');
        if (!in_array($semanticRelation, ['new', 'continue', 'revise', 'confirm', 'retry', 'cancel', 'chat'], true)) {
            $semanticRelation = '';
        }
        if ($explicitId > 0) {
            return in_array($semanticRelation, ['continue', 'revise', 'confirm', 'retry', 'cancel'], true)
                ? $semanticRelation
                : 'continue';
        }
        if ((int)($target['id'] ?? 0) > 0 && $semanticRelation === 'new') return 'new';
        if ($semanticRelation !== '') return $semanticRelation;
        if (empty($decision['semantic_authoritative'])) {
            return self::legacyTurnRelation($request, $decision, $explicitId, $target);
        }
        return !empty($decision['workflow_template']) || !empty($decision['semantic_authoritative']) ? 'new' : 'chat';
    }

    /** Legacy rule route retained only for explicit compatibility callers. */
    private static function legacyTurnRelation(string $request, array $decision, int $explicitId, array $target = []): string
    {
        $text = mb_strtolower(trim($request), 'UTF-8');
        if ((int)($target['id'] ?? 0) > 0 && self::isExplicitNewTask($request)) return 'new';
        if ($explicitId > 0) {
            if (preg_match('/取消|不用|停止|cancel/u', $text) === 1) return 'cancel';
            if (preg_match('/重试|retry/u', $text) === 1) return 'retry';
            if (preg_match('/确认|确定|同意|继续|yes|confirm/u', $text) === 1) return 'confirm';
            if (preg_match('/修改|调整|改成|替换|比例|风格|文案|revise|change/u', $text) === 1) return 'revise';
            return 'continue';
        }
        if (!empty($decision['workflow_template']) || in_array((string)($decision['intent'] ?? ''), ['generation', 'canvas_edit'], true)
            || preg_match('/生成|制作|创建|出图|画一张|做一张|主图|详情页|卖点图|logo|视频|音乐|海报|包装/u', $text) === 1) {
            return 'new';
        }
        return 'chat';
    }

    /**
     * The persisted delivery state is the source of truth for continuation.
     * It is deliberately evaluated before Skill routing so imperative replies
     * such as "继续" cannot fall through to general image generation.
     */
    private static function taskTarget(int $tenantId, array $params, array $semantic = []): array
    {
        $userId = (int)($params['user_id'] ?? 0);
        $threadId = (int)($params['thread_id'] ?? 0);
        if ($userId <= 0 || $threadId <= 0) return [];
        // A bare "regenerate" refers to the latest completed text deliverable
        // when one exists. Do not let an unexecuted media proposal replace it.
        if (!empty($params['_text_retry_context'])) return [];
        $explicitId = (int)($params['delivery_item_id'] ?? 0);
        if ($explicitId > 0) {
            $item = DeliveryPlanService::item($tenantId, $userId, $explicitId);
            if ($item !== [] && (int)($item['thread_id'] ?? 0) === $threadId) {
                return ['id' => $explicitId, 'item' => $item, 'source' => 'explicit_item'];
            }
            return [];
        }
        if ((string)($semantic['turn_relation'] ?? '') === 'new') return [];
        $semanticItemId = max(0, (int)($semantic['target_delivery_item_id'] ?? 0));
        if ($semanticItemId > 0) {
            $item = DeliveryPlanService::item($tenantId, $userId, $semanticItemId);
            if ($item !== []
                && (int)($item['thread_id'] ?? 0) === $threadId
                && !in_array((string)($item['status'] ?? ''), ['completed', 'canceled'], true)) {
                return ['id' => $semanticItemId, 'item' => $item, 'source' => 'semantic_item'];
            }
        }
        // Only the no-model compatibility route may use the former explicit
        // new-task phrase detector. Normal AgentLoop turns always provide an
        // LLM relation above.
        if ((string)($semantic['turn_relation'] ?? '') === ''
            && self::isExplicitNewTask((string)($params['_semantic_request'] ?? ''))) return [];
        $pending = DeliveryPlanService::pendingItems($tenantId, $userId, $threadId);
        if (count($pending) === 1) {
            return ['id' => (int)$pending[0]['id'], 'item' => $pending[0], 'source' => 'unique_pending_action'];
        }
        $next = DeliveryPlanService::nextExecutableItem($tenantId, $userId, $threadId);
        if ($next !== []) return ['id' => (int)$next['id'], 'item' => $next, 'source' => 'next_workflow_stage'];
        return [];
    }

    /**
     * Resolves a bare text regeneration from the actual prior assistant output,
     * not from a speculative delivery item created by an incorrect route.
     */
    private static function textRetryContext(int $tenantId, string $request, array $params): array
    {
        $requestedSourceId = (int)($params['retry_source_message_id'] ?? 0);
        if ($requestedSourceId <= 0 && !self::isTextRetryRequest($request)) return [];
        $userId = (int)($params['user_id'] ?? 0);
        $threadId = (int)($params['thread_id'] ?? 0);
        $messageId = (int)($params['message_id'] ?? 0);
        if ($userId <= 0 || $threadId <= 0) return [];
        try {
            $rows = AigcCanvasAgentMessage::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'thread_id' => $threadId,
                'delete_time' => 0,
            ])->where('id', '<>', $messageId)
                ->when($requestedSourceId > 0, static function ($query) use ($requestedSourceId) {
                    $query->where('id', $requestedSourceId);
                })
                ->order('id', 'desc')->limit($requestedSourceId > 0 ? 1 : 16)->select()->toArray();
        } catch (\Throwable) {
            return [];
        }

        $assistant = [];
        foreach ($rows as $row) {
            if ((string)($row['role'] ?? '') !== 'assistant' || (string)($row['status'] ?? '') !== 'success') continue;
            $content = trim((string)($row['content'] ?? ''));
            if ($content === '' || AgentResponseProtocol::isInternalTrace($content) || self::messageHasActualMedia($row)) continue;
            if (!self::isCompletedTextDelivery($tenantId, $userId, $row)) continue;
            $assistant = $row;
            break;
        }
        if ($assistant === []) return [];

        $sourceRequest = trim((string)($params['retry_source_request'] ?? ''));
        if ($sourceRequest === '') {
            foreach (array_reverse($rows) as $row) {
                if ((int)($row['id'] ?? 0) >= (int)($assistant['id'] ?? 0) || (string)($row['role'] ?? '') !== 'user') continue;
                $candidate = trim((string)($row['content'] ?? ''));
                if (self::isTextDeliverableRequest($candidate)) $sourceRequest = $candidate;
            }
        }
        $reply = trim((string)($assistant['content'] ?? ''));
        if ($sourceRequest === '' && $requestedSourceId <= 0 && !self::looksLikeTextDeliverable($reply)) return [];

        $isScript = preg_match('/脚本|分镜|镜头|台词|场景|剧本|script|storyboard/u', $sourceRequest . "\n" . $reply) === 1;
        return [
            'message_id' => (int)($assistant['id'] ?? 0),
            'source_request' => $sourceRequest !== '' ? $sourceRequest : $reply,
            'skill_key' => $isScript ? 'script_planning' : '',
        ];
    }

    private static function isTextRetryRequest(string $request): bool
    {
        return preg_match('/^\s*(?:重新生成|再生成一次|重新写|重写|换一版|regenerate|rewrite)\s*[。！!？?]*\s*$/iu', $request) === 1;
    }

    private static function isTextDeliverableRequest(string $request): bool
    {
        return preg_match('/脚本|文案|分镜|策划|策略|研究|字幕|台词|剧本|copy|script|storyboard/u', $request) === 1;
    }

    private static function looksLikeTextDeliverable(string $reply): bool
    {
        return preg_match('/脚本|分镜|镜头|台词|场景|策略|文案|剧本|scene|dialogue|shot/u', $reply) === 1;
    }

    private static function messageHasActualMedia(array $message): bool
    {
        $json = (array)($message['content_json'] ?? []);
        if (!empty($json['assets'])) return true;
        foreach (array_merge((array)($json['tool_calls'] ?? []), (array)($json['workspace_actions'] ?? [])) as $entry) {
            if (!is_array($entry)) continue;
            $code = (string)($entry['tool_code'] ?? $entry['code'] ?? $entry['name'] ?? $entry['action_type'] ?? '');
            if (in_array($code, ['generate_image', 'generate_video', 'generate_music', 'add_image', 'add_video', 'add_music'], true)) return true;
        }
        return false;
    }

    /**
     * A completed text delivery is proven by a persisted text action, or by a
     * terminal text result with no media submission. This intentionally ignores
     * an old routing snapshot that disagrees with the actual output.
     */
    private static function isCompletedTextDelivery(int $tenantId, int $userId, array $message): bool
    {
        $messageId = (int)($message['id'] ?? 0);
        if ($messageId <= 0) return false;
        try {
            $hasTextAction = AigcCanvasAgentWorkspaceAction::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'message_id' => $messageId,
                'action_type' => 'insert_text',
                'delete_time' => 0,
            ])->whereIn('status', ['pending', 'applied'])->count() > 0;
        } catch (\Throwable) {
            $hasTextAction = false;
        }
        if ($hasTextAction) return true;

        $json = (array)($message['content_json'] ?? []);
        $decision = (array)($json['task_decision'] ?? []);
        $response = (array)($json['response'] ?? []);
        $responseContext = (array)($response['task_context'] ?? []);
        $intent = (string)($decision['intent'] ?? $responseContext['intent'] ?? '');
        $nextAction = (string)($json['next_action'] ?? $response['next_action'] ?? '');
        return in_array($intent, ['text_generation', 'creative_plan', 'research'], true)
            && ($nextAction === '' || $nextAction === 'chat');
    }

    /** Returns a verified text-delivery message for the user-facing retry API. */
    public static function retrySourceMessageId(int $tenantId, int $userId, int $threadId, int $userMessageId): int
    {
        if ($threadId <= 0 || $userMessageId <= 0) return 0;
        try {
            $rows = AigcCanvasAgentMessage::where([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'thread_id' => $threadId,
                'role' => 'assistant',
                'status' => 'success',
                'delete_time' => 0,
            ])->where('id', '>', $userMessageId)->order('id', 'asc')->limit(4)->select()->toArray();
            foreach ($rows as $row) {
                if (self::messageHasActualMedia($row) || !self::isCompletedTextDelivery($tenantId, $userId, $row)) continue;
                return (int)($row['id'] ?? 0);
            }
        } catch (\Throwable) {
            return 0;
        }
        return 0;
    }

    /** Legacy rule route retained only for explicit compatibility callers. */
    private static function legacyTaskTarget(int $tenantId, string $request, array $params): array
    {
        $userId = (int)($params['user_id'] ?? 0);
        $threadId = (int)($params['thread_id'] ?? 0);
        if ($userId <= 0 || $threadId <= 0) return [];
        $explicitId = (int)($params['delivery_item_id'] ?? 0);
        if ($explicitId > 0) {
            $item = DeliveryPlanService::item($tenantId, $userId, $explicitId);
            if ($item !== [] && (int)($item['thread_id'] ?? 0) === $threadId) {
                return ['id' => $explicitId, 'item' => $item, 'source' => 'explicit_item'];
            }
            return [];
        }
        if (self::isExplicitNewTask($request)) return [];
        $pending = DeliveryPlanService::pendingItems($tenantId, $userId, $threadId);
        if (count($pending) === 1) {
            return ['id' => (int)$pending[0]['id'], 'item' => $pending[0], 'source' => 'unique_pending_action'];
        }
        $next = DeliveryPlanService::nextExecutableItem($tenantId, $userId, $threadId);
        if ($next !== []) return ['id' => (int)$next['id'], 'item' => $next, 'source' => 'next_workflow_stage'];
        return [];
    }

    private static function isExplicitNewTask(string $request): bool
    {
        $text = mb_strtolower(trim($request), 'UTF-8');
        return preg_match('/(?:再|另外|另做|重新)(?:做|生成|设计|创建)|另一个|another\s+(?:logo|image|design)|new\s+(?:logo|package|packaging|image|design)/u', $text) === 1;
    }

    private static function itemIntent(array $item): string
    {
        $meta = (array)($item['meta'] ?? []);
        $stage = (string)($meta['workflow_stage'] ?? '');
        if ($stage === 'research') return 'research';
        if (in_array($stage, ['brief', 'strategy', 'copy', 'visual_direction', 'applications'], true)) return 'creative_plan';
        $tool = (string)($item['tool_code'] ?? '');
        if (in_array($tool, ['generate_image', 'generate_video', 'generate_music'], true)) return 'generation';
        if ($tool === 'canvas_mutation') return 'canvas_edit';
        return 'text_generation';
    }

    private static function workflowTemplate(array $selectedSkill, array $contract, array $router): string
    {
        if ((string)($router['workflow_template'] ?? '') === 'brand_planning'
            && self::authorizesSemanticDecision($router)) {
            return 'brand_planning';
        }
        return (string)($selectedSkill['skill_key'] ?? $contract['skill_key'] ?? '') === 'brand_planning'
            ? 'brand_planning'
            : '';
    }

    /** Legacy template recognition is intentionally not used by AgentLoop turns. */
    private static function legacyWorkflowTemplate(string $request, array $selectedSkill, array $contract, array $router): string
    {
        $text = mb_strtolower(trim($request), 'UTF-8');
        $explicitSkill = (string)($selectedSkill['skill_key'] ?? '') !== '';
        $brandWorkflow = preg_match('/品牌(?:策划|战略|定位|全案|发布方案)|brand\s*(?:strategy|planning|identity)/u', $text) === 1;
        $highConfidence = (float)($router['confidence'] ?? 0) >= 0.9;
        if ($brandWorkflow && ($explicitSkill || $highConfidence || preg_match('/(?:完整|全套|全案|从.*到|阶段|工作流|complete|end-to-end|workflow)/u', $text) === 1)) return 'brand_planning';
        if ($explicitSkill && (string)($contract['skill_key'] ?? '') === 'brand_planning') return 'brand_planning';
        return '';
    }

    private static function semanticTaskContext(int $tenantId, array $params): array
    {
        $userId = (int)($params['user_id'] ?? 0);
        $threadId = (int)($params['thread_id'] ?? 0);
        if ($userId <= 0 || $threadId <= 0) return [];
        $items = DeliveryPlanService::pendingItems($tenantId, $userId, $threadId);
        $next = DeliveryPlanService::nextExecutableItem($tenantId, $userId, $threadId);
        if ($next !== []) $items[] = $next;
        $summary = [];
        foreach ($items as $item) {
            $summary[] = [
                'id' => (int)($item['id'] ?? 0),
                'objective' => mb_substr((string)($item['objective'] ?? ''), 0, 160, 'UTF-8'),
                'skill_key' => (string)($item['skill_key'] ?? ''),
                'status' => (string)($item['status'] ?? ''),
                'pending_action' => (string)(($item['pending_action']['type'] ?? '')),
            ];
        }
        $memory = ConversationMemoryBuilder::build(
            $tenantId,
            $userId,
            $threadId,
            (int)($params['message_id'] ?? 0)
        );
        return [
            'active_items' => array_slice($summary, 0, 3),
            'recent_messages' => array_slice((array)($memory['recent_messages'] ?? []), -6),
        ];
    }

    private static function authorizesSemanticDecision(array $decision): bool
    {
        $source = (string)($decision['source'] ?? $decision['router_source'] ?? '');
        return in_array($source, ['llm', 'reused'], true) && (float)($decision['confidence'] ?? 0) >= 0.7;
    }

    private static function authorizesMedia(array $decision, bool $explicitSkill = false): bool
    {
        return $explicitSkill || !empty($decision['explicit_skill']) || self::authorizesSemanticDecision($decision);
    }

    private static function mediaTool(array $decision): string
    {
        $contract = (array)($decision['skill_contract'] ?? []);
        $tools = array_merge(
            (array)($contract['allowed_tools'] ?? []),
            (array)($contract['capability_tools']['costly'] ?? []),
            (array)($decision['runtime_allowed_tools'] ?? [])
        );
        foreach (['generate_image', 'generate_video', 'generate_music'] as $tool) {
            if (in_array($tool, $tools, true)) return $tool;
        }
        return match ((string)($decision['selected_skill_key'] ?? '')) {
            'video_generation', 'image_to_video' => 'generate_video',
            'music_generation' => 'generate_music',
            'general_image', 'logo_design', 'poster_design' => 'generate_image',
            default => '',
        };
    }

    private static function withoutMediaTools(array $tools): array
    {
        return array_values(array_filter($tools, static fn($tool): bool => !in_array((string)$tool, ['generate_image', 'generate_video', 'generate_music'], true)));
    }

    /** Legacy keyword compiler retained only for callers without task_decision. */
    private static function legacyDeliverySpecs(string $request, array $context, array $decision): array
    {
        $text = mb_strtolower(trim($request), 'UTF-8');
        $hasReference = !empty($context['uploaded_references']) || !empty($context['selected_elements']) || !empty($context['selection']['elements']);
        $items = [];
        $ratio = preg_match('/\b(1:1|3:4|4:3|9:16|16:9)\b/u', $text, $match) === 1 ? (string)$match[1] : '';
        $add = static function (string $key, string $objective, string $skill, string $tool, array $delivery, bool $requiresReference = false) use (&$items, $request): void {
            $items[] = [
                'item_key' => $key,
                'objective' => $objective,
                'skill_key' => $skill,
                'tool_code' => $tool,
                'required_slots' => $requiresReference ? ['product_reference'] : [],
                'soft_slots' => $skill === 'ecommerce_selling_point' ? ['selling_points'] : [],
                'missing_slots' => [],
                'slots' => ['user_request' => $request],
                'delivery' => $delivery,
                'reference_assets' => [],
                'pending_action' => PendingActionProtocol::confirmation(),
                'meta' => ['safe_generation_when_soft_slots_missing' => true],
            ];
        };
        if (preg_match('/主图|白底图|首图|商品展示图/u', $text) === 1) $add('main_image', '生成单张商品主图', 'ecommerce_main_image', 'generate_image', ['type' => 'ecommerce_main_image', 'purpose' => '商品主图', 'ratio' => $ratio], true);
        if (preg_match('/卖点图|功能图|场景图|对比图/u', $text) === 1) $add('selling_point', '生成商品卖点图', 'ecommerce_selling_point', 'generate_image', ['type' => 'ecommerce_selling_point', 'purpose' => '商品卖点图', 'ratio' => $ratio], true);
        if (preg_match('/详情页|详情图|详情长图|多屏|一套|\d+\s*(?:屏|页)/u', $text) === 1) $add('detail_page', '规划商品详情页', 'ecommerce_detail_page', 'generate_image', ['type' => 'ecommerce_detail_page', 'purpose' => '商品详情页'], true);
        if (preg_match('/视频|短片|动画|图生视频/u', $text) === 1) $add('video', '生成视频创作', 'video_generation', 'generate_video', ['type' => 'video', 'purpose' => '视频创作', 'ratio' => $ratio]);
        if (preg_match('/音乐|配乐|音频|歌曲/u', $text) === 1) $add('music', '生成音乐创作', 'music_generation', 'generate_music', ['type' => 'music', 'purpose' => '音乐创作']);
        if (preg_match('/移动|删除|复制|对齐|编组|画布编辑|调整画布/u', $text) === 1) $add('canvas_edit', '编辑画布内容', 'canvas_editing', 'canvas_mutation', ['type' => 'canvas_edit', 'purpose' => '画布编辑']);
        if ($items === [] && !empty($decision['intent']) && (string)$decision['intent'] === 'generation') {
            $skill = (string)($decision['selected_skill_key'] ?? 'general_image');
            $tool = str_contains($skill, 'video') ? 'generate_video' : (str_contains($skill, 'music') ? 'generate_music' : 'generate_image');
            $add('media', '生成创作素材', $skill, $tool, ['type' => str_replace('generate_', '', $tool), 'purpose' => '创作素材', 'ratio' => $ratio]);
        }
        foreach ($items as $index => &$item) {
            $item['item_key'] .= '_' . ($index + 1);
            if (in_array('product_reference', (array)$item['required_slots'], true) && !$hasReference) {
                $item['missing_slots'] = ['product_reference'];
                $item['pending_action'] = PendingActionProtocol::forMissingSlots(['product_reference']);
            }
        }
        unset($item);
        return $items;
    }

    /** Keep a disabled rollout on the former strict automatic-Skill path. */
    private static function bindingMode(bool $bindingEnabled, bool $explicit, array $policy): string
    {
        if ($explicit || !$bindingEnabled) {
            return 'contract';
        }
        $mode = (string)($policy['default_mode'] ?? 'advisory');
        return in_array($mode, ['none', 'advisory', 'contract'], true) ? $mode : 'advisory';
    }

    /** No-binding turns may inspect context and plan, never mutate or bill. */
    private static function lowRiskTools(): array
    {
        return [
            'ask_user', 'retrieve_skills', 'canvas_query', 'canvas_read', 'canvas_select',
            'asset_analyze', 'web_fetch', 'search_web_info', 'search_image',
            'brand_research', 'url_to_design_brief', 'generate_text',
        ];
    }

    private static function candidates(int $tenantId, string $request, array $context): array
    {
        $ranked = [];
        $needle = mb_strtolower($request, 'UTF-8');
        foreach (AigcCanvasSkillService::routerSkills($tenantId, 80) as $skill) {
            $score = 0;
            $key = (string)($skill['skill_key'] ?? '');
            $haystack = mb_strtolower(implode(' ', [
                $key, (string)($skill['name'] ?? ''), (string)($skill['description'] ?? ''), (string)($skill['trigger_description'] ?? ''),
            ]), 'UTF-8');
            foreach (preg_split('/[\s,，。！？!?、]+/u', $needle) ?: [] as $word) {
                if (mb_strlen($word, 'UTF-8') >= 2 && str_contains($haystack, $word)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $ranked[] = ['score' => $score, 'skill' => $skill];
            }
        }
        usort($ranked, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);
        return array_values(array_map(static fn(array $item): array => (array)$item['skill'], array_slice($ranked, 0, 5)));
    }

    private static function semanticCandidates(int $tenantId, array $ranked, array $selectedSkill = []): array
    {
        $items = [];
        foreach (array_merge([$selectedSkill], $ranked, array_slice(AigcCanvasSkillService::routerSkills($tenantId, 40), 0, 40)) as $skill) {
            $key = (string)($skill['skill_key'] ?? '');
            if ($key !== '') $items[$key] = $skill;
        }
        return array_values($items);
    }

    private static function requiresSemanticClarification(array $router, bool $routerEnabled = false): bool
    {
        $source = (string)($router['source'] ?? '');
        return $source === 'llm_low_confidence' || ($routerEnabled && $source === 'fallback');
    }

    /**
     * Visual enrichment changes slot values, not the meaning of the user's
     * request. Reuse this turn's first LLM routing result while recompiling
     * the Skill against the enriched context, avoiding a second model call.
     */
    private static function reusedRouter(array $params, array $candidates): array
    {
        $semantic = is_array($params['semantic_decision'] ?? null) ? $params['semantic_decision'] : [];
        if ($semantic !== []) {
            $candidateKeys = array_values(array_filter(array_map(static fn(array $item): string => (string)($item['skill_key'] ?? ''), $candidates)));
            $skillKey = trim((string)($semantic['selected_skill_key'] ?? ''));
            $intent = trim((string)($semantic['intent'] ?? ''));
            $relation = trim((string)($semantic['turn_relation'] ?? ''));
            $mode = trim((string)($semantic['execution_mode'] ?? ''));
            $confidence = max(0.0, min(1.0, (float)($semantic['confidence'] ?? 0.0)));
            if (!in_array($intent, ['chat', 'creative_plan', 'text_generation', 'generation', 'canvas_edit', 'research'], true)
                || !in_array($relation, ['new', 'continue', 'revise', 'confirm', 'retry', 'cancel', 'chat'], true)
                || !in_array($mode, ['reply', 'clarify', 'plan', 'execute'], true)
                || $confidence < 0.55
                || ($skillKey !== '' && !in_array($skillKey, $candidateKeys, true))) {
                return [];
            }
            return [
                'turn_relation' => $relation,
                'target_delivery_item_id' => max(0, (int)($semantic['target_delivery_item_id'] ?? 0)),
                'intent' => $intent,
                'execution_mode' => $mode,
                'selected_skill_key' => $skillKey,
                'workflow_template' => (string)($semantic['workflow_template'] ?? '') === 'brand_planning' ? 'brand_planning' : '',
                'confidence' => $confidence,
                'source' => 'reused',
            ];
        }
        $skillKey = trim((string)($params['resolved_skill_key'] ?? ''));
        $candidateKeys = array_values(array_filter(array_map(static fn(array $item): string => (string)($item['skill_key'] ?? ''), $candidates)));
        if ($skillKey === '' || !in_array($skillKey, $candidateKeys, true)) {
            return [];
        }
        $intent = trim((string)($params['resolved_intent'] ?? ''));
        if (!in_array($intent, ['chat', 'creative_plan', 'text_generation', 'generation', 'canvas_edit', 'research'], true)) {
            $intent = '';
        }
        return [
            'turn_relation' => '',
            'target_delivery_item_id' => 0,
            'intent' => $intent,
            'execution_mode' => '',
            'selected_skill_key' => $skillKey,
            'workflow_template' => '',
            'confidence' => max(0.0, min(1.0, (float)($params['resolved_confidence'] ?? 0.0))),
            'source' => 'reused',
        ];
    }

    private static function preferredSkillKey(string $request, array $context): string
    {
        $text = mb_strtolower($request, 'UTF-8');
        if (preg_match('/详情|详情页|详情图|详情长图|商品长图/u', $text) === 1) return 'ecommerce_detail_page';
        if (!empty($context['uploaded_references']) && preg_match('/电商|商品图|主图|卖点图|产品图|listing|product\s*image/u', $text) === 1) {
            return 'ecommerce_main_image';
        }
        if (!empty($context['selected_elements']) && preg_match('/替换|修改|换成|背景|白底|编辑/u', $text) === 1) return 'image_edit';
        if (preg_match('/海报|poster|kv|封面/u', $text) === 1) return 'poster_design';
        if (preg_match('/脚本|文案|分镜|策划|方案|copy/u', $text) === 1) return 'script_planning';
        if (preg_match('/视频|短片|动画|video/u', $text) === 1) return 'video_generation';
        if (preg_match('/音乐|配乐|bgm|music/u', $text) === 1) return 'music_generation';
        if (preg_match('/图片|图像|插画|image/u', $text) === 1) return 'general_image';
        if (preg_match('/生成|制作|创建|出图|插入画布/u', $text) === 1) return 'general_image';
        return '';
    }

    private static function upgradeReasons(string $request, array $context, array $contract): array
    {
        $text = mb_strtolower($request, 'UTF-8');
        $reasons = [];
        $policy = (array)($contract['binding_policy'] ?? []);
        $allowed = (array)($policy['upgrade_to_contract_on'] ?? ['paid_generation', 'batch', 'canvas_write']);
        $costly = (array)($contract['capability_tools']['costly'] ?? []);
        $isGeneration = $costly !== [] && preg_match('/生成|制作|创建|出图|生成图|render|generate|(?:做|画).*(?:张|图|海报|视频)/u', $text) === 1;
        $isVisualRevision = (!empty($context['uploaded_references']) || !empty($context['selected_elements']) || !empty($context['selected_ids']))
            && preg_match('/修改|调整|改成|换成|重新做|重做|太亮|太暗|深色|浅色|色系|颜色|风格|darker|brighter|color\s*scheme|change|revise|edit/u', $text) === 1;
        if (in_array('paid_generation', $allowed, true) && ($isGeneration || $isVisualRevision)) $reasons[] = 'paid_generation';
        $isBatchContract = !empty($contract['output_policy']['batch_mode']);
        if (in_array('batch', $allowed, true) && $isGeneration && ($isBatchContract || preg_match('/(?:[2-9]|[1-9]\d+)\s*(?:张|幅|个|套|批)/u', $text) === 1)) {
            $reasons[] = 'batch';
        }
        if (in_array('canvas_write', $allowed, true) && (
            !empty($context['selected_elements']) || preg_match('/插入画布|写入画布|替换|修改|移动|删除|分组|选中/u', $text) === 1
        )) $reasons[] = 'canvas_write';
        return array_values(array_unique($reasons));
    }

    private static function intent(string $request, array $context, array $contract): string
    {
        $text = mb_strtolower($request, 'UTF-8');
        if (!empty($context['selected_elements']) && preg_match('/替换|修改|换成|背景|编辑/u', $text) === 1) return 'canvas_edit';
        if (preg_match('/想想|方向|灵感|方案|策划|文案|脚本/u', $text) === 1) return 'creative_plan';
        if (array_intersect((array)($contract['capability_tools']['costly'] ?? []), ['generate_image', 'generate_video', 'generate_music'])) return 'generation';
        return 'chat';
    }

    private static function actionMode(string $intent, string $risk, array $missing, bool $requiresConfirmation, bool $confirmed): string
    {
        if ($missing !== []) return 'clarify';
        if ($requiresConfirmation && !$confirmed) return 'confirm';
        if (in_array($intent, ['creative_plan', 'research'], true)) return 'plan';
        if ($intent === 'generation') return 'execute';
        if ($confirmed && $risk !== 'low') return 'execute';
        if ($intent === 'text_generation' && $missing === []) return 'execute';
        return 'reply';
    }

    /**
     * Semantic routing can make a turn more conservative, never less. An LLM
     * execute result must not bypass deterministic slot, payment, or
     * confirmation checks.
     */
    private static function constrainActionMode(string $actionMode, string $intent, array $router): string
    {
        if (!self::authorizesSemanticDecision($router)) return $actionMode;
        $semanticMode = (string)($router['execution_mode'] ?? '');
        if ($semanticMode === 'clarify') return 'clarify';
        if ($semanticMode === 'plan' && $actionMode === 'reply' && in_array($intent, ['creative_plan', 'research'], true)) {
            return 'plan';
        }
        if ($semanticMode === 'reply' && $intent === 'chat') return 'reply';
        return $actionMode;
    }

    private static function risk(array $upgrades): string
    {
        if (in_array('batch', $upgrades, true)) return 'batch';
        if (in_array('paid_generation', $upgrades, true)) return 'costly';
        if (in_array('canvas_write', $upgrades, true)) return 'canvas_write';
        return 'low';
    }

    private static function target(array $context): array
    {
        $selected = array_values(array_filter((array)($context['selected_ids'] ?? []), static fn($id): bool => (string)$id !== ''));
        if ($selected !== []) return ['kind' => 'selection', 'ids' => array_slice(array_map('strval', $selected), 0, 24)];
        if (!empty($context['uploaded_references'])) return ['kind' => 'uploaded_asset', 'ids' => []];
        return ['kind' => !empty($context['project']['id']) ? 'project' : 'none', 'ids' => []];
    }

    private static function confidence(string $request, array $contract): float
    {
        return (string)($contract['skill_key'] ?? '') === self::preferredSkillKey($request, []) ? 0.9 : 0.68;
    }

    private static function pendingContext($value): array
    {
        if (is_string($value)) $value = json_decode($value, true);
        if (!is_array($value)) return [];
        return [
            'skill_key' => trim((string)($value['skill_key'] ?? '')),
            'original_request' => trim((string)($value['original_request'] ?? '')),
            'confirmed' => !empty($value['confirmed']),
            'delivery_item_id' => max(0, (int)($value['delivery_item_id'] ?? 0)),
        ];
    }

    private static function estimateTools(int $tenantId, string $request, array $contract): array
    {
        $quantity = 1;
        if (preg_match('/([1-9]\d*)\s*(?:张|幅|个|套|批)/u', $request, $match) === 1) {
            $quantity = max(1, min(50, (int)$match[1]));
        }
        $result = [];
        foreach ((array)($contract['capability_tools']['costly'] ?? []) as $toolCode) {
            try {
                $estimate = CanvasGenerationPricingService::estimate($tenantId, (string)$toolCode, ['quantity' => $quantity]);
                $result[] = [
                    'tool_code' => (string)$toolCode,
                    'available' => !empty($estimate['available']),
                    'quantity' => (float)($estimate['quantity'] ?? $quantity),
                    'estimated_points' => (float)($estimate['estimated_points'] ?? 0),
                    'estimated_time' => (int)($estimate['estimated_time'] ?? 0),
                    'requires_confirmation' => true,
                ];
            } catch (\Throwable) {
                $result[] = ['tool_code' => (string)$toolCode, 'available' => false, 'quantity' => $quantity, 'estimated_points' => 0, 'estimated_time' => 0, 'requires_confirmation' => true];
            }
        }
        return $result;
    }
}
