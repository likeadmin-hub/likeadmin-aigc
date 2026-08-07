<?php

namespace app\common\service\app\aigc_canvas\agent\model;

use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\power\TenantPowerMarketSkuPrice;
use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketMusicAppRuntimeService;
use app\common\service\power\MarketNanoBananaAppRuntimeService;
use app\common\service\power\MarketVideoAppRuntimeService;
use app\common\service\power\MarketVideoModelRuntimeService;
use app\common\service\power\PowerMarketService;
use Exception;

class CanvasModelRouterService
{
    public static function applyToToolInput(int $tenantId, string $toolCode, array $params): array
    {
        return match ($toolCode) {
            'generate_image' => self::applyImageMarketSelection($tenantId, $params),
            'generate_video' => self::applyVideoMarketSelection($tenantId, $params),
            'generate_music' => self::applyMusicMarketSelection($tenantId, $params),
            default => $params,
        };
    }

    public static function toolPricing(int $tenantId, string $toolCode, array $params = []): array
    {
        try {
            if ($toolCode === 'generate_image') {
                $params = self::applyImageMarketSelection($tenantId, $params + ['quantity' => 1]);
                $selection = self::marketSelectionFromParams($params);
                $quote = MarketNanoBananaAppRuntimeService::isSelection($selection)
                    ? MarketNanoBananaAppRuntimeService::quote($tenantId, $selection, (int)($params['quantity'] ?? 1))
                    : MarketImageModelRuntimeService::quote($tenantId, $selection, (int)($params['quantity'] ?? 1));
                return self::formatQuote((string)($quote['price_source'] ?? 'power_market_sku'), $quote, $params);
            }
            if ($toolCode === 'generate_video') {
                $params = self::applyVideoMarketSelection($tenantId, $params);
                $runtime = self::videoMarketRuntime($params);
                $quote = $runtime::quote($tenantId, self::marketSelectionFromParams($params));
                return self::formatQuote('power_market_video', $quote, $params);
            }
            if ($toolCode === 'generate_music') {
                $quote = MarketMusicAppRuntimeService::quote($tenantId, self::marketSelectionFromParams($params));
                return self::formatQuote('power_market_app_api', $quote, $params);
            }
        } catch (\Throwable $e) {
            return [
                'price_source' => 'power_market_sku',
                'available' => false,
                'message' => $e->getMessage(),
            ];
        }

        return [
            'price_source' => '',
            'available' => false,
            'message' => '',
        ];
    }

    public static function marketOverview(int $tenantId): array
    {
        return [
            'image' => self::imageOverview($tenantId),
            'video' => self::videoOverview($tenantId),
            'music' => self::musicOverview($tenantId),
        ];
    }

    private static function applyImageMarketSelection(int $tenantId, array $params): array
    {
        $params = self::normalizeNanoBananaSelectionFromSku($params);
        if (MarketNanoBananaAppRuntimeService::isSelection($params)) {
            $quote = MarketNanoBananaAppRuntimeService::quote($tenantId, self::marketSelectionFromParams($params), (int)($params['quantity'] ?? 1));
            $params['market_product_id'] = (int)($quote['market_product_id'] ?? 0);
            $params['market_sku_id'] = (int)($quote['market_sku_id'] ?? 0);
            $params['sku_id'] = (int)($quote['market_sku_id'] ?? 0);
            $params['price_source'] = 'power_market_app_api';
            return $params;
        }

        $market = self::resolveImageMarket($tenantId, $params);
        if ($market === []) {
            return $params;
        }

        $product = $market['product'];
        $sku = $market['sku'];
        $locked = self::arrayValue($sku['locked_params'] ?? []);
        $quality = self::firstString($params, ['quality', 'resolution'])
            ?: self::firstString($locked, ['quality', 'resolution', 'image_size', 'size'])
            ?: (string)($params['quality'] ?? '');
        $ratio = self::normalizedRatio(self::firstString($params, ['ratio', 'size', 'aspect_ratio']))
            ?: self::normalizedRatio(self::firstString($locked, ['ratio', 'aspect_ratio']))
            ?: self::ratioFromSize($locked);

        $params['channel'] = self::marketImageModelId((int)$product['id']);
        $params['model_id'] = self::marketImageModelId((int)$product['id']);
        $params['quality'] = $quality;
        $params['ratio'] = $ratio;
        $params['market_product_id'] = (int)$product['id'];
        $params['market_sku_id'] = (int)$sku['id'];
        $params['sku_id'] = (int)$sku['id'];
        $params['price_source'] = 'power_market_sku';
        return $params;
    }

