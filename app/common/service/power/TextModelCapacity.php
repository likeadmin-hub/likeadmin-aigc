<?php
namespace app\common\service\power;

/** Shared, provider-independent limits. Input-only limits are NOT context windows. */
final class TextModelCapacity
{
    /** Official fallback only; positive channel/admin metadata always takes precedence.
     * https://platform.kimi.ai/docs/guide/kimi-k3-quickstart
     * https://platform.kimi.ai/docs/guide/codex-kimi
     */
    private static function officialDefaults(array $model): array
    {
        return strtolower(trim((string)($model['model_code'] ?? ''))) === 'kimi-k3'
            ? ['context_window' => 1048576, 'max_output_tokens' => 1048576, 'default_output_tokens' => 131072]
            : [];
    }

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
        $official = self::officialDefaults($model);
        return [
            'context_window' => $positive(['context_window', 'context_length', 'max_context_tokens']) ?: ($official['context_window'] ?? 0),
            'max_input_tokens' => $positive(['max_input_tokens']),
            'max_output_tokens' => $positive(['max_output_tokens', 'max_completion_tokens', 'max_tokens']) ?: ($official['max_output_tokens'] ?? 0),
        ];
    }

    public static function output(array $model, int $requested, int $fallback = 8192): int
    {
        $capacity = self::metadata($model);
        $limit = $capacity['max_output_tokens'];
        $official = self::officialDefaults($model);
        $default = (int)($model['default_params']['max_tokens'] ?? 0);
        if ($official !== [] && $default <= 0) {
            $default = (int)($model['default_params']['max_completion_tokens'] ?? 0);
            if ($default <= 0) $default = (int)($model['default_params']['max_output_tokens'] ?? 0);
        }
        if ($default <= 0) $default = $official['default_output_tokens'] ?? 0;
        $value = $requested > 0 ? $requested : ($default > 0 ? $default : $fallback);
        if ($limit > 0) $value = min($value, $limit);
        if ($official !== []) {
            // K3 supports more than the legacy 32K cap. Leave room for input,
            // and never raise an explicit channel limit to our legacy minimum.
            return max(1, min($value, max(1, $capacity['context_window'] - 1)));
        }
        return max(256, min(32768, $value));
    }
}
