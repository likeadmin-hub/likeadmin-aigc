<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;

final class ShortDramaPlanningBudget
{
    public static function calculate(string $input, array $model, int $remaining, bool $publicFields = false): array
    {
        // UTF-8 bytes bound the token count conservatively without silently truncating source text.
        $tokens = strlen($input);
        $context = (int)($model['context_window'] ?? $model['capabilities']['context_window'] ?? 0);
        $source = $context > 0 ? 'model_metadata' : 'conservative_fallback';
        if ($context <= 0) $context = max(2048, (int)($model['_planning_fallback']['planning_context_fallback'] ?? 32768));
        $output = (int)($model['max_tokens'] ?? 0);
        if ($output <= 0) $output = max(1200, (int)($model['_planning_fallback']['planning_output_fallback'] ?? 8192));
        $margin = max(1024, (int)ceil($context * .15));
        $available = min($output, $context - $margin - $tokens);
        $common = $publicFields ? 2048 : 0;
        $count = min(10, $remaining, (int)floor(($available - $common) / 1200));
        if ($count < 1) throw new RuntimeException('模型容量不足以容纳当前规则、灵感及一集大纲，请缩短输入或选择更大容量模型');
        return ['count' => $count, 'max_tokens' => $common + $count * 1200, 'output_capacity' => $available, 'input_estimate' => $tokens,
            'context' => $context, 'margin' => $margin, 'source' => $source, 'estimator' => 'utf8_bytes_upper_bound'];
    }
}
