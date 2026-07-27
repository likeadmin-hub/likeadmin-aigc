<?php

namespace app\common\service\app\aigc_canvas\agent\enrichment;

use app\common\service\app\aigc_canvas\AigcCanvasService;

/** Runs the reusable enrichment stage before a Skill asks for missing slots. */
final class CreativeBriefEnricher
{
    private const DEFAULT_POLICY = [
        'enabled' => true,
        'on_reference_asset' => ['visual_understanding'],
        'brief_type' => 'reference_visual',
        'auto_copy_when_missing' => false,
        'visible_facts_only' => true,
        'minimum_confidence' => 0.7,
        'cache_ttl_seconds' => 2592000,
        'render_copy_in_image' => false,
    ];

    /** @return array<string, mixed> */
    public static function enrich(int $tenantId, int $userId, string $request, array $context, array $skillContract, array $options = []): array
    {
        // Reference understanding belongs to the canvas Agent, not to a short
        // allow-list of product Skills. Skills can still opt into delivery details
        // such as rendered copy, while every referenced visual asset gets the same
        // factual analysis path.
        $policy = array_replace(self::DEFAULT_POLICY, (array)($skillContract['enrichment_policy'] ?? []));
        $agentConfig = AigcCanvasService::agentConfig($tenantId);
        $references = self::references($context);
        if ($references === []) {
            return self::skipped($context, $policy, 'no_reference_asset');
        }
        if (empty($agentConfig['visual_enrichment_enabled'])) {
            return self::skipped($context, $policy, 'tenant_rollout_disabled');
        }
        $modelVersion = (string)($options['model_version'] ?? 'vision_describe_zh_v2');
        $cached = AssetInsightCacheService::find($tenantId, $references, $modelVersion);
        $toAnalyze = array_values(array_filter($references, static function ($reference) use ($cached): bool {
            return !isset($cached[AssetInsightCacheService::assetKey($reference)]);
        }));
        $vision = $toAnalyze === []
            ? ['status' => 'success', 'insights' => [], 'errors' => []]
            : VisualUnderstandingService::analyze($tenantId, $userId, $toAnalyze, $request, $options + ['model_version' => $modelVersion]);
        $freshInsights = (array)($vision['insights'] ?? []);
        $ttl = max(60, min(2592000, (int)($policy['cache_ttl_seconds'] ?? 2592000)));
        AssetInsightCacheService::store($tenantId, $userId, (int)($options['project_id'] ?? $context['project_id'] ?? 0), $freshInsights, $modelVersion, $ttl);
        $insights = [];
        foreach ($references as $reference) {
            $key = AssetInsightCacheService::assetKey($reference);
            if (isset($cached[$key])) {
                $insights[] = $cached[$key];
                continue;
            }
            foreach ($freshInsights as $insight) {
                if ((string)($insight['asset_key'] ?? '') === $key) {
                    $insights[] = $insight;
                    break;
                }
            }
        }
        $minimum = max(0.0, min(1.0, (float)($policy['minimum_confidence'] ?? 0.7)));
        $usable = array_values(array_filter($insights, static fn(array $item): bool => (float)($item['confidence'] ?? 0) >= $minimum));
        $copyPlan = !empty($policy['auto_copy_when_missing']) && $usable !== []
            ? CopyPlanGenerator::generate($usable, $request, (array)($context['brand_memory'] ?? []))
            : [];
        $evidenceCategories = ProductFactPolicy::requestedEvidenceCategories($request);
        $hasVerifiedFacts = !empty($context['verified_product_facts']) || !empty($context['brand_memory']['verified_facts']);
        $visualPrompt = $usable !== [] ? VisualPromptCompiler::compile($usable, $request, $policy, $copyPlan) : [];
        $enriched = $context;
        $enriched['enriched_context'] = [
            'asset_insights' => $insights,
            'evidence_catalog' => array_values(array_merge(...array_map(static fn(array $item): array => (array)($item['evidence_catalog'] ?? []), $insights ?: [[]]))),
            'copy_plan' => $copyPlan,
            'visual_prompt' => $visualPrompt,
            'cache' => ['model_version' => $modelVersion, 'hit_count' => count($cached), 'miss_count' => count($toAnalyze)],
            'conditional_claim_categories' => $evidenceCategories,
            'fact_source_distribution' => ProductFactPolicy::sourceDistribution($usable, $copyPlan),
        ];
        $enriched['creative_context'] = [
            'product_identity' => ['asset_keys' => array_values(array_filter(array_map(static fn(array $item): string => (string)($item['asset_key'] ?? ''), $usable)))],
            'evidence_catalog' => (array)$enriched['enriched_context']['evidence_catalog'],
            'brand_context' => (array)($context['brand_memory'] ?? []),
            'global_visual_system' => (array)($visualPrompt['visual_system'] ?? []),
            'claim_policy' => ['claims' => (array)($copyPlan['claims'] ?? [])],
        ];
        if ($usable !== []) {
            $enriched['visual_understanding_ready'] = true;
            $enriched['visual_subject_available'] = true;
            $enriched['inferred_slot_values'] = array_merge((array)($enriched['inferred_slot_values'] ?? []), [
                'product_info' => implode('；', (array)($usable[0]['visible_facts'] ?? [])),
                'selling_points' => (array)($copyPlan['selling_points'] ?? []),
                'headline' => (string)($copyPlan['headline'] ?? ''),
                'subheadline' => (string)($copyPlan['subheadline'] ?? ''),
                'cta' => (string)($copyPlan['cta'] ?? ''),
            ]);
        }
        return [
            'status' => (string)($vision['status'] ?? 'failed'),
            'context' => $enriched,
            'asset_insights' => $insights,
            'copy_plan' => $copyPlan,
            'visual_prompt' => $visualPrompt,
            'fact_source_distribution' => ProductFactPolicy::sourceDistribution($usable, $copyPlan),
            'errors' => (array)($vision['errors'] ?? []),
            'cache' => ['model_version' => $modelVersion, 'hit_count' => count($cached), 'miss_count' => count($toAnalyze)],
            'conditional_claim_categories' => $hasVerifiedFacts ? [] : $evidenceCategories,
            'reason' => $usable === [] ? 'low_confidence_or_unavailable' : 'ready',
        ];
    }

