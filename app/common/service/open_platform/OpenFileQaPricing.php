<?php
namespace app\common\service\open_platform;

use app\common\service\power\MarketFileQaTokenPricing;

/** A published file API's limit is total tokens; the receipt determines their actual mix. */
final class OpenFileQaPricing
{
    public static function quote(array $rule, array $sku): array
    {
        $rates = $sku['token_rates'] ?? null;
        if (!$rates || !$rates['valid']) throw new OpenException('pricing_unavailable', '请同步文件应用目录并检查输入输出费率', 503);
        $limit = $rule['max_units'] ?? null;
        $paths = ['data.result.usage.total_tokens', 'data.usage.total_tokens', 'result.usage.total_tokens', 'usage.total_tokens'];
        if (($rule['basis'] ?? '') !== 'usage' || !in_array($rule['usage_path'] ?? '', $paths, true)
            || !is_numeric($limit) || !is_finite((float)$limit) || (float)$limit < 1 || (float)(int)$limit !== (float)$limit) {
            throw new OpenException('invalid_contract', '文件应用需绑定实际 Token 用量和已审核的总量上限');
        }
        $size = $rates['usage_unit_size'];
        $entry = [
            'sku_id' => (int)$sku['id'], 'sku_key' => $sku['sku_key'], 'basis' => 'file_qa_tokens',
            'usage_unit' => 'token', 'unit_size' => $size, 'usage_path' => $rule['usage_path'], 'max_units' => (int)$limit,
            'token_pricing' => ['usage_unit_size' => $size, 'platform' => $rates['cost'], 'tenant' => $rates['sale']],
        ];
        return ['rule' => $entry, 'tenant' => round(max($rates['cost']) * $limit / $size, 2),
            'user' => round(max($rates['sale']) * $limit / $size, 2)];
    }

    public static function settle(array $rule, array $result): ?array
    {
        $path = substr($rule['usage_path'], 0, -strlen('.total_tokens'));
        $usage = OpenSchema::get($result, $path);
        if (!is_array($usage) || (!isset($usage['input_tokens']) && !isset($usage['prompt_tokens']))
            || (!isset($usage['output_tokens']) && !isset($usage['completion_tokens']))) return null;
        [$tenant, $user, $breakdown] = MarketFileQaTokenPricing::amounts($rule, ['usage' => $usage]);
        if ($breakdown['total_tokens'] > $rule['max_units']) throw new OpenException('usage_boundary_exceeded', '实际 Token 超过契约上限，等待核对', 502);
        return ['tenant' => $tenant, 'user' => $user, 'usage' => [
            'sku_id' => $rule['sku_id'], 'units' => $breakdown['total_tokens'], 'token_breakdown' => $breakdown,
        ]];
    }
}
