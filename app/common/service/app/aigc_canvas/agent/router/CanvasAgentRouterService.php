<?php

namespace app\common\service\app\aigc_canvas\agent\router;

use app\common\service\app\aigc_canvas\AigcCanvasSkillService;
use app\common\service\app\aigc_canvas\agent\billing\CanvasAgentQueuePolicyService;
use app\common\service\app\aigc_canvas\agent\delivery\CanvasDeliveryPlannerService;
use app\common\service\app\aigc_canvas\agent\model\CanvasGenerationPricingService;
use app\common\service\app\aigc_canvas\agent\orchestrator\DesignAgentOrchestrator;
use app\common\service\app\aigc_canvas\agent\planning\EcommerceDetailSectionPlanner;

final class CanvasAgentRouterService
{
    use RouterSupport;

    public static function route(int $tenantId, int $userId, array $params, string $content, array $context): array
    {
        $pendingContext = ClarificationManager::normalizePendingSkillContext($params['pending_skill_context'] ?? []);

        $manualDbSkill = SkillRetriever::resolveManualSkill($tenantId, $params);
        if ($manualDbSkill !== []) {
            return self::buildSkillRoute($tenantId, $userId, $manualDbSkill, $content, $context, $pendingContext, [
                'matched' => true,
                'manual' => true,
                'confidence' => 1,
            ]);
        }

        $pendingSkill = SkillRetriever::resolvePendingSkill($tenantId, $pendingContext);
        if ($pendingSkill !== []) {
            return self::buildSkillRoute($tenantId, $userId, $pendingSkill, $content, $context, $pendingContext, [
                'matched' => true,
                'confidence' => 1,
                'reason' => 'pending_clarification',
            ]);
        }

        $intent = IntentClassifier::classify($tenantId, $userId, $params, $content, $context);
        if (($intent['next_action'] ?? '') === 'local_reply') {
            return [
                'matched' => true,
                'skill_code' => 'chat',
                'skill_key' => '',
                'intent' => 'chat',
                'confidence' => (float)($intent['confidence'] ?? 1),
                'reason' => (string)($intent['reason'] ?? 'simple_chat_rule'),
                'slots' => [],
                'missing_slots' => [],
                'next_action' => 'local_reply',
                'clarify_question' => '',
                'pending_skill_context' => [],
                'reply' => (string)($intent['reply'] ?? ''),
            ];
        }
        if (($intent['intent'] ?? '') === 'compound_plan_media') {
            return [
                'matched' => true,
                'skill_code' => 'creative_plan',
                'media_skill_code' => (string)($intent['media_skill_code'] ?? 'generate_image'),
                'intent' => 'compound_plan_media',
                'confidence' => (float)($intent['confidence'] ?? 0.92),
                'reason' => (string)($intent['reason'] ?? 'compound_planning_media_rule'),
                'slots' => [],
                'missing_slots' => [],
                'next_action' => (string)($intent['next_action'] ?? 'compound_plan_image'),
                'clarify_question' => '',
                'pending_skill_context' => [],
            ];
        }

        if (CanvasDeliveryPlannerService::supports($content)) {
            $deliveryPlan = CanvasDeliveryPlannerService::plan($tenantId, $userId, $content, $context);
            return [
                'matched' => true,
                'skill_code' => 'creative_plan',
                'skill_key' => 'multi_asset_delivery',
                'intent' => 'delivery_plan',
                'confidence' => 0.9,
                'reason' => 'delivery_planner_rule',
                'slots' => [],
                'missing_slots' => [],
                'next_action' => 'delivery_plan',
                'clarify_question' => '',
                'pending_skill_context' => [],
                'delivery_plan' => $deliveryPlan,
                'estimated_tools' => (array)($deliveryPlan['estimated_tools'] ?? []),
            ];
        }

        $retrieved = SkillRetriever::retrieve($tenantId, $userId, $content, $context, $pendingContext);
        if (!empty($retrieved['skill'])) {
            $dbRoute = self::buildSkillRoute($tenantId, $userId, $retrieved['skill'], $content, $context, $pendingContext, (array)($retrieved['router_json'] ?? []));
            $dbSkillKey = (string)($dbRoute['skill_key'] ?? '');
            if (DesignAgentOrchestrator::supports($content) && in_array($dbSkillKey, ['general_chat', 'script_planning', 'creative_plan'], true)) {
                return self::designAgentRoute();
            }
            return $dbRoute;
        }

        if (DesignAgentOrchestrator::supports($content)) {
            return self::designAgentRoute();
        }

        $skillCode = (string)($intent['skill_code'] ?? 'creative_plan');
        return [
            'matched' => false,
            'skill_code' => $skillCode,
            'intent' => (string)($intent['intent'] ?? $skillCode),
            'confidence' => (float)($intent['confidence'] ?? 0),
            'reason' => (string)($intent['reason'] ?? ''),
            'slots' => [],
            'missing_slots' => [],
            'next_action' => $skillCode === 'creative_plan' ? 'chat' : 'execute_tool',
            'clarify_question' => '',
            'pending_skill_context' => [],
        ];
    }

