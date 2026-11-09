<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** file_qa's zero scalar price is a placeholder when separate Token rates exist. */
final class MarketFileQaTokenPricing
{
    public static function supports(string $type, string $app, string $api): bool
    {
        return $type === 'app_api' && $app === 'file_qa' && in_array($api, ['parse', 'chat'], true);
    }

    public static function storedDeclaration(array $sku): array
    {
        $source = $sku['source_payload'] ?? [];
        return is_array($source) ? $source : (json_decode($source, true) ?: []);
    }

    public static function rates(array $declaration): array
    {
        $rates = $declaration['effective_rate_config'] ?? null;
        if (!is_array($rates) || !isset($rates['input'], $rates['output'])) throw new InvalidArgumentException('文件应用输入输出费率不完整，请重新同步应用目录');
        $size = $declaration['usage_unit_size'] ?? null;
        if (!is_numeric($size) || !is_finite((float)$size) || (float)$size < 1) throw new InvalidArgumentException('文件应用 Token 计费单位无效');
        return [
            'input' => MarketPricePolicy::amount($rates['input']),
            'cached_input' => MarketPricePolicy::amount($rates['cached_input'] ?? $rates['input']),
            'output' => MarketPricePolicy::amount($rates['output']),
        ];
    }

    /** Project only the cost and sale tiers visible to this market owner. */
    public static function display(array $sku, int $owner, ?array $platformRules = null, ?array $tenantRules = null): ?array
    {
        $source = self::storedDeclaration($sku);
        if (!array_key_exists('effective_rate_config', $source)) return null;
        $pricing = self::snapshot($sku, $source, $platformRules ?? MarketPriceRuleService::rules(0),
            $owner === 0 ? [] : ($tenantRules ?? MarketPriceRuleService::rules($owner)), false);
        return ['cost' => $pricing[$owner === 0 ? 'upstream' : 'platform'], 'sale' => $pricing[$owner === 0 ? 'platform' : 'tenant'],
            'usage_unit_size' => $pricing['usage_unit_size'], 'valid' => $pricing['valid']];
    }

    public static function snapshot(array $sku, array $declaration, array $platformRules, array $tenantRules, bool $strict = true): ?array
    {
        if (!array_key_exists('effective_rate_config', $declaration)) return null;
        $declaration += ['usage_unit_size' => $sku['usage_unit_size'] ?? null];
        $rates = self::rates($declaration);
        $size = (float)$declaration['usage_unit_size'];
        $snapshot = ['version' => 1, 'valid' => true, 'usage_unit_size' => $size, 'upstream' => [], 'platform' => [], 'tenant' => [], 'rules' => []];
        foreach (['input', 'cached_input', 'output'] as $key) {
            $base = $rates[$key];
            $platform = MarketPricePolicy::resolve($base, $base, $platformRules, (int)$sku['product_id'], (int)$sku['id']);
            $tenant = MarketPricePolicy::resolve($platform['price'], $platform['price'], $tenantRules, (int)$sku['product_id'], (int)$sku['id']);
            if (!$platform['valid'] || !$tenant['valid']) {
                if ($strict) throw new InvalidArgumentException('文件应用分项售价低于进货价，请先调整价格');
                $snapshot['valid'] = false;
            }
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
