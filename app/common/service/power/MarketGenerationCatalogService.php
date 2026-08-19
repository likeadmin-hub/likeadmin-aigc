<?php

namespace app\common\service\power;

use app\common\model\power\PowerMarketProduct;

/**
 * Unified catalogue for the three generation families.
 *
 * Models are selected by model_type; application APIs are selected by the
 * normalized category_code. Unsupported application APIs are returned in a
 * separate diagnostic collection instead of being silently downgraded.
 */
class MarketGenerationCatalogService
{
    public const TYPES = ['text', 'image', 'video'];

    /** @return array<string, array<string, mixed>> */
    public static function directory(int $tenantId): array
    {
        $directory = [];
        foreach (self::TYPES as $generationType) {
            $directory[$generationType] = self::options($tenantId, $generationType);
        }
        return $directory;
    }

    /** @return array<string, mixed> */
    public static function options(int $tenantId, string $generationType): array
    {
        $generationType = strtolower(trim($generationType));
        if (!in_array($generationType, self::TYPES, true)) {
            throw new \InvalidArgumentException('Invalid generation type');
        }

        $models = self::modelOptions($tenantId, $generationType);
        $applications = [];
        $unavailable = [];
        $adapterOptions = [];
        foreach (self::applicationProducts($tenantId, $generationType) as $product) {
            $matched = false;
            try {
                $adapter = self::adapterForProduct($generationType, $product);
            } catch (\Throwable $e) {
                $unavailable[] = self::unavailable($product, '该应用 API 尚未接入算力市场运行适配器');
                continue;
            }
            if (!array_key_exists($adapter, $adapterOptions)) {
                try {
                    $adapterOptions[$adapter] = (array)$adapter::options($tenantId);
                } catch (\Throwable) {
                    $adapterOptions[$adapter] = [];
                }
            }
            foreach ($adapterOptions[$adapter] as $option) {
                if (!is_array($option) || (int)($option['market_product_id'] ?? 0) !== (int)$product['id']) {
                    continue;
                }
                if (!self::isVisibleOption($option)) {
                    continue;
                }
                $option['generation_type'] = $generationType;
                if (!isset($option['category_name'])) {
                    $option['category_name'] = self::categoryName($generationType);
                }
                $applications[] = $option;
                $matched = true;
            }
            if (!$matched) {
                $unavailable[] = self::unavailable($product, 'Market application API has no sellable runtime option');
            }
        }

        return [
            'generation_type' => $generationType,
            'models' => $models,
            'applications' => $applications,
            'unavailable_applications' => $unavailable,
            'channels' => array_merge($models, $applications),
        ];
    }

    private static function adapterForProduct(string $type, array $product): string
    {
        $categoryCode = (string)(PowerMarketService::appCategory($product)['category_code'] ?? '');
        if ($type === 'video' || ($type === 'app_api' && $categoryCode === 'video')) {
            return MarketVideoAppRuntimeService::class;
        }
        if (($type === 'image' || ($type === 'app_api' && $categoryCode === 'image'))
            && (string)($product['upstream_app_code'] ?? '') === 'nano_banana') {
            return MarketNanoBananaAppRuntimeService::class;
        }
        return MarketApplicationApiRuntimeService::adapterForSelection([
            'upstream_app_code' => (string)($product['upstream_app_code'] ?? ''),
            'upstream_api_code' => (string)($product['upstream_api_code'] ?? ''),
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'api_code' => (string)($product['upstream_api_code'] ?? ''),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private static function modelOptions(int $tenantId, string $type): array
    {
        $options = [];
        if ($type === 'text') {
            foreach (MarketTextModelRuntimeService::modelGroups($tenantId) as $group) {
                foreach ((array)($group['options'] ?? []) as $option) {
                    if (is_array($option)) {
                        $option['resource_type'] = PowerMarketService::TYPE_MODEL;
                        $option['model_type'] = 'text';
                        $option['category_code'] = 'text';
                        $options[(string)($option['id'] ?? $option['model_code'] ?? count($options))] = $option;
                    }
                }
            }
        } elseif ($type === 'image') {
            $options = array_column(MarketImageModelRuntimeService::options($tenantId), null, 'id');
            foreach ($options as &$option) {
                $option['resource_type'] = PowerMarketService::TYPE_MODEL;
                $option['model_type'] = 'image';
                $option['category_code'] = 'image';
            }
            unset($option);
        } elseif ($type === 'video') {
            foreach (MarketVideoModelRuntimeService::options($tenantId) as $option) {
                $option['resource_type'] = PowerMarketService::TYPE_MODEL;
                $option['model_type'] = 'video';
                $option['category_code'] = 'video';
                $options[(string)($option['id'] ?? count($options))] = $option;
            }
        }
        return self::visibleOptions(array_values($options));
    }

    /** @param array<int, array<string, mixed>> $options */
    private static function visibleOptions(array $options): array
    {
        return array_values(array_filter($options, static function (array $option): bool {
            return self::isVisibleOption($option);
        }));
    }

    private static function isVisibleOption(array $option): bool
    {
        $status = (int)($option['status'] ?? (($option['enabled'] ?? true) === false || ($option['available'] ?? true) === false ? 0 : 1));
        return $status === 1
            && ($option['enabled'] ?? true) !== false
            && ($option['available'] ?? true) !== false;
    }

    /** @return array<int, array<string, mixed>> */
    private static function applicationProducts(int $tenantId, string $type): array
    {
        $products = PowerMarketProduct::where([
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'status' => 1,
        ])->order(['update_time' => 'desc', 'id' => 'desc'])->select()->toArray();
        TenantPowerMarketService::applyProductDisplays($tenantId, $products);
        return array_values(array_filter($products, static function (array $product) use ($tenantId, $type): bool {
            if ($type === 'app_api') {
                return true;
            }
            return (string)(PowerMarketService::appCategory($product)['category_code'] ?? '') === $type;
        }));
    }

    /** @return array<string, mixed> */
    private static function unavailable(array $product, string $reason): array
    {
        $category = PowerMarketService::appCategory($product);
        return [
            'id' => 'market_app_unavailable:' . (int)$product['id'],
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'app_code' => (string)($product['upstream_app_code'] ?? ''),
            'api_code' => (string)($product['upstream_api_code'] ?? ''),
            'market_product_id' => (int)$product['id'],
            'category_code' => (string)($category['category_code'] ?? ''),
            'category_name' => (string)($category['category_name'] ?? ''),
            'name' => (string)($product['name'] ?? ''),
            'available' => false,
            'enabled' => false,
            'status' => 0,
            'unavailable_reason' => trim($reason) ?: 'No market application API adapter is registered',
            'reason' => trim($reason) ?: 'No market application API adapter is registered',
        ];
    }

    private static function categoryName(string $type): string
    {
        return ['text' => '文本生成', 'image' => '图片生成', 'video' => '视频生成', 'app_api' => '应用 API'][$type] ?? $type;
    }
}

