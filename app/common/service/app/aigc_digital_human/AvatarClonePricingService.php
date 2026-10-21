<?php
namespace app\common\service\app\aigc_digital_human;

use app\common\model\app\image_human\ImageHumanConfig;
use app\common\model\app\aigc_pic_lipsync\AigcPicLipsyncConfig;
use InvalidArgumentException;

/** Image-avatar prices live in the owning tool's config_json.pricing.avatar_clone. */
final class AvatarClonePricingService
{
    private static function stored(int $tenantId, string $app): array
    {
        $model = match ($app) {
            'image_human' => ImageHumanConfig::class,
            'aigc_pic_lipsync' => AigcPicLipsyncConfig::class,
            default => throw new InvalidArgumentException('不支持的形象类型'),
        };
        $row = $model::where('tenant_id', $tenantId)->findOrEmpty();
        return $row->isEmpty() ? [] : (array)($row['config_json']['pricing']['avatar_clone'] ?? []);
    }

    public static function price(int $tenantId, string $app): array
    {
        // These two tools historically saved images for free. Keep that default.
        $platform = array_replace(['tenant_unit_price' => '0.00'], self::stored(0, $app));
        $tenant = $tenantId > 0 ? self::stored($tenantId, $app) : [];
        return ['platform_unit_cost' => '0.00', 'tenant_unit_price' => self::amount($tenant['tenant_unit_price'] ?? $platform['tenant_unit_price'])];
    }

    public static function amount($value): string
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 99999999) {
            throw new InvalidArgumentException('形象克隆价格必须为有效的非负数');
        }
        return number_format((float)$value, 2, '.', '');
    }

    /** Omitted fields preserve existing overrides, including an explicit zero price. */
    public static function forSave(int $tenantId, string $app, array $pricing): array
    {
        $stored = self::stored($tenantId, $app);
        if (!array_key_exists('avatar_clone', $pricing)) return $stored;
        if (!is_array($pricing['avatar_clone']) || !array_key_exists('tenant_unit_price', $pricing['avatar_clone'])) {
            throw new InvalidArgumentException('形象克隆价格格式不正确');
        }
        return ['tenant_unit_price' => self::amount($pricing['avatar_clone']['tenant_unit_price'])];
    }

    public static function estimate(int $tenantId, string $app, string $type): array
    {
        $price = self::price($tenantId, $app);
        return $price + ['billing_type' => $type, 'billing_unit' => 'count', 'quantity' => 1,
            'tenant_cost_points' => '0.00', 'user_charge_points' => $price['tenant_unit_price']];
    }
}
