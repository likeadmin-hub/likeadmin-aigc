<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

final class ShortDramaPlanningBudget
{
    public static function calculate(string $input, array $model, int $remaining, bool $publicFields = false): array
    {
        // Byte length makes Chinese prompts look roughly three times larger
        // than they are. Use the same mixed-language heuristic used by the
        // text runtime, while retaining a UTF-8 guard for CJK-heavy input.
        // Provider usage remains the billing source of truth after submission.
        $bytes = strlen($input);
        $characters = mb_strlen($input, 'UTF-8');
        $tokens = max(1, (int)ceil(max($characters / 1.5, $bytes / 3)));
        $output = (int)($model['max_tokens'] ?? 0);
        if ($output <= 0) $output = max(1200, (int)($model['_planning_fallback']['planning_output_fallback'] ?? 8192));
        $fallbackContext = max(2048, (int)($model['_planning_fallback']['planning_context_fallback'] ?? 32768));
        $metadataContext = self::contextWindow($model);
        // `max_tokens` is a completion cap, not proof of an input-context
        // window. Never inflate the input allowance from it: doing so can turn
        // an early, actionable validation error into a provider-side context
        // overflow. Providers without published context metadata use the
        // conservative application fallback instead.
        if ($metadataContext > 0) {
            $context = $metadataContext;
            $source = 'model_metadata';
        } else {
            $context = $fallbackContext;
            $source = 'conservative_fallback';
        }
        $margin = max(1024, (int)ceil($context * .15));
        $available = min($output, $context - $margin - $tokens);
        $common = $publicFields ? 2048 : 0;
        $count = min(10, $remaining, (int)floor(($available - $common) / 1200));
        if ($count < 1) throw new RuntimeException('模型容量不足以容纳当前规则、灵感及一集大纲，请缩短输入或选择更大容量模型');
        return ['count' => $count, 'max_tokens' => $common + $count * 1200, 'output_capacity' => $available, 'input_estimate' => $tokens,
            'input_bytes' => $bytes, 'context' => $context, 'margin' => $margin, 'source' => $source, 'estimator' => 'mixed_utf8_heuristic'];
    }

    /**
     * A JSON repair only returns the current stage's small contract. Do not
     * ask a provider to reserve the whole remaining context window merely
     * because its first response had formatting defects.
     */
    public static function repairMaxTokens(array $budget, int $count, bool $publicFields = false): int
    {
        $available = max(1, (int)($budget['output_capacity'] ?? 0));
        $requested = $publicFields
            ? max(4096, 2048 + max(1, $count) * 1200)
            : max(1600, max(1, $count) * 1500);
        return min($available, $requested);
    }

    private static function contextWindow(array $model): int
    {
        $capabilities = (array)($model['capabilities'] ?? []);
        foreach ([
            $model['context_window'] ?? null,
            $model['context_length'] ?? null,
            $model['max_context_tokens'] ?? null,
            $model['max_input_tokens'] ?? null,
            $capabilities['context_window'] ?? null,
            $capabilities['context_length'] ?? null,
            $capabilities['max_context_tokens'] ?? null,
            $capabilities['max_input_tokens'] ?? null,
        ] as $value) {
            $value = is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value)) ? (int)$value : 0;
            if ($value > 0) return $value;
        }
        return 0;
    }
}
