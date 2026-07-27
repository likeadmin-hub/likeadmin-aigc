<?php

namespace app\common\service\app\aigc_canvas\agent\enrichment;

/**
 * Keeps visual observations separate from marketing facts. Vision may describe
 * what is visible, but it cannot certify product attributes or outcomes.
 */
final class ProductFactPolicy
{
    public const SOURCE_VISIBLE = 'visible';
    public const SOURCE_USER = 'user';
    public const SOURCE_VERIFIED = 'verified';

    private const PROHIBITED_CATEGORIES = [
        'material_grade', 'performance', 'certification', 'price',
        'medical', 'effectiveness', 'comparison', 'specification',
    ];

    private const CLAIM_PATTERN = '/(?:\b\d+(?:\.\d+)?\s*(?:%|h|hrs?|hours?|mah|w|db|db[a-z]*)\b|\b(?:316|304|ipx?\d|ce|fcc|rohs)\b|\d+\s*(?:小时|分钟|毫安|瓦|分贝|级)|保温|续航|降噪|防水|抗敏|美白|修复|治愈|认证|专利|医用|杀菌|抑菌|第一|最好|最低价|限时价|元起|不锈钢)/iu';

    /** @return array<string, mixed> */
    public static function sanitizeInsight(array $insight): array
    {
        $facts = [];
        foreach ((array)($insight['visible_facts'] ?? []) as $fact) {
            $fact = self::cleanText($fact);
            if ($fact !== '' && self::allowsVisibleClaim($fact)) {
                $facts[] = $fact;
            }
        }
        $styles = self::cleanList((array)($insight['visual_style'] ?? []));
        $ocr = self::cleanList((array)($insight['ocr_text'] ?? []));
        $composition = is_array($insight['composition'] ?? null) ? $insight['composition'] : [];
        $sources = is_array($insight['fact_sources'] ?? null) ? $insight['fact_sources'] : [];

        return [
            'asset_key' => self::cleanText($insight['asset_key'] ?? ''),
            'asset_type' => in_array((string)($insight['asset_type'] ?? ''), ['image', 'video', 'logo', 'file'], true) ? (string)$insight['asset_type'] : 'image',
            'subject_type' => self::safeSubjectType((string)($insight['subject_type'] ?? 'object')),
            'visible_facts' => array_values(array_unique(array_slice($facts, 0, 12))),
            'visual_style' => array_values(array_unique(array_slice($styles, 0, 8))),
            'composition' => self::safeComposition($composition),
            'ocr_text' => array_slice($ocr, 0, 8),
            'confidence' => max(0.0, min(1.0, (float)($insight['confidence'] ?? 0))),
            'fact_sources' => self::safeSources($sources, $facts),
            'unsafe_claim_categories' => self::PROHIBITED_CATEGORIES,
        ];
    }

    public static function allowsVisibleClaim(string $text): bool
    {
        return $text !== '' && preg_match(self::CLAIM_PATTERN, $text) !== 1;
    }

    public static function allowsClaim(string $text, string $source): bool
    {
        $source = strtolower(trim($source));
        if (in_array($source, [self::SOURCE_USER, self::SOURCE_VERIFIED], true)) {
            return true;
        }
        return self::allowsVisibleClaim($text);
    }

    /** @return array<int, string> */
    public static function requestedEvidenceCategories(string $request): array
    {
        $text = mb_strtolower($request, 'UTF-8');
        $patterns = [
            'performance' => '/保温|续航|降噪|防水|防尘|功率|分贝|小时|分钟|mah|\b\d+\s*(?:h|hrs?|hours?|w|db)\b/iu',
            'material_grade' => '/316|304|材质等级|医用级|食品级|不锈钢/iu',
            'certification' => '/认证|证书|ce|fcc|rohs|专利/iu',
            'medical' => '/美白|抗敏|修复|治疗|疗效|杀菌|抑菌/iu',
            'price' => '/价格|低价|元起|售价|折扣|优惠价/iu',
        ];
        $result = [];
        foreach ($patterns as $category => $pattern) {
            if (preg_match($pattern, $text) === 1) $result[] = $category;
        }
        return $result;
    }

    /** @return array<string, int> */
    public static function sourceDistribution(array $insights, array $copyPlan = []): array
    {
        $distribution = [self::SOURCE_VISIBLE => 0, self::SOURCE_USER => 0, self::SOURCE_VERIFIED => 0, 'prohibited' => 0];
        foreach ($insights as $insight) {
            foreach ((array)($insight['fact_sources'] ?? []) as $source) {
                $source = strtolower((string)$source);
                if (array_key_exists($source, $distribution)) {
                    $distribution[$source]++;
                }
            }
        }
        foreach ((array)($copyPlan['claim_sources'] ?? []) as $source) {
            $source = strtolower((string)$source);
            if (array_key_exists($source, $distribution)) {
                $distribution[$source]++;
            }
        }
        return $distribution;
    }

    /** Converts visible observations into stable evidence records. */
    public static function evidenceCatalog(array $insight): array
    {
        $assetKey = trim((string)($insight['asset_key'] ?? 'asset')) ?: 'asset';
        $confidence = max(0.0, min(1.0, (float)($insight['confidence'] ?? 0)));
        $ocr = array_values(array_filter(array_map('strval', (array)($insight['ocr_text'] ?? []))));
        $records = [];
        foreach (array_values((array)($insight['visible_facts'] ?? [])) as $index => $observation) {
            $observation = self::cleanText($observation);
            if ($observation === '') continue;
            $records[] = [
                'fact_id' => 'fact_' . substr(sha1($assetKey . '|' . $observation), 0, 16),
                'observation' => $observation,
                'confidence' => $confidence,
                'source' => self::SOURCE_VISIBLE,
                'asset_key' => $assetKey,
                'allowed_usage' => ['identity', 'visual_direction', 'interaction_copy'],
                'ocr' => array_values(array_filter($ocr, static fn(string $value): bool => str_contains(mb_strtolower($observation, 'UTF-8'), mb_strtolower($value, 'UTF-8')))),
                'not_supported' => self::PROHIBITED_CATEGORIES,
            ];
        }
        return $records;
    }

    private static function safeSubjectType(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['product', 'person', 'logo', 'packaging', 'scene', 'object', 'unknown'], true) ? $value : 'object';
    }

    private static function safeComposition(array $composition): array
    {
        $allowed = ['subject_position', 'text_safe_area', 'background', 'camera_angle', 'dominant_colors'];
        $result = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $composition)) {
                continue;
            }
            $value = is_array($composition[$key]) ? self::cleanList($composition[$key]) : self::cleanText($composition[$key]);
            if ($value !== '' && $value !== []) {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private static function safeSources(array $sources, array $facts): array
    {
        $result = [];
        foreach ($facts as $index => $fact) {
            $source = strtolower((string)($sources[$fact] ?? $sources[$index] ?? self::SOURCE_VISIBLE));
            $result[$fact] = in_array($source, [self::SOURCE_VISIBLE, self::SOURCE_USER, self::SOURCE_VERIFIED], true) ? $source : self::SOURCE_VISIBLE;
        }
        return $result;
    }

    private static function cleanList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = self::cleanText($value);
            if ($value !== '') {
                $result[] = $value;
            }
        }
        return $result;
    }

    private static function cleanText($value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string)$value) ?? '');
        return mb_substr($value, 0, 160, 'UTF-8');
    }
}
