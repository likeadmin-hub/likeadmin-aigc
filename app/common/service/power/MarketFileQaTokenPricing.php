<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** file_qa's zero scalar price is a placeholder when separate Token rates exist. */
final class MarketFileQaTokenPricing
{
    public static function snapshot(array $sku, array $declaration, array $platformRules, array $tenantRules): ?array
    {
        if (!array_key_exists('effective_rate_config', $declaration)) return null;
        $rates = $declaration['effective_rate_config'];
        if (!is_array($rates) || !isset($rates['input'], $rates['output'])) throw new InvalidArgumentException('剧本解析输入输出费率不完整');
        $rates['cached_input'] ??= $rates['input'];
        $size = (float)($declaration['usage_unit_size'] ?? $sku['usage_unit_size'] ?? 0);
        if (!is_finite($size) || $size <= 0) throw new InvalidArgumentException('剧本解析计费单位无效');
        $snapshot = ['version' => 1, 'usage_unit_size' => $size, 'upstream' => [], 'platform' => [], 'tenant' => [], 'rules' => []];
        foreach (['input', 'cached_input', 'output'] as $key) {
            $base = MarketPricePolicy::amount($rates[$key]);
            $platform = MarketPricePolicy::resolve($base, $base, $platformRules, (int)$sku['product_id'], (int)$sku['id']);
            $tenant = MarketPricePolicy::resolve($platform['price'], $platform['price'], $tenantRules, (int)$sku['product_id'], (int)$sku['id']);
            if (!$platform['valid'] || !$tenant['valid']) throw new InvalidArgumentException('剧本解析分项售价低于进货价，请先调整价格');
            $snapshot['upstream'][$key] = $base;
            $snapshot['platform'][$key] = $platform['price'];
            $snapshot['tenant'][$key] = $tenant['price'];
            $snapshot['rules'][$key] = ['platform' => $platform, 'tenant' => $tenant];
        }
        return $snapshot;
    }

    /** Only documented usage paths count. A plan's arbitrary nested numbers never bill. */
    public static function usage(array $response): array
    {
        $root = is_array($response['data'] ?? null) ? $response['data'] : $response;
        foreach ([$root['result']['usage'] ?? null, $root['usage'] ?? null] as $usage) {
            if (!is_array($usage)) continue;
            $input = self::tokens($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null);
            $output = self::tokens($usage['output_tokens'] ?? $usage['completion_tokens'] ?? null);
            if ($input === null || $output === null || $input + $output <= 0) continue;
            $cached = self::tokens($usage['cached_input_tokens'] ?? $usage['input_tokens_details']['cached_tokens'] ?? $usage['prompt_tokens_details']['cached_tokens'] ?? 0);
            if ($cached === null || $cached > $input) throw new InvalidArgumentException('剧本解析缓存 Token 用量无效');
            if (isset($usage['total_tokens']) && self::tokens($usage['total_tokens']) !== $input + $output) throw new InvalidArgumentException('剧本解析 Token 总量与分项不一致');
            return ['input' => $input - $cached, 'cached_input' => $cached, 'output' => $output, 'total_tokens' => $input + $output];
        }
        throw new InvalidArgumentException('剧本解析结果缺少实际输入输出 Token 用量，尚未结算');
    }

    public static function amounts(array $snapshot, array $response): array
    {
        $usage = self::usage($response);
        $pricing = $snapshot['token_pricing'];
        $amounts = [];
        foreach (['platform', 'tenant'] as $side) {
            $sum = 0.;
            foreach (['input', 'cached_input', 'output'] as $key) $sum += $usage[$key] * (float)$pricing[$side][$key];
            // Match the account's two-decimal precision before saving totals and logs.
            $amounts[] = round($sum / $pricing['usage_unit_size'], 2);
        }
        return [$amounts[0], $amounts[1], $usage];
    }

    private static function tokens(mixed $value): ?int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^\d+$/D', $value))) || (float)$value < 0 || (float)$value > 1000000000) return null;
        return (int)$value;
    }
}