    private static function buildSkillRoute(int $tenantId, int $userId, array $skill, string $content, array $context, array $pendingContext = [], array $routerJson = []): array
    {
        $skill = AigcCanvasSkillService::formatSkill($skill, true);
        $slots = SlotFiller::fill($skill, $content, $context, $pendingContext, $routerJson);
        $defaults = is_array($skill['defaults_json'] ?? null) ? $skill['defaults_json'] : [];
        $effectiveSlots = array_merge($defaults, $slots);
        $skillKey = (string)($skill['skill_key'] ?? '');
        $isEcommerce = $skillKey === 'ecommerce_detail_page';

        if ($isEcommerce) {
            $pendingSlots = is_array($pendingContext['slots'] ?? null) ? $pendingContext['slots'] : [];
            $savedSections = EcommerceDetailSectionPlanner::normalizeSections((array)($pendingContext['detail_sections'] ?? $pendingSlots['detail_sections'] ?? []));
            $isConfirmation = self::isExecutionConfirmation($content);
            $originalRequest = trim((string)($pendingContext['original_request'] ?? ''));
            $planningContent = !$isConfirmation && $originalRequest !== ''
                ? $originalRequest . "\n\n用户补充或修改：\n" . $content
                : $content;
            $referenceImages = self::referenceImagesFromReferences(array_values(array_merge(
                (array)($pendingContext['uploaded_references'] ?? []),
                (array)($context['uploaded_references'] ?? [])
            )));
            $missingBeforePlanning = SlotFiller::missingRequiredSlots($skill, $slots, $context);
            $plan = !empty($savedSections) && $isConfirmation
                ? [
                    'section_count' => count($savedSections),
                    'recommended_section_count' => count($savedSections),
                    'count_reason' => (string)($pendingContext['count_reason'] ?? 'pending_confirmation'),
                    'detail_sections' => $savedSections,
                    'design_analysis' => is_array($pendingSlots['design_analysis'] ?? null) ? $pendingSlots['design_analysis'] : [],
                    'analysis_source' => (string)($pendingSlots['analysis_source'] ?? 'pending_confirmation'),
                ]
                : ($missingBeforePlanning === []
                    ? EcommerceDetailSectionPlanner::resolve($tenantId, $userId, $planningContent, $effectiveSlots, $referenceImages)
                    : []);
            $effectiveSlots = array_merge($effectiveSlots, $plan);
            $slots = array_merge($slots, $plan);
        }

        $missing = SlotFiller::missingRequiredSlots($skill, $slots, $context);
        $skillType = (string)($skill['skill_type'] ?? '');
        $intent = $skillType === 'agent_workflow'
            ? 'skill_execution'
            : (string)($routerJson['intent'] ?? SlotFiller::inferIntentFromSkill($skill));
        $needsConfirmation = $isEcommerce && empty($missing) && (int)($effectiveSlots['section_count'] ?? 0) > 0;
        $nextAction = !empty($missing)
            ? 'clarify'
            : ($needsConfirmation
                ? 'confirm_execution'
                : ($skillType === 'agent_workflow'
                    ? 'skill_execution'
                    : (SlotFiller::toolIntentIsText($intent, $skill) ? 'chat' : 'execute_tool')));
        $estimatedTools = empty($missing) ? self::estimateGenerationTools($tenantId, $skill, $effectiveSlots) : [];

        return [
            'matched' => true,
            'db_skill' => $skill,
            'skill_code' => $skillKey,
            'skill_key' => $skillKey,
            'intent' => $intent,
            'confidence' => (float)($routerJson['confidence'] ?? 1),
            'reason' => (string)($routerJson['reason'] ?? ''),
            'slots' => $effectiveSlots,
            'raw_slots' => $slots,
            'missing_slots' => $missing,
            'next_action' => $nextAction,
            'estimated_tools' => $estimatedTools,
            'clarify_question' => !empty($missing) ? ClarificationManager::resolveQuestion($tenantId, $userId, $skill, $missing, $content, $effectiveSlots, $context, $pendingContext, $routerJson) : '',
            'confirmation_message' => $needsConfirmation ? ClarificationManager::confirmationMessage($effectiveSlots, $context) : '',
            'pending_skill_context' => ClarificationManager::buildPendingContext($skill, $effectiveSlots, $missing, $needsConfirmation, $content, $context, $pendingContext),
            'uploaded_references' => array_values(array_merge(
                (array)($pendingContext['uploaded_references'] ?? []),
                (array)($context['uploaded_references'] ?? [])
            )),
        ];
    }

