<?php

namespace app\common\service\app\aigc_canvas\agent\enrichment;

/** Creates low-risk, editable marketing copy from approved visual observations. */
final class CopyPlanGenerator
{
    /** @return array<string, mixed> */
    public static function generate(array $insights, string $request = '', array $brandMemory = []): array
    {
        $facts = [];
        $styles = [];
        foreach ($insights as $insight) {
            foreach ((array)($insight['visible_facts'] ?? []) as $fact) {
                if (ProductFactPolicy::allowsVisibleClaim((string)$fact)) $facts[] = (string)$fact;
            }
            foreach ((array)($insight['visual_style'] ?? []) as $style) {
                $styles[] = (string)$style;
            }
        }
        $facts = array_values(array_unique(array_filter($facts)));
        $styles = array_values(array_unique(array_filter($styles)));
        $brand = trim((string)($brandMemory['brand_name'] ?? $brandMemory['name'] ?? ''));
        $tone = trim((string)($brandMemory['tone'] ?? ''));
        $headline = $brand !== '' ? $brand . '，为日常而设计' : '一眼可见的简约质感';
        $subheadline = $facts !== []
            ? '聚焦可见细节，呈现自然清晰的产品印象'
            : '以清晰主体和留白构图，传达产品方向';
        if ($tone !== '') {
            $subheadline = mb_substr($tone . '，' . $subheadline, 0, 56, 'UTF-8');
        }
        $sellingPoints = $facts === [] ? ['主体清晰呈现', '简洁视觉表达'] : array_slice($facts, 0, 3);
        // Generic composition guidance is not a product claim. A visible claim
        // is allowed only when an evidence catalog record can prove it.
        $sellingPoints = array_values(array_filter(
            $facts,
            static fn(string $fact): bool => self::evidenceIds($insights, $fact) !== []
        ));
        $sellingPoints = array_slice($sellingPoints, 0, 3);
        $claimSources = [];
        foreach ($sellingPoints as $point) $claimSources[$point] = ProductFactPolicy::SOURCE_VISIBLE;
        $claims = [];
        foreach ($sellingPoints as $point) {
            $evidence = self::evidenceMeta($insights, $point);
            $claims[] = [
                'claim_id' => 'claim_' . substr(sha1($point), 0, 16),
                'text' => $point,
                'status' => 'approved_claim',
                'source' => ProductFactPolicy::SOURCE_VISIBLE,
                'evidence_ids' => self::evidenceIds($insights, $point),
                'confidence' => $evidence['confidence'],
                'allowed_usage' => $evidence['allowed_usage'],
            ];
        }
        foreach (ProductFactPolicy::requestedEvidenceCategories($request) as $category) {
            $claims[] = [
                'claim_id' => 'claim_need_' . substr(sha1($category), 0, 16),
                'text' => $category,
                'status' => 'needs_verification',
                'source' => 'missing_evidence',
                'evidence_ids' => [],
                'confidence' => 0.0,
                'allowed_usage' => [],
            ];
        }
        return [
            'headline' => $headline,
            'subheadline' => $subheadline,
            'cta' => '探索更多',
            'selling_points' => $sellingPoints,
            'visual_direction' => $facts === [] ? ['clear_product_subject', 'clean_composition'] : [],
            'claim_sources' => $claimSources + ['headline' => ProductFactPolicy::SOURCE_VISIBLE, 'subheadline' => ProductFactPolicy::SOURCE_VISIBLE, 'cta' => 'generic'],
            'claims' => $claims,
            'editable' => true,
            'request_summary' => mb_substr(trim($request), 0, 300, 'UTF-8'),
        ];
    }

    private static function evidenceIds(array $insights, string $claim): array
    {
        $ids = [];
        foreach ($insights as $insight) {
            foreach ((array)($insight['evidence_catalog'] ?? []) as $evidence) {
                if (!is_array($evidence)) continue;
                $observation = (string)($evidence['observation'] ?? '');
                if ($observation !== '' && (str_contains($claim, $observation) || str_contains($observation, $claim))) {
                    $ids[] = (string)($evidence['fact_id'] ?? '');
                }
            }
        }
        return array_values(array_filter(array_unique($ids)));
    }

    private static function evidenceMeta(array $insights, string $claim): array
    {
        $confidence = 0.0;
        $allowedUsage = [];
        foreach ($insights as $insight) {
            foreach ((array)($insight['evidence_catalog'] ?? []) as $evidence) {
                if (!is_array($evidence)) continue;
                $observation = (string)($evidence['observation'] ?? '');
                if ($observation === '' || (!str_contains($claim, $observation) && !str_contains($observation, $claim))) continue;
                $confidence = max($confidence, (float)($evidence['confidence'] ?? 0));
                $allowedUsage = array_merge($allowedUsage, (array)($evidence['allowed_usage'] ?? []));
            }
        }
        return [
            'confidence' => max(0.0, min(1.0, $confidence)),
            'allowed_usage' => array_values(array_unique(array_filter(array_map('strval', $allowedUsage)))),
        ];
    }
}