    private static function normalizeNanoBananaSelectionFromSku(array $params): array
    {
        if (MarketNanoBananaAppRuntimeService::isSelection($params)) {
            return $params;
        }
        $skuId = (int)($params['market_sku_id'] ?? $params['sku_id'] ?? $params['image_sku_id'] ?? 0);
        if ($skuId <= 0) {
            return $params;
        }
        $sku = PowerMarketSku::where(['id' => $skuId, 'status' => 1, 'sale_status' => 1])->findOrEmpty();
        if ($sku->isEmpty()) {
            return $params;
        }
        $product = PowerMarketProduct::where([
            'id' => (int)$sku['product_id'],
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'upstream_app_code' => 'nano_banana',
            'upstream_api_code' => 'submit',
            'status' => 1,
        ])->findOrEmpty();
        if ($product->isEmpty()) {
            return $params;
        }
        $locked = self::arrayValue($sku['locked_params'] ?? []);
        $model = trim((string)($locked['model'] ?? ''));
        if ($model === '') {
            return $params;
        }
        $selection = 'market_nano_banana:' . (int)$product['id'] . ':' . base64_encode($model);
        $params['channel'] = $selection;
        $params['model_id'] = $selection;
        $params['image_model_id'] = $selection;
        $params['market_product_id'] = (int)$product['id'];
        $params['market_sku_id'] = $skuId;
        $params['sku_id'] = $skuId;
        if (empty($params['quality']) && !empty($locked['resolution'])) {
            $params['quality'] = (string)$locked['resolution'];
            $params['resolution'] = (string)$locked['resolution'];
        }
        return $params;
    }

    private static function applyVideoMarketSelection(int $tenantId, array $params): array
    {
        $selection = self::marketSelectionFromParams($params);
        $hasMarket = !empty($selection['market_sku_id'])
            || !empty($selection['market_product_id'])
            || str_starts_with((string)($selection['channel'] ?? ''), 'market_video_')
            || str_starts_with((string)($selection['model_id'] ?? ''), 'market_video_');
        if (!$hasMarket) {
            $default = self::defaultVideoChannel($tenantId);
            if ($default !== '') {
                $selection['channel'] = $default;
                $selection['model_id'] = $default;
            }
        }
        if (!empty($selection['market_sku_id']) && empty($selection['channel']) && empty($selection['model_id'])) {
            $product = self::productForSku((int)$selection['market_sku_id']);
            if ($product !== []) {
                $selection['channel'] = self::videoChannelForProduct($product);
                $selection['model_id'] = $selection['channel'];
            }
        }
        if (!empty($selection['market_product_id']) && empty($selection['channel']) && empty($selection['model_id'])) {
            $product = PowerMarketProduct::where(['id' => (int)$selection['market_product_id'], 'status' => 1])->findOrEmpty();
            if (!$product->isEmpty()) {
                $selection['channel'] = self::videoChannelForProduct($product->toArray());
                $selection['model_id'] = $selection['channel'];
            }
        }
        return array_merge($params, $selection);
    }

    private static function applyMusicMarketSelection(int $tenantId, array $params): array
    {
        try {
            $quote = MarketMusicAppRuntimeService::quote($tenantId, self::marketSelectionFromParams($params));
            $params['market_product_id'] = (int)($quote['market_product_id'] ?? 0);
            $params['market_sku_id'] = (int)($quote['market_sku_id'] ?? 0);
            $params['sku_id'] = (int)($quote['market_sku_id'] ?? 0);
            $params['price_source'] = 'power_market_app_api';
        } catch (\Throwable) {
            // Keep the existing music task flow as a fallback until market music callbacks are unified.
        }
        return $params;
    }

    private static function resolveImageMarket(int $tenantId, array $params): array
    {
        $skuId = (int)($params['market_sku_id'] ?? $params['sku_id'] ?? $params['image_sku_id'] ?? 0);
        if ($skuId > 0) {
            $sku = PowerMarketSku::where(['id' => $skuId, 'status' => 1, 'sale_status' => 1])->findOrEmpty();
            if ($sku->isEmpty()) {
                throw new Exception('所选图片 SKU 已下架');
            }
            $product = PowerMarketProduct::where(['id' => (int)$sku['product_id'], 'status' => 1])->findOrEmpty();
            if ($product->isEmpty() || (string)$product['resource_type'] !== PowerMarketService::TYPE_MODEL || (string)$product['model_type'] !== 'image') {
                throw new Exception('所选 SKU 不是可用的图片模型');
            }
            return self::marketRow($tenantId, $product->toArray(), $sku->toArray());
        }

        $productId = self::marketProductId($params, 'market_image_model:');
        if ($productId > 0) {
            $product = PowerMarketProduct::where(['id' => $productId, 'resource_type' => PowerMarketService::TYPE_MODEL, 'model_type' => 'image', 'status' => 1])->findOrEmpty();
            if ($product->isEmpty()) {
                throw new Exception('所选图片模型已下架');
            }
            return self::resolveImageProductSku($tenantId, $product->toArray(), $params);
        }

        $products = PowerMarketProduct::where([
            'resource_type' => PowerMarketService::TYPE_MODEL,
            'model_type' => 'image',
            'status' => 1,
        ])->order(['update_time' => 'desc', 'id' => 'desc'])->select()->toArray();
        foreach ($products as $product) {
            try {
                return self::resolveImageProductSku($tenantId, $product, $params);
            } catch (\Throwable) {
            }
        }
        return [];
    }

