<?php

namespace app\common\service\power;

use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\service\app\UpstreamPricingService;
use Exception;
use think\facade\Db;

class PowerMarketService
{
    public const SOURCE_LIKEADMIN_API = 'likeadmin_api';
    public const TYPE_MODEL = 'model';
    public const TYPE_APP_API = 'app_api';
    private const UPSTREAM_RETRY_ATTEMPTS = 2;

    public static function productTypes(): array
    {
        return [
            self::TYPE_MODEL => '模型 API',
            self::TYPE_APP_API => '应用 API',
        ];
    }

    public static function detail(int $id): array
    {
        $product = PowerMarketProduct::findOrEmpty($id);
        if ($product->isEmpty()) {
            return [];
        }
        $data = self::formatProduct($product->toArray());
        // Only current upstream SKU rows belong in the editable market price
        // surface. Retired rows remain in storage for historical consumption
        // snapshots, but must never be displayed or made sellable again.
        $data['skus'] = PowerMarketSku::where('product_id', $id)
            ->where('status', 1)
            ->order(['sort' => 'desc', 'id' => 'asc'])
            ->select()
            ->toArray();
        foreach ($data['skus'] as &$sku) {
            $sku = self::formatSku($sku);
        }
        unset($sku);

        return $data;
    }

    /**
     * 保存平台给租户的成本价。上游结算价只由同步任务维护，不能被人工修改。
     *
     * @param array<int, array<string, mixed>> $prices
     */
    public static function savePrices(int $productId, array $prices): void
    {
        $product = PowerMarketProduct::findOrEmpty($productId);
        if ($product->isEmpty()) {
            throw new Exception('商品不存在');
        }
        $skuById = [];
        foreach (PowerMarketSku::where('product_id', $productId)->where('status', 1)->select()->toArray() as $sku) {
            $skuById[(int)$sku['id']] = $sku;
        }
        Db::transaction(function () use ($prices, $skuById): void {
            foreach ($prices as $price) {
                $skuId = (int)($price['sku_id'] ?? $price['id'] ?? 0);
                if ($skuId <= 0 || !isset($skuById[$skuId])) {
                    throw new Exception('计费 SKU 不存在');
                }
                $cost = max(0, (float)($skuById[$skuId]['upstream_price'] ?? 0));
                $sale = round(max(0, (float)($price['sale_points'] ?? 0)), 6);
                if ($sale < $cost) {
                    throw new Exception('租户成本价不能低于上游结算价');
                }
                PowerMarketSku::where('id', $skuId)->update([
                    'sale_points' => $sale,
                    'sale_status' => (int)($price['sale_status'] ?? $price['status'] ?? 1) === 1 ? 1 : 0,
                    'update_time' => time(),
                ]);
            }
        });
    }

