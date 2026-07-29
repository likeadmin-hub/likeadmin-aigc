<?php

namespace app\common\service\app\aigc_canvas\agent\runtime;

use app\common\service\app\aigc_canvas\AigcCanvasSkillService;
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
        $deliveryItemId = (int)($params['delivery_item_id'] ?? 0);
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
        $router = ['intent' => '', 'selected_skill_key' => '', 'confidence' => 0.0, 'source' => 'rules'];
        $reusedRouter = !$explicit && empty($pending['skill_key'])
            ? self::reusedRouter($params, $candidates)
            : [];
        if ($reusedRouter !== []) {
            $router = $reusedRouter;
        } elseif (!$explicit && empty($pending['skill_key']) && !empty($params['agent_intent_router_enabled']) && $candidates !== []) {
            $router = AgentIntentRouter::resolve(
                $tenantId,
                (int)($params['user_id'] ?? 0),
                (int)($params['project_id'] ?? 0),
                (int)($params['thread_id'] ?? 0),
                (int)($params['message_id'] ?? 0),
                $request,
                $context,
                $candidates
            );
            if (!empty($router['selected_skill_key'])) {
                foreach ($candidates as $candidate) {
                    if ((string)($candidate['skill_key'] ?? '') === (string)$router['selected_skill_key']) {
                        $skill = $candidate;
                        break;
                    }
                }
            }
        }
        if ($skill === []) {
            $skill = (array)($candidates[0] ?? []);
        }
        if ($skill === []) {
            if (!empty($params['agent_generic_capability_fallback_enabled'])) {
                $generic = GenericCapabilityContractFactory::create($tenantId, $effectiveRequest, $context);
                if ($generic !== []) {
                    return self::genericDecision($tenantId, $effectiveRequest, $context, $generic, $confirmed, $bindingEnabled, $deliveryItemId);
                }
            }
            return self::noneDecision($bindingEnabled, $context, $deliveryItemId);
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

        $intent = (string)($router['intent'] ?? '') ?: self::intent($request, $context, $contract);
        $risk = self::risk($upgrades);
        $actionMode = self::actionMode($intent, $risk, $missing, $requiresConfirmation, $confirmed);
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

        return [
            'delivery_item_id' => $deliveryItemId,
            'operation' => self::operation($deliveryItemId, $intent, $actionMode),
            'intent' => $intent,
            'action_mode' => $actionMode,
            'binding_mode' => $mode,
            'target' => self::target($context),
            'risk' => $risk,
            'selected_skill_key' => (string)($contract['skill_key'] ?? ''),
            'selected_skill_name' => (string)($contract['name'] ?? ''),
            'skill_candidates' => array_values(array_filter(array_map(static fn(array $item): string => (string)($item['skill_key'] ?? ''), $candidates))),
            'router_source' => $explicit || !empty($pending['skill_key']) ? 'rules' : (string)($router['source'] ?? 'rules'),
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
        ];
    }

    private static function noneDecision(bool $bindingEnabled = true, array $context = [], int $deliveryItemId = 0): array
    {
        return [
            'delivery_item_id' => $deliveryItemId, 'operation' => 'reply', 'intent' => 'chat', 'action_mode' => 'reply', 'binding_mode' => 'none', 'target' => self::target($context), 'risk' => 'low', 'skill_candidates' => [], 'router_source' => 'fallback', 'selected_skill_key' => '', 'selected_skill_name' => '',
            'confidence' => 0.0, 'missing_hard_slots' => [], 'inferred_slots' => [], 'default_slots' => [],
            'default_sources' => [], 'clarification_question' => '', 'next_action' => 'reply',
            'upgrade_reasons' => [], 'requires_confirmation' => false, 'estimated_tools' => [], 'pending_context' => [],
            'runtime_allowed_tools' => self::lowRiskTools(), 'binding_rollout_enabled' => $bindingEnabled, 'skill_contract' => [],
        ];
    }

    private static function genericDecision(int $tenantId, string $request, array $context, array $contract, bool $confirmed, bool $bindingEnabled, int $deliveryItemId = 0): array
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
        return [
            'delivery_item_id' => $deliveryItemId,
            'operation' => self::operation($deliveryItemId, $isMedia ? 'generation' : ($capability === 'canvas_mutation' ? 'canvas_edit' : 'text_generation'), $actionMode),
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
        ];
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
        $preferred = self::preferredSkillKey($request, $context);
        $ranked = [];
        $needle = mb_strtolower($request, 'UTF-8');
        foreach (AigcCanvasSkillService::routerSkills($tenantId, 80) as $skill) {
            $key = (string)($skill['skill_key'] ?? '');
            $score = $key === $preferred ? 100 : 0;
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

    /**
     * Visual enrichment changes slot values, not the meaning of the user's
     * request. Reuse this turn's first LLM routing result while recompiling
     * the Skill against the enriched context, avoiding a second model call.
     */
    private static function reusedRouter(array $params, array $candidates): array
    {
        $skillKey = trim((string)($params['resolved_skill_key'] ?? ''));
        $candidateKeys = array_values(array_filter(array_map(static fn(array $item): string => (string)($item['skill_key'] ?? ''), $candidates)));
        if ($skillKey === '' || !in_array($skillKey, $candidateKeys, true)) {
            return [];
        }
        $intent = trim((string)($params['resolved_intent'] ?? ''));
        if (!in_array($intent, ['chat', 'creative_plan', 'generation', 'canvas_edit', 'research', 'revision'], true)) {
            $intent = '';
        }
        return [
            'intent' => $intent,
            'selected_skill_key' => $skillKey,
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
        if (!empty($context['uploaded_references']) && self::hasFunctionalSellingPoint($text)) {
            return 'ecommerce_selling_point';
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
        $isProductSellingPoint = (string)($contract['skill_key'] ?? '') === 'ecommerce_selling_point'
            && !empty($context['uploaded_references'])
            && self::hasFunctionalSellingPoint($text);
        $isVisualRevision = (!empty($context['uploaded_references']) || !empty($context['selected_elements']) || !empty($context['selected_ids']))
            && preg_match('/修改|调整|改成|换成|重新做|重做|太亮|太暗|深色|浅色|色系|颜色|风格|darker|brighter|color\s*scheme|change|revise|edit/u', $text) === 1;
        if (in_array('paid_generation', $allowed, true) && ($isGeneration || $isProductSellingPoint || $isVisualRevision)) $reasons[] = 'paid_generation';
        $isBatchContract = !empty($contract['output_policy']['batch_mode']);
        if (in_array('batch', $allowed, true) && $isGeneration && ($isBatchContract || preg_match('/(?:[2-9]|[1-9]\d+)\s*(?:张|幅|个|套|批)/u', $text) === 1)) {
            $reasons[] = 'batch';
        }
        if (in_array('canvas_write', $allowed, true) && (
            !empty($context['selected_elements']) || preg_match('/插入画布|写入画布|替换|修改|移动|删除|分组|选中/u', $text) === 1
        )) $reasons[] = 'canvas_write';
        return array_values(array_unique($reasons));
    }

    private static function hasFunctionalSellingPoint(string $text): bool
    {
        return preg_match('/\x{5356}\x{70B9}|\x{7A81}\x{51FA}|\x{7279}\x{70B9}|\x{4F18}\x{52BF}|\x{7EED}\x{822A}|\x{6750}\x{8D28}|\x{529F}\x{80FD}|\x{964D}\x{566A}/u', $text) === 1;
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
        if ($confirmed && $risk !== 'low') return 'execute';
        if ($intent === 'generation' && !$requiresConfirmation) return 'execute';
        if ($intent === 'text_generation' && $missing === []) return 'execute';
        return 'reply';
    }

    private static function operation(int $deliveryItemId, string $intent, string $actionMode): string
    {
        if ($deliveryItemId > 0) return in_array($actionMode, ['execute', 'confirm'], true) ? 'execute' : 'continue';
        if (in_array($intent, ['generation', 'canvas_edit'], true)) return 'create';
        return $actionMode === 'clarify' ? 'clarify' : 'reply';
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
