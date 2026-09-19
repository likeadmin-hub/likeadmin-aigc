<?php
namespace app\common\service\power;

/** Shared, provider-independent limits. Input-only limits are NOT context windows. */
final class TextModelCapacity
{
    public static function metadata(array $model): array
    {
        $cap = (array)($model['capabilities'] ?? []);
        $positive = static function (array $keys) use ($model, $cap): int {
            foreach ($keys as $key) {
                foreach ([$model[$key] ?? null, $cap[$key] ?? null] as $value) {
                    if (is_numeric($value) && (int)$value > 0) return (int)$value;
                }
            }
            return 0;
        };
        return [
            'context_window' => $positive(['context_window', 'context_length', 'max_context_tokens']),
            'max_input_tokens' => $positive(['max_input_tokens']),
            'max_output_tokens' => $positive(['max_output_tokens', 'max_completion_tokens', 'max_tokens']),
        ];
    }

    public static function output(array $model, int $requested, int $fallback = 8192): int
    {
        $limit = self::metadata($model)['max_output_tokens'];
        $default = (int)($model['default_params']['max_tokens'] ?? 0);
        $value = $requested > 0 ? $requested : ($default > 0 ? $default : $fallback);
        if ($limit > 0) $value = min($value, $limit);
        return max(256, min(32768, $value));
    }
}
