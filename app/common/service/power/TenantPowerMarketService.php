<?php

namespace app\common\service\power;

use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\power\TenantPowerMarketSkuPrice;
use app\common\model\power\TenantPowerMarketProduct;
use Exception;
use think\facade\Db;

class TenantPowerMarketService
{
    public static function models(int $tenantId, string $keyword = '', $status = '', int $pageNo = 1, int $pageSize = 15, string $modelType = ''): array
    {
        $query = PowerMarketProduct::where(['resource_type' => PowerMarketService::TYPE_MODEL, 'status' => 1]);
        $keyword = trim($keyword);
        $modelType = trim($modelType);
        if (in_array($modelType, ['text', 'image', 'video'], true)) {
            $query->where('model_type', $modelType);
        }
        if ($keyword !== '') {
            $query->whereLike('name|product_code|upstream_model_code|upstream_channel_code', '%' . $keyword . '%');
        }
        $rows = $query->order(['update_time' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();
        self::attachProductStats($tenantId, $rows);
        self::applyProductDisplays($tenantId, $rows);
        $rows = array_values(array_filter($rows, static fn (array $row): bool => (int)($row['platform_status'] ?? 0) === 1));
        if ($status !== '' && $status !== null) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => (int)$row['status'] === (int)$status));
        }
        return self::paginate($rows, $pageNo, $pageSize);
    }

    public static function apps(int $tenantId, string $keyword = '', $status = '', int $pageNo = 1, int $pageSize = 15): array
    {
        $products = PowerMarketProduct::where([
            'resource_type' => PowerMarketService::TYPE_APP_API,
            'status' => 1,
        ])->order(['update_time' => 'desc', 'id' => 'desc'])->select()->toArray();
        self::attachProductStats($tenantId, $products);

        $groups = [];
        foreach ($products as $product) {
            if ((int)($product['platform_status'] ?? 0) !== 1) {
                continue;
            }
            $appCode = trim((string)($product['upstream_app_code'] ?? ''));
            if ($appCode === '') {
                continue;
            }
            $payload = self::arrayValue($product['source_payload'] ?? []);
            $resource = (array)($payload['resource'] ?? []);
            $metadata = (array)($payload['market_metadata'] ?? []);
            if (!isset($groups[$appCode])) {
                $groups[$appCode] = [
                    'app_code' => $appCode,
                    'name' => trim((string)($resource['app_name'] ?? $metadata['app_name'] ?? $appCode)),
                    'description' => trim((string)($metadata['app_description'] ?? $resource['description'] ?? '')),
                    'api_count' => 0,
                    'sku_count' => 0,
                    'min_price' => null,
                    'status' => 0,
                    'status_text' => '下架',
                    'update_time' => 0,
                ];
            }
            $group = &$groups[$appCode];
            $group['api_count']++;
            $group['sku_count'] += (int)($product['sku_count'] ?? 0);
            if ((int)($product['sku_count'] ?? 0) > 0) {
                $price = (float)($product['min_price'] ?? 0);
                $group['min_price'] = $group['min_price'] === null ? $price : min($group['min_price'], $price);
            }
            $group['status'] = max((int)$group['status'], (int)($product['status'] ?? 0));
            $group['status_text'] = (int)$group['status'] === 1 ? '上架' : '下架';
            $group['update_time'] = max((int)$group['update_time'], (int)($product['update_time'] ?? 0));
            unset($group);
        }

        $groups = array_values($groups);
        foreach ($groups as &$group) {
            $group['min_price'] = (float)($group['min_price'] ?? 0);
            if ($group['description'] === '') {
                $group['description'] = '包含 ' . $group['api_count'] . ' 个可计费 API';
            }
        }
        unset($group);
        $keyword = mb_strtolower(trim($keyword));
        if ($keyword !== '') {
            $groups = array_values(array_filter($groups, static function (array $group) use ($keyword): bool {
                return str_contains(mb_strtolower($group['name'] . ' ' . $group['app_code'] . ' ' . $group['description']), $keyword);
            }));
        }
        if ($status !== '' && $status !== null) {
            $groups = array_values(array_filter($groups, static fn (array $group): bool => (int)$group['status'] === (int)$status));
        }
        usort($groups, static fn (array $left, array $right): int => [$right['status'], $right['update_time'], $left['app_code']] <=> [$left['status'], $left['update_time'], $right['app_code']]);
        return self::paginate($groups, $pageNo, $pageSize);
    }

    public static function detail(int $tenantId, int $productId): array
    {
        $product = PowerMarketProduct::where(['id' => $productId, 'status' => 1])->findOrEmpty();
        if ($product->isEmpty()) {
            return [];
        }
        $data = PowerMarketService::detail($productId);
        $data['skus'] = array_values(array_filter((array)($data['skus'] ?? []), static fn (array $sku): bool => self::isPlatformAvailableSku($sku)));
        if ($data['skus'] === []) {
            return [];
        }
        $data['skus'] = self::applyTenantPrices($tenantId, $data['skus']);
        self::applyProductDisplays($tenantId, $data);
        return $data;
    }

    public static function saveProductDisplay(int $tenantId, int $productId, array $params): void
    {
        $product = PowerMarketProduct::where(['id' => $productId, 'resource_type' => PowerMarketService::TYPE_MODEL, 'status' => 1])->findOrEmpty();
        if ($product->isEmpty()) {
            throw new Exception('模型商品不存在或已下架');
        }
        $row = TenantPowerMarketProduct::where(['tenant_id' => $tenantId, 'product_id' => $productId])->findOrEmpty();
        $data = [
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'name' => mb_substr(trim((string)($params['name'] ?? '')), 0, 160, 'UTF-8'),
            'icon' => mb_substr(trim((string)($params['icon'] ?? '')), 0, 500, 'UTF-8'),
            'description' => mb_substr(trim((string)($params['description'] ?? '')), 0, 1000, 'UTF-8'),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            TenantPowerMarketProduct::create($data);
            return;
        }
        $row->save($data);
    }

    public static function appDetail(int $tenantId, string $appCode): array
    {
        $data = PowerMarketService::appDetail($appCode);
        if ($data === []) {
            return [];
        }
        $apis = [];
        foreach ((array)($data['apis'] ?? []) as $api) {
            if ((int)($api['status'] ?? 0) !== 1) {
                continue;
            }
            $api['skus'] = array_values(array_filter((array)($api['skus'] ?? []), static fn (array $sku): bool => self::isPlatformAvailableSku($sku)));
            if ($api['skus'] === []) {
                continue;
            }
            $api['skus'] = self::applyTenantPrices($tenantId, $api['skus']);
            $apis[] = $api;
        }
        $data['apis'] = $apis;
        if ($data['apis'] === []) {
            return [];
        }
        $rows = $data['apis'];
        self::attachProductStats($tenantId, $rows);
        $data['status'] = max(array_column($rows, 'status'));
        $data['status_text'] = (int)$data['status'] === 1 ? '上架' : '下架';
        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $prices
     */
    public static function savePrices(int $tenantId, int $productId, array $prices): void
    {
        if ($tenantId <= 0) {
            throw new Exception('租户信息无效');
        }
        $product = PowerMarketProduct::where(['id' => $productId, 'status' => 1])->findOrEmpty();
        if ($product->isEmpty()) {
            throw new Exception('商品已下架，不能配置');
        }
        $skuById = [];
        foreach (PowerMarketSku::where('product_id', $productId)->select()->toArray() as $sku) {
            $skuById[(int)$sku['id']] = $sku;
        }
        Db::transaction(function () use ($tenantId, $prices, $skuById): void {
            foreach ($prices as $price) {
                $skuId = (int)($price['sku_id'] ?? $price['id'] ?? 0);
                if ($skuId <= 0 || !isset($skuById[$skuId])) {
                    throw new Exception('计费 SKU 不存在');
                }
                $platformEnabled = self::isPlatformAvailableSku($skuById[$skuId]);
                if (!$platformEnabled) {
                    throw new Exception('平台商品已下架，不能配置');
                }
                $cost = max(0, (float)($skuById[$skuId]['sale_points'] ?? 0));
                $sale = round(max(0, (float)($price['sale_points'] ?? 0)), 6);
                if ($sale < $cost) {
                    throw new Exception('租户售价不能低于平台成本价');
                }
                $row = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => $skuId])->findOrEmpty();
                $data = [
                    'tenant_id' => $tenantId,
                    'sku_id' => $skuId,
                    'sale_points' => $sale,
                    'sale_status' => (int)($price['sale_status'] ?? 1) === 1 ? 1 : 0,
                    'update_time' => time(),
                ];
                if ($row->isEmpty()) {
                    $data['create_time'] = time();
                    TenantPowerMarketSkuPrice::create($data);
                } else {
                    $row->save($data);
                }
            }
        });
    }

    /**
     * 批量更新租户模型 SKU 的上架状态。
     *
     * 已配置 SKU 只更新状态，保留租户自主定价；未配置 SKU 首次按平台成本价创建。
     * 当 all 为 true 时，按当前模型市场筛选条件处理全部匹配商品，而不受前端分页限制。
     *
     * @param array<int, mixed> $productIds
     * @return array{products: int, skus: int}
     */
    public static function batchShelf(
        int $tenantId,
        array $productIds,
        int $saleStatus,
        bool $all = false,
        string $keyword = '',
        string $modelType = '',
        $status = ''
    ): array {
        if ($tenantId <= 0) {
            throw new Exception('租户信息无效');
        }

        $query = PowerMarketProduct::where([
            'resource_type' => PowerMarketService::TYPE_MODEL,
            'status' => 1,
        ]);
        $modelType = trim($modelType);
        if (in_array($modelType, ['text', 'image', 'video'], true)) {
            $query->where('model_type', $modelType);
        }
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $query->whereLike('name|product_code|upstream_model_code|upstream_channel_code', '%' . $keyword . '%');
        }
        if (!$all) {
            $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
            if ($productIds === []) {
                throw new Exception('请选择模型商品');
            }
            $query->whereIn('id', $productIds);
        }

        $products = $query->select()->toArray();
        self::attachProductStats($tenantId, $products);
        $products = array_values(array_filter($products, static function (array $product) use ($all, $status): bool {
            if ((int)($product['platform_status'] ?? 0) !== 1) {
                return false;
            }
            return !$all || $status === '' || $status === null || (int)$product['status'] === (int)$status;
        }));
        if ($products === []) {
            throw new Exception('当前筛选条件下没有可操作的模型');
        }

        $productIds = array_values(array_unique(array_map(static fn (array $product): int => (int)$product['id'], $products)));
        $skus = PowerMarketSku::whereIn('product_id', $productIds)->select()->toArray();
        $skus = array_values(array_filter($skus, static fn (array $sku): bool => self::isPlatformAvailableSku($sku)));
        if ($skus === []) {
            throw new Exception('当前筛选条件下没有可操作的计费 SKU');
        }

        $skuIds = array_values(array_unique(array_map(static fn (array $sku): int => (int)$sku['id'], $skus)));
        $configured = TenantPowerMarketSkuPrice::where('tenant_id', $tenantId)
            ->whereIn('sku_id', $skuIds)
            ->select()
            ->toArray();
        $configuredBySku = array_column($configured, null, 'sku_id');
        $saleStatus = $saleStatus === 1 ? 1 : 0;
        $now = time();

        Db::transaction(function () use ($tenantId, $skus, $configuredBySku, $saleStatus, $now): void {
            foreach ($skus as $sku) {
                $skuId = (int)$sku['id'];
                $configured = $configuredBySku[$skuId] ?? null;
                if ($configured !== null) {
                    TenantPowerMarketSkuPrice::where('id', (int)$configured['id'])->update([
                        'sale_status' => $saleStatus,
                        'update_time' => $now,
                    ]);
                    continue;
                }
                TenantPowerMarketSkuPrice::create([
                    'tenant_id' => $tenantId,
                    'sku_id' => $skuId,
                    'sale_points' => max(0, (float)($sku['sale_points'] ?? 0)),
                    'sale_status' => $saleStatus,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
        });

        return ['products' => count($productIds), 'skus' => count($skus)];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function attachProductStats(int $tenantId, array &$rows): void
    {
        $ids = array_values(array_filter(array_column($rows, 'id')));
        $stats = [];
        if ($ids !== []) {
            $skus = PowerMarketSku::whereIn('product_id', $ids)->select()->toArray();
            $skuIds = array_values(array_filter(array_column($skus, 'id')));
            $tenantPrices = $skuIds === [] ? [] : TenantPowerMarketSkuPrice::where('tenant_id', $tenantId)->whereIn('sku_id', $skuIds)->select()->toArray();
            $pricesBySku = [];
            foreach ($tenantPrices as $price) {
                $pricesBySku[(int)$price['sku_id']] = $price;
            }
            foreach ($skus as $sku) {
                $platformEnabled = self::isPlatformAvailableSku($sku);
                if (!$platformEnabled) {
                    continue;
                }
                $id = (int)$sku['product_id'];
                $stats[$id]['platform_sku_count'] = (int)($stats[$id]['platform_sku_count'] ?? 0) + 1;
                $tenantPrice = $pricesBySku[(int)$sku['id']] ?? null;
                $tenantEnabled = $platformEnabled && (int)($tenantPrice['sale_status'] ?? 1) === 1;
                if (!$tenantEnabled) {
                    continue;
                }
                $stats[$id]['sku_count'] = (int)($stats[$id]['sku_count'] ?? 0) + 1;
                $platformPrice = max(0, (float)($sku['sale_points'] ?? 0));
                $price = max($platformPrice, (float)($tenantPrice['sale_points'] ?? $platformPrice));
                $stats[$id]['min_price'] = isset($stats[$id]['min_price']) ? min($stats[$id]['min_price'], $price) : $price;
            }
        }
        foreach ($rows as &$row) {
            $id = (int)$row['id'];
            $row = PowerMarketService::formatProduct($row);
            $row['sku_count'] = (int)($stats[$id]['sku_count'] ?? 0);
            $row['min_price'] = (float)($stats[$id]['min_price'] ?? 0);
            $row['platform_status'] = (int)($stats[$id]['platform_sku_count'] ?? 0) > 0 ? 1 : 0;
            $row['status'] = $row['sku_count'] > 0 ? 1 : 0;
            $row['status_text'] = $row['status'] === 1 ? '上架' : '下架';
        }
        unset($row);
    }

    /**
     * @param array<int, array<string, mixed>> $skus
     * @return array<int, array<string, mixed>>
     */
    private static function applyTenantPrices(int $tenantId, array $skus): array
    {
        $ids = array_values(array_filter(array_column($skus, 'id')));
        $configured = $ids === [] ? [] : TenantPowerMarketSkuPrice::where('tenant_id', $tenantId)->whereIn('sku_id', $ids)->select()->toArray();
        $bySku = [];
        foreach ($configured as $row) {
            $bySku[(int)$row['sku_id']] = $row;
        }
        foreach ($skus as &$sku) {
            $price = $bySku[(int)$sku['id']] ?? null;
            $platformSale = max(0, (float)($sku['sale_points'] ?? 0));
            $platformEnabled = self::isPlatformAvailableSku($sku);
            $tenantSale = $price === null ? $platformSale : max($platformSale, (float)($price['sale_points'] ?? 0));
            $sku['platform_sale_points'] = $platformSale;
            $sku['sale_points'] = $tenantSale;
            $sku['platform_sale_status'] = $platformEnabled ? 1 : 0;
            $sku['sale_status'] = $platformEnabled && (int)($price['sale_status'] ?? 1) === 1 ? 1 : 0;
            $sku['gross_margin_points'] = round($tenantSale - $platformSale, 6);
        }
        unset($sku);
        return $skus;
    }

    /** @param array<int, array<string, mixed>>|array<string, mixed> $rows */
    private static function applyProductDisplays(int $tenantId, array &$rows): void
    {
        $isOne = isset($rows['id']);
        $items = $isOne ? [&$rows] : $rows;
        $ids = array_values(array_filter(array_map(static fn (array $row): int => (int)($row['id'] ?? 0), $items)));
        if ($ids === []) {
            return;
        }
        $overrides = TenantPowerMarketProduct::where('tenant_id', $tenantId)->whereIn('product_id', $ids)->select()->toArray();
        $byProduct = array_column($overrides, null, 'product_id');
        foreach ($items as &$item) {
            $override = $byProduct[(int)$item['id']] ?? [];
            $item['origin_name'] = (string)($item['name'] ?? '');
            $item['origin_description'] = (string)($item['description'] ?? '');
            $item['display_name'] = trim((string)($override['name'] ?? '')) ?: $item['origin_name'];
            $item['display_icon'] = trim((string)($override['icon'] ?? ''));
            $item['display_description'] = trim((string)($override['description'] ?? '')) ?: $item['origin_description'];
            $item['name'] = $item['display_name'];
            $item['description'] = $item['display_description'];
        }
        unset($item);
        if (!$isOne) {
            $rows = $items;
        }
    }

    /**
     * 平台商品状态是租户市场的硬上限。
     *
     * @param array<string, mixed> $sku
     */
    private static function isPlatformAvailableSku(array $sku): bool
    {
        return (int)($sku['status'] ?? 0) === 1 && (int)($sku['sale_status'] ?? 1) === 1;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{lists: array<int, array<string, mixed>>, count: int}
     */
    private static function paginate(array $rows, int $pageNo, int $pageSize): array
    {
        $pageNo = max(1, $pageNo);
        $pageSize = min(100, max(1, $pageSize));
        return [
            'lists' => array_slice($rows, ($pageNo - 1) * $pageSize, $pageSize),
            'count' => count($rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayValue($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