    /** @param array<int, int> $productIds */
    public static function batchShelf(array $productIds, int $saleStatus): int
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($productIds === []) {
            throw new Exception('请选择模型商品');
        }
        $saleStatus = $saleStatus === 1 ? 1 : 0;
        return PowerMarketSku::whereIn('product_id', $productIds)
            ->where('status', 1)
            ->update(['sale_status' => $saleStatus, 'update_time' => time()]);
    }

    /**
     * 应用 API 在市场中按应用聚合展示，价格仍以每个 API 的 SKU 为准。
     *
     * @return array{lists: array<int, array<string, mixed>>, count: int}
     */
    public static function apps(string $keyword = '', $status = '', int $pageNo = 1, int $pageSize = 15): array
    {
        $products = PowerMarketProduct::where('resource_type', self::TYPE_APP_API)
            ->order(['status' => 'desc', 'update_time' => 'desc', 'id' => 'desc'])
            ->select()
            ->toArray();
        $groups = self::groupApps($products);
        $keyword = trim($keyword);
        if ($keyword !== '') {
            $keyword = mb_strtolower($keyword);
            $groups = array_values(array_filter($groups, static function (array $item) use ($keyword): bool {
                return str_contains(mb_strtolower($item['name'] . ' ' . $item['app_code'] . ' ' . $item['description']), $keyword);
            }));
        }
        if ($status !== '' && $status !== null) {
            $groups = array_values(array_filter($groups, static fn (array $item): bool => (int)$item['status'] === (int)$status));
        }
        $count = count($groups);
        $pageNo = max(1, $pageNo);
        $pageSize = min(100, max(1, $pageSize));
        return [
            'lists' => array_slice($groups, ($pageNo - 1) * $pageSize, $pageSize),
            'count' => $count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function appDetail(string $appCode): array
    {
        $appCode = trim($appCode);
        if ($appCode === '') {
            return [];
        }
        $products = PowerMarketProduct::where('resource_type', self::TYPE_APP_API)
            ->where('upstream_app_code', $appCode)
            ->order(['status' => 'desc', 'update_time' => 'desc', 'id' => 'asc'])
            ->select()
            ->toArray();
        $groups = self::groupApps($products);
        if ($groups === []) {
            return [];
        }
        $data = $groups[0];
        $data['apis'] = [];
        foreach ($products as $product) {
            $item = self::formatProduct($product);
            $item['skus'] = PowerMarketSku::where('product_id', (int)$product['id'])
                ->where('status', 1)
                ->order(['sort' => 'desc', 'id' => 'asc'])
                ->select()
            ->toArray();
            foreach ($item['skus'] as &$sku) {
                $sku = self::formatSku($sku);
            }
            unset($sku);
            $data['apis'][] = $item;
        }
        return $data;
    }

    /**
     * 将上游 API Key 实际可调用的商品价格同步为本平台的结算成本。
     *
     * @return array<string, mixed>
     */
    public static function syncFromUpstream(): array
    {
        $requests = [];
        $models = [];
        $syncedModelTypes = [];
        $emptyModelTypes = [];
        $typeErrors = [];
        foreach (['text', 'image', 'video'] as $modelType) {
            try {
                $remoteModels = self::withUpstreamRetry(fn() => UpstreamPricingService::queryModels($modelType, true));
                $syncedModelTypes[] = $modelType;
                if ($remoteModels === []) {
                    $emptyModelTypes[] = $modelType;
                }
                foreach ($remoteModels as $model) {
                    $code = trim((string)($model['model_code'] ?? $model['code'] ?? ''));
                    if ($code === '') {
                        continue;
                    }
                    $channel = trim((string)($model['channel_code'] ?? ''));
                    $models[$code . '|' . $channel] = array_merge($model, [
                        'type_code' => trim((string)($model['type_code'] ?? '')) ?: $modelType,
                    ]);
                }
            } catch (\Throwable $e) {
                // A single upstream type must not prevent other model types from reaching the market.
                $typeErrors[$modelType] = '同步失败';
            }
        }
        foreach ($models as $model) {
            $code = trim((string)($model['model_code'] ?? $model['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $modelType = self::normalizeModelType((string)($model['type_code'] ?? 'text'));
            $requests[] = [
                'local_key' => 'model:' . $code . ':' . trim((string)($model['channel_code'] ?? '')),
                'type' => self::TYPE_MODEL,
                'model' => $code,
                'channel' => trim((string)($model['channel_code'] ?? '')),
                'market_scope' => self::TYPE_MODEL . ':' . $modelType,
                'market_metadata' => [
                    'model_name' => trim((string)($model['model_name'] ?? $model['name'] ?? $code)),
                    'model_description' => trim((string)($model['description'] ?? '')),
                    'model_type' => $modelType,
                    'protocol' => (string)($model['protocol'] ?? ''),
                    'protocols' => is_array($model['protocols'] ?? null) ? $model['protocols'] : preg_split('/[,|\s]+/', (string)($model['protocols'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
                    'params_schema' => is_array($model['params_schema'] ?? null) ? $model['params_schema'] : [],
                    // These are model API defaults, not market pricing or SKU parameters.
                    // Keep them in the snapshot so business apps can call the selected model
                    // without depending on the legacy AIGC chat application's model tables.
                    'default_params' => is_array($model['default_params'] ?? null) ? $model['default_params'] : [],
                    'max_tokens' => (int)($model['max_tokens'] ?? 0),
                    'capabilities' => (array)($model['capabilities'] ?? []),
                    'input_modalities' => (array)($model['input_modalities'] ?? []),
                    'supports_vision' => !empty($model['supports_vision']),
                    // Keep the complete model response. New provider capability fields
                    // must be available to the market without another schema release.
                    'upstream_metadata' => $model,
                ],
            ];
        }
        self::appendExistingModelsForEmptyCatalogTypes($requests, $emptyModelTypes);

        $appsSynced = false;
        try {
            foreach (self::withUpstreamRetry(fn() => UpstreamPricingService::queryApps()) as $app) {
                $appCode = trim((string)($app['code'] ?? ''));
                if ($appCode === '') {
                    continue;
                }
                $metadata = [
                    'app_name' => trim((string)($app['name'] ?? $app['title'] ?? $appCode)),
                    'app_description' => trim((string)($app['description'] ?? $app['remark'] ?? '')),
                ];
                foreach ((array)($app['apis'] ?? []) as $api) {
                    if (!is_array($api)) {
                        continue;
                    }
                    $apiCode = trim((string)($api['code'] ?? ''));
                    if ($apiCode === '') {
                        continue;
                    }
                    $requests[] = [
                        'local_key' => 'app_api:' . $appCode . ':' . $apiCode,
                        'type' => self::TYPE_APP_API,
                        'app_code' => $appCode,
                        'api_code' => $apiCode,
                        'market_scope' => self::TYPE_APP_API,
                        'market_metadata' => $metadata,
                    ];
                }
            }
            $appsSynced = true;
        } catch (\Throwable $e) {
            $typeErrors['app_api'] = '同步失败';
        }

        $items = [];
        $metadataByLocalKey = [];
        $requestsByScope = [];
        foreach ($requests as $request) {
            $metadataByLocalKey[(string)$request['local_key']] = (array)($request['market_metadata'] ?? []);
            $scope = (string)($request['market_scope'] ?? '');
            if ($scope !== '') {
                $requestsByScope[$scope][] = $request;
            }
        }
        $syncedScopes = [];
        foreach ($requestsByScope as $scope => $scopeRequests) {
            try {
                $scopeItems = [];
                foreach (array_chunk($scopeRequests, 100) as $chunk) {
                    $scopeItems = array_merge($scopeItems, self::withUpstreamRetry(fn() => UpstreamPricingService::queryBatch($chunk))['items'] ?? []);
                }
                // A successful transport response with no items is not proof that
                // every local product in this scope was removed upstream. This can
                // happen while an upstream catalogue is rebuilding. Only reconcile
                // missing local products after this scope returned actual items.
                if ($scopeItems === []) {
                    continue;
                }
                $items = array_merge($items, $scopeItems);
                $syncedScopes[$scope] = true;
            } catch (\Throwable $e) {
                $typeErrors[str_replace(self::TYPE_MODEL . ':', '', $scope)] = '价格同步失败';
            }
        }
        foreach ($items as &$item) {
            $item['market_metadata'] = $metadataByLocalKey[(string)($item['local_key'] ?? '')] ?? [];
        }
        unset($item);
        $items = self::deduplicatePricingItems($items);

        $seenByScope = [];
        $summary = ['products' => 0, 'skus' => 0, 'unavailable' => 0];
        Db::transaction(function () use ($items, &$seenByScope, &$summary, $syncedScopes) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    $summary['unavailable']++;
                    continue;
                }
                $resource = (array)($item['resource'] ?? []);
                $type = (string)($item['type'] ?? '');
                $pricing = (array)($item['pricing_v2'] ?? []);
                $skuItems = array_values(array_filter((array)($pricing['items'] ?? []), 'is_array'));
                $available = !empty($item['available']) && $skuItems !== [];
                $product = self::upsertProduct($type, $resource, $item, $available);
                if ($product === null) {
                    $summary['unavailable']++;
                    continue;
                }
                $scope = $type === self::TYPE_MODEL
                    ? self::TYPE_MODEL . ':' . self::normalizeModelType((string)($product['model_type'] ?? 'text'))
                    : self::TYPE_APP_API;
                $seenByScope[$scope][] = (int)$product['id'];
                $summary['products']++;
                if (!$available) {
                    PowerMarketSku::where('product_id', (int)$product['id'])->update([
                        'status' => 0,
                        'sale_status' => 0,
                        'update_time' => time(),
                    ]);
                    $summary['unavailable']++;
                    continue;
                }
                PowerMarketSku::where('product_id', (int)$product['id'])->update([
                    'status' => 0,
                    'update_time' => time(),
                ]);
                foreach ($skuItems as $sku) {
                    self::upsertSku((int)$product['id'], $sku, $item);
                    $summary['skus']++;
                }
            }

            foreach (['text', 'image', 'video'] as $modelType) {
                $scope = self::TYPE_MODEL . ':' . $modelType;
                if (!isset($syncedScopes[$scope])) {
                    continue;
                }
                $query = PowerMarketProduct::where('source_code', self::SOURCE_LIKEADMIN_API)
                    ->where('resource_type', self::TYPE_MODEL)
                    ->where('model_type', $modelType);
                $seen = array_values(array_unique($seenByScope[$scope] ?? []));
                if ($seen !== []) {
                    $query->whereNotIn('id', $seen);
                }
                $query->update(['status' => 0, 'update_time' => time()]);
            }
            if (isset($syncedScopes[self::TYPE_APP_API])) {
                $query = PowerMarketProduct::where('source_code', self::SOURCE_LIKEADMIN_API)
                    ->where('resource_type', self::TYPE_APP_API);
                $seen = array_values(array_unique($seenByScope[self::TYPE_APP_API] ?? []));
                if ($seen !== []) {
                    $query->whereNotIn('id', $seen);
                }
                $query->update(['status' => 0, 'update_time' => time()]);
            }
        });

        $summary['retired'] = self::retireExplicitlyUnavailableModels($emptyModelTypes);

        $summary['synced_at'] = date('Y-m-d H:i:s');
        $summary['synced_model_types'] = $syncedModelTypes;
        $summary['type_errors'] = $typeErrors;
        return $summary;
    }

    /**
     * When an upstream model catalogue temporarily has no rows for a type, do
     * not infer that every local model was removed. Verify existing products via
     * the pricing endpoint and only retire models explicitly reported as gone.
     *
     * @param array<int, string> $modelTypes
     */
    private static function retireExplicitlyUnavailableModels(array $modelTypes): int
    {
        $modelTypes = array_values(array_intersect(['text', 'image', 'video'], array_unique($modelTypes)));
        if ($modelTypes === []) {
            return 0;
        }

        $products = PowerMarketProduct::where('source_code', self::SOURCE_LIKEADMIN_API)
            ->where('resource_type', self::TYPE_MODEL)
            ->where('status', 1)
            ->whereIn('model_type', $modelTypes)
            ->select()
            ->toArray();
        if ($products === []) {
            return 0;
        }

        $requests = [];
        foreach ($products as $product) {
            $model = trim((string)($product['upstream_model_code'] ?? ''));
            if ($model === '') {
                continue;
            }
            $requests[] = [
                'local_key' => (string)$product['id'],
                'type' => self::TYPE_MODEL,
                'model' => $model,
                'channel' => trim((string)($product['upstream_channel_code'] ?? '')),
            ];
        }
        if ($requests === []) {
            return 0;
        }

        $retiredIds = [];
        try {
            foreach (array_chunk($requests, 100) as $chunk) {
                $items = self::withUpstreamRetry(fn() => UpstreamPricingService::queryBatch($chunk))['items'] ?? [];
                foreach ($items as $item) {
                    if (!is_array($item) || !empty($item['available'])) {
                        continue;
                    }
                    // An empty response can be transient. Retire only when the
                    // upstream explicitly states that this model/channel is gone.
                    if (strtolower(trim((string)($item['error_code'] ?? ''))) !== 'model_not_found') {
                        continue;
                    }
                    $id = (int)($item['local_key'] ?? 0);
                    if ($id > 0) {
                        $retiredIds[$id] = $id;
                    }
                }
            }
        } catch (\Throwable $e) {
            return 0;
        }

        if ($retiredIds === []) {
            return 0;
        }
        return PowerMarketProduct::whereIn('id', array_values($retiredIds))
            ->update(['status' => 0, 'update_time' => time()]);
    }

    /**
     * The upstream catalogue can contain both a default model descriptor and a
     * channel-specific descriptor. Pricing resolves both to the same resource;
     * preserve the descriptor whose requested model/channel exactly match the
     * resolved resource so its capability metadata is not overwritten.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private static function deduplicatePricingItems(array $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $resource = (array)($item['resource'] ?? []);
            $type = trim((string)($item['type'] ?? ''));
            if ($type === self::TYPE_MODEL) {
                $code = strtolower(trim((string)($resource['model_code'] ?? '')));
                $channel = strtolower(trim((string)($resource['channel_code'] ?? '')));
                $key = $code === ''
                    ? 'local:' . (string)($item['local_key'] ?? uniqid('', true))
                    : implode(':', [$type, $code, $channel]);
            } else {
                $appCode = strtolower(trim((string)($resource['app_code'] ?? '')));
                $apiCode = strtolower(trim((string)($resource['api_code'] ?? '')));
                $key = $appCode === '' || $apiCode === ''
                    ? 'local:' . (string)($item['local_key'] ?? uniqid('', true))
                    : implode(':', [$type, $appCode, $apiCode]);
            }
            if (!isset($unique[$key]) || (!self::isExactPricingResourceRequest($unique[$key]) && self::isExactPricingResourceRequest($item))) {
                $unique[$key] = $item;
            }
        }
        return array_values($unique);
    }

    /**
     * The upstream model catalogue is an index, while pricing/batch is the
     * pricing authority. When an index temporarily has no rows for a model
     * type, continue reconciling existing products through pricing/batch so
     * valid SKUs and prices stay current and explicitly unavailable models are
     * retired together with their SKUs.
     *
     * @param array<int, array<string, mixed>> $requests
     * @param array<int, string> $modelTypes
     */
    private static function appendExistingModelsForEmptyCatalogTypes(array &$requests, array $modelTypes): void
    {
        $modelTypes = array_values(array_intersect(['text', 'image', 'video'], array_unique($modelTypes)));
        if ($modelTypes === []) {
            return;
        }

        $products = PowerMarketProduct::where('source_code', self::SOURCE_LIKEADMIN_API)
            ->where('resource_type', self::TYPE_MODEL)
            ->whereIn('model_type', $modelTypes)
            ->select()
            ->toArray();
        foreach ($products as $product) {
            $model = trim((string)($product['upstream_model_code'] ?? ''));
            if ($model === '') {
                continue;
            }
            $channel = trim((string)($product['upstream_channel_code'] ?? ''));
            $snapshot = self::arrayValue($product['source_payload'] ?? []);
            $metadata = self::arrayValue($snapshot['market_metadata'] ?? []);
            $modelType = self::normalizeModelType((string)($product['model_type'] ?? 'text'));
            $requests[] = [
                'local_key' => 'model:' . $model . ':' . $channel,
                'type' => self::TYPE_MODEL,
                'model' => $model,
                'channel' => $channel,
                'market_scope' => self::TYPE_MODEL . ':' . $modelType,
                'market_metadata' => $metadata,
            ];
        }
    }

    /** @param array<string, mixed> $item */
    private static function isExactPricingResourceRequest(array $item): bool
    {
        if (($item['type'] ?? '') !== self::TYPE_MODEL) {
            return true;
        }
        $request = (array)($item['request'] ?? []);
        $resource = (array)($item['resource'] ?? []);
        return strtolower(trim((string)($request['model'] ?? ''))) === strtolower(trim((string)($resource['model_code'] ?? '')))
            && strtolower(trim((string)($request['channel'] ?? ''))) === strtolower(trim((string)($resource['channel_code'] ?? '')));
    }

    /**
     * Restores distinct SKU rows from pricing snapshots created before sku_key was
     * correctly treated as a public business identifier.
     *
     * @return array{products: int, skus: int}
     */
    public static function repairMaskedSkusFromSnapshots(): array
    {
        $summary = ['products' => 0, 'skus' => 0];
        Db::transaction(function () use (&$summary): void {
            foreach (PowerMarketProduct::where('source_code', self::SOURCE_LIKEADMIN_API)->select() as $product) {
                $productId = (int)$product['id'];
                $snapshot = self::arrayValue($product['source_payload'] ?? []);
                $skuItems = array_values(array_filter((array)($snapshot['pricing_v2']['items'] ?? []), 'is_array'));
                if ($skuItems === [] || !PowerMarketSku::where(['product_id' => $productId, 'sku_key' => '******'])->count()) {
                    continue;
                }

                PowerMarketSku::where(['product_id' => $productId, 'sku_key' => '******'])->update([
                    'status' => 0,
                    'update_time' => time(),
                ]);
                foreach ($skuItems as $sku) {
                    // The old snapshot has only a masked key. The immutable pricing
                    // specification remains sufficient to generate a stable local key.
                    unset($sku['sku_key']);
                    self::upsertSku($productId, $sku, []);
                    $summary['skus']++;
                }
                $summary['products']++;
            }
        });
        return $summary;
    }

    public static function formatProduct(array $product): array
    {
        $product['type_text'] = self::productTypes()[$product['resource_type'] ?? ''] ?? '';
        $product['status_text'] = (int)($product['status'] ?? 0) === 1 ? '上架' : '下架';
        $capability = self::capability($product);
        $product['capability'] = $capability;
        $product['capability_tags'] = self::capabilityTags($capability);
        return $product;
    }

    /**
     * Normalizes provider-specific capability metadata into a stable market contract.
     * Raw provider metadata remains untouched in source_payload.market_metadata.
     *
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    public static function capability(array $product): array
    {
        $snapshot = self::arrayValue($product['source_payload'] ?? []);
        $metadata = self::arrayValue($snapshot['market_metadata'] ?? []);
        $sources = [
            $metadata,
            self::arrayValue($metadata['upstream_metadata'] ?? []),
            self::arrayValue($snapshot['resource'] ?? []),
            self::arrayValue($snapshot['raw'] ?? []),
        ];

        // SKU configuration is part of the provider specification. It often carries
        // media-reference limits even when the model catalogue does not.
        foreach ((array)($snapshot['pricing_v2']['items'] ?? []) as $sku) {
            if (!is_array($sku)) {
                continue;
            }
            $sources[] = $sku;
            $sources[] = self::arrayValue($sku['locked_params'] ?? []);
            $sources[] = self::arrayValue($sku['selectable_params'] ?? []);
        }

        $modalities = self::stringValues($sources, [
            'input_modalities', 'modalities', 'supported_input_modalities', 'supported_asset_types', 'inputs',
            'capabilities', 'features', 'supported_features',
        ]);
        $vision = self::boolValue($sources, [
            'supports_vision', 'vision', 'supports_image_input', 'image_input', 'multimodal',
        ]) || in_array('image', $modalities, true) || in_array('vision', $modalities, true);
        $thinking = self::boolValue($sources, [
            'supports_thinking', 'thinking', 'supports_reasoning', 'reasoning', 'deep_thinking',
        ]) || self::hasKey($sources, ['enable_thinking', 'thinking_enabled', 'reasoning_enabled'])
            || in_array('thinking', $modalities, true) || in_array('reasoning', $modalities, true);

        $maxImages = self::positiveInt($sources, [
            'max_reference_images', 'max_reference_image_count', 'reference_image_limit', 'max_image_references',
        ]);
        $maxVideos = self::positiveInt($sources, [
            'max_reference_videos', 'max_reference_video_count', 'reference_video_limit', 'max_video_references',
        ]);
        $maxAudios = self::positiveInt($sources, [
            'max_reference_audios', 'max_reference_audio_count', 'reference_audio_limit', 'max_audio_references',
        ]);
        $maxAssets = self::positiveInt($sources, ['max_reference_assets', 'max_reference_files', 'reference_asset_limit']);

        return [
            'supports_vision' => $vision,
            'supports_thinking' => $thinking,
            'max_reference_images' => $maxImages,
            'max_reference_videos' => $maxVideos,
            'max_reference_audios' => $maxAudios,
            'max_reference_assets' => $maxAssets,
            'input_modalities' => $modalities,
        ];
    }

    /**
     * @param array<string, mixed> $capability
     * @return array<int, array{key: string, label: string, value: int|string|bool}>
     */
    public static function capabilityTags(array $capability): array
    {
        $tags = [];
        if (!empty($capability['supports_vision'])) {
            $tags[] = ['key' => 'vision', 'label' => '支持视觉', 'value' => true];
        }
        if (!empty($capability['supports_thinking'])) {
            $tags[] = ['key' => 'thinking', 'label' => '支持深度思考', 'value' => true];
        }
        foreach ([
            'max_reference_images' => ['reference_images', '参考图', '张'],
            'max_reference_videos' => ['reference_videos', '参考视频', '个'],
            'max_reference_audios' => ['reference_audios', '参考音频', '个'],
            'max_reference_assets' => ['reference_assets', '参考素材', '个'],
        ] as $field => [$key, $label, $unit]) {
            $value = max(0, (int)($capability[$field] ?? 0));
            if ($value > 0) {
                $tags[] = ['key' => $key, 'label' => $label . ' ' . $value . ' ' . $unit, 'value' => $value];
            }
        }
        return $tags;
    }

    private static function upsertProduct(string $type, array $resource, array $item, bool $available = true): ?PowerMarketProduct
    {
        if (!isset(self::productTypes()[$type])) {
            return null;
        }
        $isModel = $type === self::TYPE_MODEL;
        $code = trim((string)($isModel ? ($resource['model_code'] ?? '') : ($resource['app_code'] ?? '')));
        $apiCode = $isModel ? '' : trim((string)($resource['api_code'] ?? ''));
        if ($code === '' || (!$isModel && $apiCode === '')) {
            return null;
        }
        $channelCode = $isModel ? trim((string)($resource['channel_code'] ?? '')) : '';
        $resourceKey = $isModel
            ? 'model:' . $code . ':' . $channelCode
            : 'app_api:' . $code . ':' . $apiCode;
        $metadata = (array)($item['market_metadata'] ?? []);
        $name = $isModel
            ? trim((string)($resource['model_name'] ?? $metadata['model_name'] ?? $code))
            : trim((string)($resource['app_name'] ?? $metadata['app_name'] ?? $code)) . ' / ' . trim((string)($resource['api_name'] ?? $apiCode));
        $row = PowerMarketProduct::where([
            'source_code' => self::SOURCE_LIKEADMIN_API,
            'upstream_resource_key' => $resourceKey,
        ])->findOrEmpty();
        $data = [
            'product_code' => $resourceKey,
            'resource_type' => $type,
            'name' => $name,
            'description' => trim((string)($resource['description'] ?? $metadata['model_description'] ?? $metadata['app_description'] ?? '')),
            'source_code' => self::SOURCE_LIKEADMIN_API,
            'upstream_resource_key' => $resourceKey,
            'upstream_app_code' => $isModel ? '' : $code,
            'upstream_api_code' => $apiCode,
            'upstream_model_code' => $isModel ? $code : '',
            'upstream_channel_code' => $channelCode,
            'model_type' => $isModel ? self::normalizeModelType((string)($metadata['model_type'] ?? 'text')) : '',
            'source_payload' => self::productSnapshot($item),
            'status' => $available ? 1 : 0,
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            return PowerMarketProduct::create($data);
        }
        $row->save($data);
        return $row;
    }

    private static function upsertSku(int $productId, array $sku, array $item): void
    {
        $upstreamKey = trim((string)($sku['sku_key'] ?? ''));
        $fallbackKey = self::fallbackSkuKey($sku);
        $key = $upstreamKey ?: $fallbackKey;
        $row = PowerMarketSku::where(['product_id' => $productId, 'sku_key' => $key])->findOrEmpty();
        if ($row->isEmpty() && $upstreamKey !== '') {
            $row = PowerMarketSku::where(['product_id' => $productId, 'sku_key' => $fallbackKey])->findOrEmpty();
        }
        if ($row->isEmpty()) {
            // Older syncs masked sku_key as a secret. Restore the one legacy row only
            // when its immutable billing specification proves it is the same SKU.
            foreach (PowerMarketSku::where(['product_id' => $productId, 'sku_key' => '******'])->select()->toArray() as $legacyRow) {
                if (self::fallbackSkuKey($legacyRow) === $fallbackKey) {
                    $row = PowerMarketSku::findOrEmpty((int)$legacyRow['id']);
                    break;
                }
            }
        }
        $price = (array)($sku['price'] ?? []);
        $data = [
            'title' => trim((string)($sku['title'] ?? $key)),
            'billing_mode' => trim((string)($sku['billing_mode'] ?? 'fixed')),
            'locked_params' => self::arrayValue($sku['locked_params'] ?? []),
            'selectable_params' => self::arrayValue($sku['selectable_params'] ?? []),
            'usage_unit' => trim((string)($sku['usage_unit'] ?? $price['unit'] ?? 'per_call')),
            'usage_unit_size' => max(1, (float)($sku['usage_unit_size'] ?? 1)),
            'upstream_price' => max(0, (float)($price['points'] ?? 0)),
            'price_hash' => sha1(json_encode($sku, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'source_payload' => self::skuSnapshot($sku),
            'status' => 1,
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $data['product_id'] = $productId;
            $data['sku_key'] = $key;
            $data['sale_points'] = $data['upstream_price'];
            $data['sale_status'] = 1;
            $data['sort'] = 0;
            $data['create_time'] = time();
            PowerMarketSku::create($data);
            return;
        }
        if ((string)$row['sku_key'] !== $key) {
            $data['sku_key'] = $key;
        }
        // Existing tenant prices are preserved, except when an upstream cost
        // increase would make the configured platform-to-tenant price invalid.
        if ((float)($row['sale_points'] ?? 0) < (float)$data['upstream_price']) {
            $data['sale_points'] = $data['upstream_price'];
        }
        $row->save($data);
    }

    private static function normalizeModelType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, ['text', 'image', 'video'], true) ? $type : 'text';
    }

    private static function withUpstreamRetry(callable $callback)
    {
        $lastException = null;
        for ($attempt = 1; $attempt <= self::UPSTREAM_RETRY_ATTEMPTS; $attempt++) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($attempt < self::UPSTREAM_RETRY_ATTEMPTS) {
                    usleep(200000);
                }
            }
        }
        throw $lastException;
    }

    private static function fallbackSkuKey(array $sku): string
    {
        $fingerprint = [
            'title' => (string)($sku['title'] ?? ''),
            'locked_params' => self::arrayValue($sku['locked_params'] ?? []),
            'selectable_params' => self::arrayValue($sku['selectable_params'] ?? []),
            'usage_unit' => (string)($sku['usage_unit'] ?? ''),
            'usage_unit_size' => (float)($sku['usage_unit_size'] ?? 1),
            'billing_mode' => (string)($sku['billing_mode'] ?? ''),
        ];
        return 'legacy_' . substr(sha1(json_encode($fingerprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 32);
    }

    private static function productSnapshot(array $item): array
    {
        return [
            'type' => (string)($item['type'] ?? ''),
            'resource' => (array)($item['resource'] ?? []),
            'pricing_v2' => (array)($item['pricing_v2'] ?? []),
            'price_view' => (array)($item['price_view'] ?? []),
            'pricing_source' => (array)($item['pricing_source'] ?? []),
            'market_metadata' => (array)($item['market_metadata'] ?? []),
            // UpstreamPricingService has already removed secret values. Keep the
            // remaining source fields for forward-compatible capability parsing.
            'raw' => (array)($item['raw'] ?? []),
            'synced_at' => date('c'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @return array<int, array<string, mixed>>
     */
    private static function groupApps(array $products): array
    {
        if ($products === []) {
            return [];
        }
        $productIds = array_values(array_filter(array_column($products, 'id')));
        $stats = [];
        if ($productIds !== []) {
            foreach (PowerMarketSku::whereIn('product_id', $productIds)->where('status', 1)->select()->toArray() as $sku) {
                if ((int)($sku['sale_status'] ?? 1) !== 1) {
                    continue;
                }
                $productId = (int)$sku['product_id'];
                $stats[$productId]['sku_count'] = (int)($stats[$productId]['sku_count'] ?? 0) + 1;
                $price = (float)($sku['upstream_price'] ?? 0);
                $stats[$productId]['min_price'] = isset($stats[$productId]['min_price'])
                    ? min($stats[$productId]['min_price'], $price)
                    : $price;
            }
        }
        $groups = [];
        foreach ($products as $product) {
            $appCode = trim((string)($product['upstream_app_code'] ?? ''));
            if ($appCode === '') {
                continue;
            }
            $snapshot = self::arrayValue($product['source_payload'] ?? []);
            $resource = (array)($snapshot['resource'] ?? []);
            $metadata = (array)($snapshot['market_metadata'] ?? []);
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
            $productId = (int)$product['id'];
            $group['api_count']++;
            $group['sku_count'] += (int)($stats[$productId]['sku_count'] ?? 0);
            if (isset($stats[$productId]['min_price'])) {
                $group['min_price'] = $group['min_price'] === null
                    ? $stats[$productId]['min_price']
                    : min($group['min_price'], $stats[$productId]['min_price']);
            }
            $productAvailable = (int)($product['status'] ?? 0) === 1 && (int)($stats[$productId]['sku_count'] ?? 0) > 0;
            $group['status'] = max($group['status'], $productAvailable ? 1 : 0);
            $group['status_text'] = $group['status'] === 1 ? '上架' : '下架';
            $group['update_time'] = max($group['update_time'], (int)($product['update_time'] ?? 0));
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
        usort($groups, static fn (array $left, array $right): int => [$right['status'], $right['update_time'], $left['app_code']] <=> [$left['status'], $left['update_time'], $right['app_code']]);
        return $groups;
    }

    private static function skuSnapshot(array $sku): array
    {
        return [
            'sku_key' => (string)($sku['sku_key'] ?? ''),
            'title' => (string)($sku['title'] ?? ''),
            'billing_mode' => (string)($sku['billing_mode'] ?? ''),
            'locked_params' => self::arrayValue($sku['locked_params'] ?? []),
            'selectable_params' => self::arrayValue($sku['selectable_params'] ?? []),
            'usage_unit' => (string)($sku['usage_unit'] ?? ''),
            'usage_unit_size' => (float)($sku['usage_unit_size'] ?? 1),
            'price' => (array)($sku['price'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $sku
     * @return array<string, mixed>
     */
    private static function formatSku(array $sku): array
    {
        $sku['locked_params'] = self::arrayValue($sku['locked_params'] ?? []);
        $sku['selectable_params'] = self::arrayValue($sku['selectable_params'] ?? []);
        $sku['upstream_price'] = round(max(0, (float)($sku['upstream_price'] ?? 0)), 6);
        $sku['sale_points'] = round(max(0, (float)($sku['sale_points'] ?? $sku['upstream_price'])), 6);
        $sku['sale_status'] = (int)($sku['sale_status'] ?? 1) === 1 ? 1 : 0;
        $sku['gross_margin_points'] = round($sku['sale_points'] - $sku['upstream_price'], 6);
        return $sku;
    }

    private static function arrayValue($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, string> $keys
     */
    private static function boolValue(array $sources, array $keys): bool
    {
        foreach ($sources as $source) {
            foreach (self::valuesByKeys($source, $keys) as $value) {
                if (is_bool($value)) {
                    if ($value) {
                        return true;
                    }
                    continue;
                }
                if (is_numeric($value)) {
                    if ((float)$value > 0) {
                        return true;
                    }
                    continue;
                }
                if (is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'supported', 'enable', 'enabled'], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * A parameter existing in a schema/defaults means the provider accepts it,
     * including enable_thinking=false as the provider's default.
     *
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, string> $keys
     */
    private static function hasKey(array $sources, array $keys): bool
    {
        foreach ($sources as $source) {
            if (self::valuesByKeys($source, $keys) !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, string> $keys
     */
    private static function positiveInt(array $sources, array $keys): int
    {
        $max = 0;
        foreach ($sources as $source) {
            foreach (self::valuesByKeys($source, $keys) as $value) {
                if (is_numeric($value)) {
                    $max = max($max, (int)$value);
                }
            }
        }
        return $max;
    }

    /**
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, string> $keys
     * @return array<int, string>
     */
    private static function stringValues(array $sources, array $keys): array
    {
        $values = [];
        foreach ($sources as $source) {
            foreach (self::valuesByKeys($source, $keys) as $value) {
                self::appendStringValues($values, $value);
            }
        }
        return array_values($values);
    }

    /** @param array<string, string> $values */
    private static function appendStringValues(array &$values, $value): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                // Some providers use {"image": true}, while others use
                // ["image"]. Keep both forms equivalent.
                if (!is_int($key) && (is_bool($item) || is_numeric($item)) && (bool)$item) {
                    self::appendStringValues($values, (string)$key);
                }
                self::appendStringValues($values, $item);
            }
            return;
        }
        if (!is_scalar($value)) {
            return;
        }
        foreach (preg_split('/[,|\\s]+/', (string)$value, -1, PREG_SPLIT_NO_EMPTY) as $item) {
            $item = strtolower(trim($item));
            if ($item !== '') {
                $values[$item] = $item;
            }
        }
    }

    /**
     * Recursively finds values by normalized field name. Provider formats vary
     * between snake_case, kebab-case and camelCase.
     *
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     * @return array<int, mixed>
     */
    private static function valuesByKeys(array $source, array $keys): array
    {
        $wanted = [];
        foreach ($keys as $key) {
            $wanted[self::normalizeCapabilityKey($key)] = true;
        }
        $values = [];
        $walk = static function ($value, $key = null) use (&$walk, $wanted, &$values): void {
            if ($key !== null && isset($wanted[PowerMarketService::normalizeCapabilityKey((string)$key)])) {
                $values[] = $value;
            }
            if (!is_array($value)) {
                return;
            }
            foreach ($value as $childKey => $childValue) {
                $walk($childValue, $childKey);
            }
        };
        $walk($source);
        return $values;
    }

    private static function normalizeCapabilityKey(string $key): string
    {
        return (string)preg_replace('/[^a-z0-9]+/', '', strtolower($key));
    }
}
