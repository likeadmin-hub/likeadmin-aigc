<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;
use app\common\service\power\TextModelCapacity;

final class ShortDramaPlanningBudget
{
    public static function calculate(string $input, array $model, int $remaining, bool $publicFields = false): array
    {
        $capacity = self::capacity($input, $model);
        $common = $publicFields ? 2048 : 0;
        $count = min(10, $remaining, (int)floor(($capacity['output_capacity'] - $common) / 1200));
        if ($count < 1) throw new RuntimeException('模型容量不足以容纳当前规则、灵感及一集大纲，请缩短输入或选择更大容量模型', 413);
        return ['count' => $count, 'max_tokens' => $common + $count * 1200] + $capacity;
    }

    /** Stage budgets do not pretend that story setting is a three-episode batch. */
    public static function stage(string $input, array $model, string $stage, int $desired = 8192): array
    {
        $capacity = self::capacity($input, $model);
        $minimum = $stage === 'story' ? 2048 : 1200;
        if ($capacity['output_capacity'] < $minimum) {
            throw new RuntimeException('当前模型容量不足以完成此步骤，请减少输入或选择更大容量模型；已完成内容保留', 413);
        }
        return ['count' => 1, 'stage' => $stage,
            'max_tokens' => min($capacity['output_capacity'], max($minimum, $desired))] + $capacity;
    }

    public static function capacity(string $input, array $model): array
    {
        // Byte length makes Chinese prompts look roughly three times larger
        // than they are. Use the same mixed-language heuristic used by the
        // text runtime, while retaining a UTF-8 guard for CJK-heavy input.
        // Provider usage remains the billing source of truth after submission.
        $bytes = strlen($input);
        $characters = mb_strlen($input, 'UTF-8');
        $tokens = max(1, (int)ceil(max($characters / 1.5, $bytes / 3)));
        $limits = TextModelCapacity::metadata($model);
        $output = $limits['max_output_tokens'];
        if ($output <= 0) $output = max(1200, (int)($model['_planning_fallback']['planning_output_fallback'] ?? 8192));
        $output = TextModelCapacity::output($model, $output);
        $fallbackContext = max(2048, (int)($model['_planning_fallback']['planning_context_fallback'] ?? 32768));
        $metadataContext = $limits['context_window'];
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
        if ($limits['max_input_tokens'] > 0 && $tokens > $limits['max_input_tokens']) {
            throw new RuntimeException('输入内容超过当前模型的输入容量，请减少输入或选择更大容量模型', 413);
        }
        $available = min($output, $context - $margin - $tokens);
        return ['output_capacity' => max(0, $available), 'input_estimate' => $tokens,
            'input_bytes' => $bytes, 'context' => $context, 'margin' => $margin, 'source' => $source, 'estimator' => 'mixed_utf8_heuristic'];
    }

    /**
     * A JSON repair only returns the current stage's small contract. Do not
     * ask a provider to reserve the whole remaining context window merely
     * because its first response had formatting defects.
     */
    public static function repairMaxTokens(array $budget, int $count, bool $publicFields = false): int
    {
        if (($budget['stage'] ?? '') === 'story') return $budget['max_tokens'];
        $available = max(1, (int)($budget['output_capacity'] ?? 0));
        $requested = $publicFields
            ? max(4096, 2048 + max(1, $count) * 1200)
            : max(1600, max(1, $count) * 1500);
        return min($available, $requested);
    }

}