    /** @return array<string, mixed> */
    public static function userFacingSummary(array $brief): array
    {
        if ((string)($brief['status'] ?? '') !== 'success') {
            return [];
        }
        $list = static function ($value, int $limit): array {
            $items = [];
            foreach ((array)$value as $item) {
                $item = mb_substr(trim((string)$item), 0, 160, 'UTF-8');
                if ($item !== '' && !in_array($item, $items, true)) {
                    $items[] = $item;
                }
                if (count($items) >= $limit) {
                    break;
                }
            }
            return $items;
        };
        $composition = [];
        $briefComposition = (array)($brief['composition'] ?? []);
        foreach (['subject_position', 'text_safe_area', 'background', 'camera_angle', 'dominant_colors'] as $key) {
            $value = $briefComposition[$key] ?? null;
            if (is_array($value)) {
                $value = implode('、', $list($value, 3));
            }
            $value = mb_substr(trim((string)$value), 0, 160, 'UTF-8');
            if ($value !== '' && $value !== 'unknown') {
                $composition[$key] = $value;
            }
        }
        return array_filter([
            'visible_facts' => $list($brief['visible_facts'] ?? [], 3),
            'reference_style' => $list($brief['visual_style'] ?? [], 3),
            'composition' => $composition,
        ], static fn($value): bool => $value !== []);
    }

    /** @return array<string, mixed> */
    private static function skipped(array $context, array $policy, string $reason): array
    {
        return ['status' => 'skipped', 'context' => $context, 'asset_insights' => [], 'copy_plan' => [], 'visual_prompt' => [], 'fact_source_distribution' => [], 'errors' => [], 'reason' => $reason, 'policy' => $policy];
    }

    /** @return array<int, mixed> */
    private static function references(array $context): array
    {
        return array_values(array_filter(array_merge(
            (array)($context['uploaded_references'] ?? []),
            (array)($context['selected_elements'] ?? $context['selection']['elements'] ?? [])
        ), static fn($item): bool => is_array($item) || is_string($item)));
    }
}
