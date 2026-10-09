<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Task quota only; account balances and actual charges remain owned by PointService. */
final class ResourceBudget
{
    private const FIELDS = ['total_tokens', 'tenant_cost_points', 'user_charge_points'];
    public static function create(array $limits): array
    {
        $limits = self::amounts($limits);
        if ($limits['total_tokens'] < 1) throw new RuntimeException('任务 token 预算必须大于零', 422);
        return ['version' => 1, 'limits' => $limits, 'spent' => array_fill_keys(self::FIELDS, 0),
            'holds' => [], 'rounds' => [], 'last_bound' => null, 'blocked' => ''];
    }
    /** Points are stored as integer millionths, matching the settlement precision. */
    private static function amounts(array $values): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $values[$field] ?? null;
            if (!is_numeric($value) || !is_finite((float)$value) || $value < 0 || $value > 1000000000
                || ($field === 'total_tokens' && (float)$value !== (float)(int)$value)
                || ($field !== 'total_tokens' && (float)$value !== round((float)$value, 6))) {
                throw new RuntimeException('任务资源预算或模型用量无效', 422);
            }
            $out[$field] = $field === 'total_tokens' ? (int)$value : (int)round((float)$value * 1000000);
        }
        return $out;
    }
    private static function assertFits(array $state): void
    {
        self::assertVerified($state);
        $total = $state['spent'];
        foreach ($state['holds'] as $hold) foreach (self::FIELDS as $field) $total[$field] += $hold[$field];
        foreach ($state['rounds'] as $round) foreach (self::FIELDS as $field) $total[$field] += $round['bound'][$field] * $round['remaining'];
        foreach (self::FIELDS as $field) if ($total[$field] > $state['limits'][$field]) {
            throw new RuntimeException('剩余资源预算不足，已保存内容保留', 429);
        }
    }
    public static function assertVerified(array $state): void
    {
        if ($state['blocked'] !== '') throw new RuntimeException('模型用量需要核对，已停止后续生成并保留内容', 429);
    }
    public static function reserveRound(array $state, string $key, int $calls): array
    {
        if (isset($state['rounds'][$key])) return $state;
        if (!$state['last_bound'] || $calls < 2) throw new RuntimeException('无法预留完整修复轮次的资源预算', 429);
        $state['rounds'][$key] = ['bound' => $state['last_bound'], 'remaining' => $calls];
        self::assertFits($state);
        return $state;
    }
    public static function admit(array $state, string $key, array $quote, string $round): array
    {
        if (isset($state['holds'][$key])) throw new RuntimeException('同一阶段不能重复提交', 409);
        $bound = self::amounts($quote);
        if ($bound['total_tokens'] < 1) throw new RuntimeException('缺少可靠的模型容量上限', 422);
        if ($round !== '') {
            if (($state['rounds'][$round]['remaining'] ?? 0) < 1) throw new RuntimeException('修复资源预留已用完', 429);
            $state['rounds'][$round]['remaining']--;
        }
        $state['holds'][$key] = $bound;
        self::assertFits($state);
        $state['last_bound'] = $bound;
        return $state;
    }
    public static function releaseUnsubmitted(array $state, string $key): array
    {
        unset($state['holds'][$key]);
        return $state;
    }
    /** An unverifiable receipt retains its entire hold. Never treat missing usage as free. */
    public static function receive(array $state, string $key, array $receipt): array
    {
        $usage = $receipt['usage'] ?? $receipt['result']['usage'] ?? [];
        $billing = $receipt['billing'] ?? [];
        try {
            $input = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0;
            $output = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0;
            foreach ([$input, $output, $usage['total_tokens'] ?? 0] as $tokens) {
                if (!is_numeric($tokens) || $tokens < 0 || $tokens > 1000000000 || (float)$tokens !== (float)(int)$tokens) throw new RuntimeException('invalid usage');
            }
            $amount = self::amounts(['total_tokens' => max($input + $output, $usage['total_tokens'] ?? 0),
                'tenant_cost_points' => $billing['tenant_cost_points'] ?? null, 'user_charge_points' => $billing['user_charge_points'] ?? null]);
            if (($billing['billing_status'] ?? '') !== 'settled' || !$amount['total_tokens'] || !isset($state['holds'][$key])) throw new RuntimeException('unverified usage');
        } catch (\Throwable $error) {
            $state['blocked'] = 'resource_usage_unverified';
            return $state;
        }
        foreach (self::FIELDS as $field) {
            $state['spent'][$field] += $amount[$field];
            if ($amount[$field] > $state['holds'][$key][$field]) $state['blocked'] = 'resource_bound_exceeded';
        }
        unset($state['holds'][$key]);
        return $state;
    }
}