    private static function resolveImageProductSku(int $tenantId, array $product, array $params): array
    {
        $quality = self::firstString($params, ['quality', 'resolution']);
        $ratio = self::normalizedRatio(self::firstString($params, ['ratio', 'size', 'aspect_ratio']));
        $rows = PowerMarketSku::where(['product_id' => (int)$product['id'], 'status' => 1, 'sale_status' => 1])
            ->order(['sort' => 'desc', 'id' => 'asc'])
            ->select()
            ->toArray();
        foreach ($rows as $sku) {
            $locked = self::arrayValue($sku['locked_params'] ?? []);
            if ($quality !== '') {
                $skuQuality = self::firstString($locked, ['quality', 'resolution', 'image_size', 'size']);
                if ($skuQuality !== '' && strcasecmp($skuQuality, $quality) !== 0) {
                    continue;
                }
            }
            if ($ratio !== '') {
                $skuRatio = self::firstString($locked, ['ratio', 'aspect_ratio']) ?: self::ratioFromSize($locked);
                if ($skuRatio !== '' && strcasecmp($skuRatio, $ratio) !== 0) {
                    continue;
                }
            }
            return self::marketRow($tenantId, $product, $sku);
        }
        throw new Exception('当前图片模型没有可用的算力市场 SKU');
    }

