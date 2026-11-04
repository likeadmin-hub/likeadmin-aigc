<?php
declare(strict_types=1);
namespace app\common\service\promotion;

/** Customer-facing order review. Never reload a mutable package to describe purchased rights. */
final class PackageCheckoutSummary
{
    public static function fromOrder(string $from, array $order, ?array $snapshot): ?array
    {
        if (!in_array($from, ['membership', 'recharge'], true)) return null;
        $rights = $snapshot ? json_decode((string)$snapshot['rights'], true, 512, JSON_THROW_ON_ERROR) : [];
        $rights = is_array($rights) ? $rights : [];
        $payable = $snapshot ? (int)$snapshot['payable_cents'] : PackagePromotionPolicy::cents($order['order_amount']);
        $regular = $snapshot ? (int)$snapshot['regular_cents'] : $payable;
        $texts = static fn($items): array => array_values(array_filter((array)$items, static fn($item) => is_string($item) && trim($item) !== ''));
        return [
            'title' => (string)($order[$from === 'membership' ? 'plan_name' : 'package_name'] ?? ''),
            'description' => (string)($rights['description'] ?? ''),
            'duration_months' => $from === 'membership' ? (int)($rights['duration_months'] ?? $order['duration_months'] ?? 0) : 0,
            'bonus_points' => $from === 'membership' ? (string)($rights['bonus_points'] ?? $order['bonus_points'] ?? '0') : '0',
            'recharge_points' => $from === 'recharge' ? (string)($rights['recharge_points'] ?? $order['recharge_points'] ?? '0') : '0',
            'features' => $texts($rights['features'] ?? []),
            'app_names' => $texts($rights['app_names'] ?? []),
            'regular_price' => PackagePromotionPolicy::amount($regular),
            'discount_amount' => PackagePromotionPolicy::amount(max(0, $regular - $payable)),
            'is_discount' => (bool)($snapshot['is_discount'] ?? false),
        ];
    }
}