    private static function estimateGenerationTools(int $tenantId, array $skill, array $slots): array
    {
        $policy = is_array($skill['tool_policy_json'] ?? null) ? $skill['tool_policy_json'] : [];
        $allowedTools = array_values(array_filter(array_map('strval', (array)($policy['allowed_tools'] ?? []))));
        $tools = array_values(array_intersect($allowedTools, ['generate_image', 'generate_video', 'generate_music']));
        $result = [];
        foreach ($tools as $toolCode) {
            $params = self::estimateParamsForTool($toolCode, $slots);
            $estimate = CanvasGenerationPricingService::estimate($tenantId, $toolCode, $params);
            $queue = CanvasAgentQueuePolicyService::resolve($estimate, $params);
            $result[] = [
                'tool_code' => $toolCode,
                'available' => !empty($estimate['available']),
                'quantity' => (float)($estimate['quantity'] ?? $params['quantity'] ?? 1),
                'estimated_points' => (float)($estimate['estimated_points'] ?? 0),
                'estimated_time' => (int)($estimate['estimated_time'] ?? 0),
                'requires_confirmation' => !empty($estimate['requires_confirmation']),
                'queue' => $queue,
                'queue_type' => (string)($queue['queue_type'] ?? ''),
                'queue_label' => (string)($queue['label'] ?? ''),
                'message' => (string)($estimate['message'] ?? ''),
                'price_source' => (string)($estimate['price_source'] ?? ''),
            ];
        }
        return $result;
    }

    private static function estimateParamsForTool(string $toolCode, array $slots): array
    {
        $quantity = (int)($slots['quantity'] ?? 0);
        if ($quantity <= 0 && $toolCode === 'generate_image') {
            $quantity = (int)($slots['section_count'] ?? 1);
        }
        $params = [
            'quantity' => max(1, $quantity),
            'ratio' => (string)($slots['ratio'] ?? ''),
            'quality' => (string)($slots['quality'] ?? $slots['resolution'] ?? ''),
            'duration' => (int)($slots['duration'] ?? 0),
        ];
        foreach (['market_product_id', 'market_sku_id', 'sku_id', 'channel', 'model_id', 'resource_type'] as $key) {
            if (isset($slots[$key]) && $slots[$key] !== '') {
                $params[$key] = $slots[$key];
            }
        }
        return $params;
    }

    private static function designAgentRoute(): array
    {
        return [
            'matched' => true,
            'skill_code' => 'creative_plan',
            'intent' => 'design_agent',
            'confidence' => 0.95,
            'reason' => 'design_agent_rule',
            'slots' => [],
            'missing_slots' => [],
            'next_action' => 'insert_canvas',
            'clarify_question' => '',
            'pending_skill_context' => [],
        ];
    }

    private static function referenceImagesFromReferences(array $references): array
    {
        $images = [];
        foreach ($references as $reference) {
            if (!is_array($reference)) {
                continue;
            }
            $type = (string)($reference['type'] ?? 'image');
            if ($type !== 'image') {
                continue;
            }
            $url = trim((string)($reference['url'] ?? $reference['uri'] ?? ''));
            if ($url !== '') {
                $images[] = $url;
            }
        }
        return array_values(array_unique($images));
    }
}
