<?php

declare(strict_types=1);

namespace app\common\service\app\aigc_video_enhance;

use app\common\service\app\AppDisplayConfigService;
use app\common\service\power\MarketGenericImageAppRuntimeService as Runtime;
use app\common\service\storage\StorageConfigService;
use InvalidArgumentException;
use think\facade\Db;

final class VideoEnhanceConfig
{
    public const MAX_SECONDS = 30;
    private const DEFAULTS = ['enabled' => 1, 'max_seconds' => self::MAX_SECONDS, 'default_bit_rate' => 10, 'allow_link' => 1, 'sku_id' => 0];
    private const TABLE = 'aigc_video_enhance_config';

    public static function settings(int $tenant): array
    {
        $global = self::stored(0);
        $settings = array_replace(self::DEFAULTS, $global, $tenant > 0 ? self::stored($tenant) : []);
        if ($tenant > 0) {
            $settings['enabled'] = (int)($global['enabled'] ?? 1);
            $settings['max_seconds'] = min((int)$settings['max_seconds'], (int)($global['max_seconds'] ?? self::MAX_SECONDS));
            $settings['allow_link'] = (int)($settings['allow_link'] && ($global['allow_link'] ?? 1));
        }
        return $settings;
    }

    public static function admin(int $tenant): array
    {
        $settings = self::settings($tenant);
        $options = self::options($tenant);
        $selected = self::selected($settings, $options);
        $settings['options'] = $options;
        $settings['selected'] = $selected;
        $settings['overrides'] = self::stored($tenant);
        $settings['global_max_seconds'] = (int)(self::stored(0)['max_seconds'] ?? self::MAX_SECONDS);
        $settings['global_allow_link'] = (int)(self::stored(0)['allow_link'] ?? 1);
        try { $settings['sample_quote'] = $selected ? Runtime::quote($tenant, $selected, min(5, $settings['max_seconds'])) : null; }
        catch (\Exception) { $settings['sample_quote'] = null; }
        if ($settings['sample_quote']) {
            $settings['sample_quote'] = array_intersect_key($settings['sample_quote'], array_flip(['quantity', 'user_charge_points']));
        }
        if ($tenant > 0) {
            $settings['storage_engine'] = StorageConfigService::getEffectiveDefault($tenant);
            return AppDisplayConfigService::appendToConfig($tenant, VideoEnhanceService::APP, $settings);
        }
        return $settings;
    }

    public static function save(int $tenant, array $input): void
    {
        $global = $tenant > 0 ? self::settings(0) : self::DEFAULTS;
        $settings = self::validate($input, $tenant > 0, (int)$global['max_seconds']);
        if (!empty($settings['sku_id']) && !in_array($settings['sku_id'], array_column(self::options($tenant), 'market_sku_id'), true)
            && $settings['sku_id'] !== (int)(self::stored($tenant)['sku_id'] ?? 0)) {
            throw new InvalidArgumentException('请选择已上架的 FlashVSR 规格');
        }
        Db::transaction(static function () use ($tenant, $input, $settings): void {
            $now = time();
            $body = json_encode($settings, JSON_THROW_ON_ERROR);
            Db::name(self::TABLE)->duplicate(['settings' => $body, 'update_time' => $now])->insert([
                'tenant_id' => $tenant, 'settings' => $body, 'update_time' => $now,
            ]);
            if ($tenant > 0) AppDisplayConfigService::saveFromConfigPayload($tenant, VideoEnhanceService::APP, $input);
        });
    }

    public static function validate(array $input, bool $tenant, int $maxSeconds): array
    {
        $limits = ['max_seconds' => [1, $maxSeconds], 'default_bit_rate' => [1, 20], 'allow_link' => [0, 1], 'sku_id' => [0, PHP_INT_MAX]];
        if (!$tenant) $limits['enabled'] = [0, 1];
        $result = [];
        foreach ($limits as $key => [$min, $max]) {
            $value = $input[$key] ?? null;
            if ($value === null && $tenant) continue;
            $value = filter_var($value ?? self::DEFAULTS[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
            if ($value === false || ($key === 'default_bit_rate' && !in_array($value, [6, 10, 16], true))) {
                throw new InvalidArgumentException('请检查时长、码率或规格配置');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    public static function options(int $tenant): array
    {
        $options = array_filter(Runtime::options($tenant, Runtime::ENHANCE_APP), static fn(array $option): bool => $option['usage_unit'] === 'input_second');
        $fields = array_flip(['market_product_id', 'market_sku_id', 'name', 'usage_unit', 'usage_unit_size', 'tenant_unit_price', 'platform_unit_cost']);
        return array_values(array_map(static fn(array $option): array => array_intersect_key($option, $fields), $options));
    }

    public static function selected(array $settings, array $options): ?array
    {
        $sku = (int)$settings['sku_id'];
        if ($sku === 0) return $options[0] ?? null;
        return array_column($options, null, 'market_sku_id')[$sku] ?? null;
    }

    private static function stored(int $tenant): array
    {
        $value = Db::name(self::TABLE)->where('tenant_id', $tenant)->value('settings');
        return is_string($value) ? (json_decode($value, true) ?: []) : [];
    }
}