    private static function marketRow(int $tenantId, array $product, array $sku): array
    {
        $tenant = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => (int)$sku['id']])->findOrEmpty();
        if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) {
            throw new Exception('租户未上架该算力市场 SKU');
        }
        return [
            'product' => $product,
            'sku' => $sku,
            'tenant_price' => $tenant->isEmpty() ? (float)$sku['sale_points'] : (float)$tenant['sale_points'],
        ];
    }

    private static function defaultVideoChannel(int $tenantId): string
    {
        $market = self::videoOverview($tenantId);
        return (string)($market['default'] ?? '');
    }

    private static function imageOverview(int $tenantId): array
    {
        try {
            $items = array_merge(
                MarketImageModelRuntimeService::options($tenantId),
                MarketNanoBananaAppRuntimeService::options($tenantId)
            );
            $items = self::availableRuntimeOptions($items);
            return ['default' => (string)(($items[0] ?? [])['id'] ?? ''), 'options' => $items];
        } catch (\Throwable) {
            return ['default' => '', 'options' => []];
        }
    }

    private static function availableRuntimeOptions(array $items): array
    {
        return array_values(array_filter($items, static function ($item): bool {
            if (!is_array($item)) {
                return false;
            }
            $status = (int)($item['status'] ?? (($item['enabled'] ?? true) === false || ($item['available'] ?? true) === false ? 0 : 1));
            return $status === 1
                && ($item['enabled'] ?? true) !== false
                && ($item['available'] ?? true) !== false
                && !empty($item['skus']);
        }));
    }

    private static function videoOverview(int $tenantId): array
    {
        try {
            $models = MarketVideoModelRuntimeService::options($tenantId);
            $apps = MarketVideoAppRuntimeService::options($tenantId);
            $items = array_merge($models, $apps);
            return ['default' => (string)(($items[0] ?? [])['id'] ?? ''), 'options' => $items];
        } catch (\Throwable) {
            return ['default' => '', 'options' => []];
        }
    }

    private static function musicOverview(int $tenantId): array
    {
        try {
            $items = MarketMusicAppRuntimeService::options($tenantId);
            if ($items !== []) {
                return ['default' => (string)(($items[0] ?? [])['value'] ?? ''), 'options' => $items];
            }
            $quote = MarketMusicAppRuntimeService::quote($tenantId, []);
            return [
                'default' => (string)($quote['market_sku_id'] ?? ''),
                'options' => [[
                    'value' => (string)($quote['market_sku_id'] ?? ''),
                    'label' => '音乐生成 API',
                    'market_product_id' => (int)($quote['market_product_id'] ?? 0),
                    'market_sku_id' => (int)($quote['market_sku_id'] ?? 0),
                    'tenant_unit_price' => (float)($quote['user_unit_points'] ?? 0),
                    'platform_unit_cost' => (float)($quote['tenant_unit_points'] ?? 0),
                ]],
            ];
        } catch (\Throwable) {
            return ['default' => '', 'options' => []];
        }
    }

    private static function marketImageModelId(int $productId): string
    {
        return 'market_image_model:' . $productId;
    }

    private static function videoChannelForProduct(array $product): string
    {
        $prefix = (string)($product['resource_type'] ?? '') === PowerMarketService::TYPE_APP_API
            ? 'market_video_app:'
            : 'market_video_model:';
        return $prefix . (int)$product['id'];
    }

    private static function formatQuote(string $source, array $quote, array $params): array
    {
        return [
            'price_source' => (string)($quote['price_source'] ?? $source),
            'available' => true,
            'market_product_id' => (int)($quote['market_product_id'] ?? $params['market_product_id'] ?? 0),
            'market_sku_id' => (int)($quote['market_sku_id'] ?? $params['market_sku_id'] ?? 0),
            'quantity' => (float)($quote['quantity'] ?? $params['quantity'] ?? 1),
            'tenant_cost_points' => (float)($quote['tenant_cost_points'] ?? 0),
            'user_charge_points' => (float)($quote['user_charge_points'] ?? 0),
            'tenant_unit_points' => (float)($quote['tenant_unit_points'] ?? $quote['platform_unit_cost'] ?? 0),
            'user_unit_points' => (float)($quote['user_unit_points'] ?? $quote['tenant_unit_price'] ?? 0),
            'billing_unit' => (string)($quote['billing_unit'] ?? ''),
            'settlement_mode' => (string)($quote['settlement_mode'] ?? 'reserved'),
        ];
    }

    private static function videoMarketRuntime(array $selection): string
    {
        $value = implode('|', array_map('strval', [
            $selection['resource_type'] ?? '',
            $selection['model_id'] ?? '',
            $selection['channel'] ?? '',
        ]));
        return str_contains($value, 'app_api') || str_contains($value, 'market_video_app:')
            ? MarketVideoAppRuntimeService::class
            : MarketVideoModelRuntimeService::class;
    }

    private static function marketOption(string $value, array $market): array
    {
        $product = $market['product'];
        $sku = $market['sku'];
        return [
            'value' => $value,
            'label' => (string)($product['name'] ?? $value),
            'market_product_id' => (int)$product['id'],
            'market_sku_id' => (int)$sku['id'],
            'sku_key' => (string)$sku['sku_key'],
            'tenant_unit_price' => (float)$market['tenant_price'],
            'platform_unit_cost' => (float)$sku['sale_points'],
            'locked_params' => self::arrayValue($sku['locked_params'] ?? []),
        ];
    }

    private static function marketSelectionFromParams(array $params): array
    {
        $selection = is_array($params['params'] ?? null) ? (array)$params['params'] : [];
        $selection = array_merge($selection, $params);
        if (empty($selection['market_sku_id']) && !empty($selection['sku_id'])) {
            $selection['market_sku_id'] = (int)$selection['sku_id'];
        }
        if (empty($selection['model_id']) && !empty($selection['channel'])) {
            $selection['model_id'] = $selection['channel'];
        }
        if (empty($selection['resolution']) && !empty($selection['quality'])) {
            $selection['resolution'] = $selection['quality'];
        }
        return $selection;
    }

    private static function productForSku(int $skuId): array
    {
        $sku = PowerMarketSku::where(['id' => $skuId, 'status' => 1, 'sale_status' => 1])->findOrEmpty();
        if ($sku->isEmpty()) {
            return [];
        }
        $product = PowerMarketProduct::where(['id' => (int)$sku['product_id'], 'status' => 1])->findOrEmpty();
        return $product->isEmpty() ? [] : $product->toArray();
    }

    private static function marketProductId(array $params, string $prefix): int
    {
        $productId = (int)($params['market_product_id'] ?? $params['product_id'] ?? 0);
        if ($productId > 0) {
            return $productId;
        }
        foreach (['channel', 'model', 'model_id', 'image_model_id'] as $key) {
            $value = (string)($params[$key] ?? '');
            if (str_starts_with($value, $prefix)) {
                return (int)substr($value, strlen($prefix));
            }
        }
        return 0;
    }

    private static function firstString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($source[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private static function ratioFromSize(array $source): string
    {
        $width = (int)($source['width'] ?? $source['w'] ?? 0);
        $height = (int)($source['height'] ?? $source['h'] ?? 0);
        if ($width <= 0 || $height <= 0) {
            return '';
        }
        $gcd = self::gcd($width, $height);
        return ((int)($width / $gcd)) . ':' . ((int)($height / $gcd));
    }

    private static function normalizedRatio(string $ratio): string
    {
        $ratio = trim($ratio);
        if ($ratio === '') {
            return '';
        }
        $normalized = strtolower($ratio);
        if (in_array($normalized, ['auto', 'adaptive', 'default', 'original'], true)) {
            return '';
        }
        if (in_array($ratio, ['默认', '自适应', '原比例'], true)) {
            return '';
        }
        return $ratio;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return max(1, abs($a));
    }

    private static function arrayValue($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
