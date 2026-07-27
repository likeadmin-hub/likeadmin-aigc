<?php

namespace app\common\service\app\aigc_canvas\agent\prompt;

/** Validates and normalizes the structured PromptSpec before a provider receives it. */
final class PromptPolicyValidator
{
    public const VERSION = 'prompt-policy-v2';

    public static function validate(array $spec): array
    {
        $evidence = [];
        foreach ((array)($spec['evidence'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $id = trim((string)($item['fact_id'] ?? ''));
            $observation = trim((string)($item['observation'] ?? ''));
            if ($id !== '' && $observation !== '' && !isset($evidence[$id])) $evidence[$id] = $item;
        }
        $spec['evidence'] = array_values($evidence);
        $spec['evidence_ids'] = array_keys($evidence);
        $claims = [];
        foreach ((array)($spec['claims'] ?? []) as $claim) {
            if (!is_array($claim) || trim((string)($claim['text'] ?? '')) === '') continue;
            $ids = array_values(array_intersect(array_keys($evidence), array_map('strval', (array)($claim['evidence_ids'] ?? []))));
            $verified = (string)($claim['source'] ?? '') === 'verified' && !empty($claim['verification_record']);
            if (!$verified && $ids === []) continue;
            $text = trim((string)$claim['text']);
            if (!self::duplicatesEvidence($text, $evidence)) {
                $claim['evidence_ids'] = $ids;
                $claims[(string)($claim['claim_id'] ?? sha1($text))] = $claim;
            }
        }
        $spec['claims'] = array_values($claims);
        $spec['claim_ids'] = array_values(array_filter(array_map(static fn(array $claim): string => (string)($claim['claim_id'] ?? ''), $spec['claims'])));
        $constraints = self::strings($spec['constraints'] ?? []);
        // Product-claim controls must not become a generic image-generation suffix.
        if (self::requiresProductClaimGuard($spec)) {
            $constraints[] = '不添加价格、认证、规格参数、性能承诺、医疗或比较性宣传';
        }
        $spec['constraints'] = array_values(array_unique($constraints));
        if ((string)($spec['language'] ?? 'zh-CN') === 'zh-CN') {
            $spec['visual_direction'] = array_values(array_filter(
                self::strings($spec['visual_direction'] ?? []),
                static fn(string $value): bool => !self::isInternalEnglishLabel($value)
            ));
        }
        self::assertNoVisualConflict($spec);
        $spec['policy_version'] = self::VERSION;
        return $spec;
    }

    private static function duplicatesEvidence(string $claim, array $evidence): bool
    {
        $claim = self::semanticKey($claim);
        foreach ($evidence as $item) {
            $fact = self::semanticKey((string)($item['observation'] ?? ''));
            if ($fact !== '' && ($fact === $claim || str_contains($claim, $fact) || str_contains($fact, $claim))) return true;
        }
        return false;
    }

    private static function assertNoVisualConflict(array $spec): void
    {
        $text = implode(' ', array_merge(self::strings($spec['visual_direction'] ?? []), [
            (string)($spec['user_request'] ?? ''), (string)($spec['narrative'] ?? '')
        ]));
        $white = preg_match('/白底|纯白背景|white background/ui', $text) === 1;
        $outdoor = preg_match('/户外|室外|森林|海边|outdoor/ui', $text) === 1;
        if ($white && $outdoor) throw new \InvalidArgumentException('画面方向冲突：纯白背景不能同时要求户外场景。');
    }

    private static function requiresProductClaimGuard(array $spec): bool
    {
        if (!empty($spec['product_identity']) || !empty($spec['evidence']) || !empty($spec['claims']) || !empty($spec['copy_plan'])) {
            return true;
        }
        return preg_match('/(?:ecommerce|product|listing|商品|电商|详情页|主图|卖点)/u', (string)($spec['delivery_type'] ?? '')) === 1;
    }

    private static function semanticKey(string $text): string
    {
        return preg_replace('/[\s，,。.!！；;：:\-]/u', '', mb_strtolower(trim($text), 'UTF-8')) ?: '';
    }

    private static function strings($value): array
    {
        $items = is_array($value) ? $value : [$value]; $out = [];
        foreach ($items as $item) if (is_scalar($item) && trim((string)$item) !== '') $out[] = trim((string)$item);
        return $out;
    }

    /** Do not let old English-only intermediate tags leak into a Chinese prompt. */
    private static function isInternalEnglishLabel(string $value): bool
    {
        if (preg_match('/[\x{4e00}-\x{9fff}]/u', $value) === 1) return false;
        return preg_match('/^[a-z][a-z0-9 _-]{1,120}$/i', trim($value)) === 1;
    }
}
