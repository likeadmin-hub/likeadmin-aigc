<?php

namespace app\platformapi\lists\power;

use app\common\lists\ListsSearchInterface;
use app\common\lists\ListsExtendInterface;
use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\service\power\PowerMarketService;
use app\common\service\power\MarketModelSource;
use app\common\service\power\MarketModelFilter;
use app\common\service\power\MarketFileQaTokenPricing;
use app\common\service\power\MarketOtherModelCategory;
use app\common\service\power\MarketPriceRuleService;
use app\platformapi\lists\BaseAdminDataLists;

class PowerMarketProductLists extends BaseAdminDataLists implements ListsSearchInterface, ListsExtendInterface
{
    public function setSearch(): array
    {
        return [
            '%like%' => ['name', 'product_code', 'upstream_model_code', 'upstream_app_code', 'upstream_api_code'],
            '=' => ['resource_type', 'model_type'],
        ];
    }

    public function lists(): array
    {
        $query = $this->filteredQuery();
        $rows = $query
            ->orderRaw(MarketModelSource::officialFirstOrder())
            ->order(['status' => 'desc', 'update_time' => 'desc', 'id' => 'desc'])
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        $ids = array_values(array_filter(array_column($rows, 'id')));
        $textIds = array_fill_keys(array_column(array_filter($rows, static fn($row) => $row['model_type'] === 'text'), 'id'), true);
        $platformRules = null;
        $stats = [];
        if ($ids !== []) {
            foreach (PowerMarketSku::whereIn('product_id', $ids)->where('status', 1)->select()->toArray() as $sku) {
                $id = (int)$sku['product_id'];
                if (isset($sku['source_payload']['image_item_billing'])) $stats[$id]['has_image_billing_items'] = true;
                if ((int)($sku['sale_status'] ?? 1) !== 1) {
                    continue;
                }
                if (isset($sku['source_payload']['effective_rate_config'])) $stats[$id]['has_token_rates'] = true;
                if (($sku['source_payload']['image_item_billing']['mode'] ?? '') === 'token_items') $stats[$id]['has_token_rates'] = true;
                $input = $sku['source_payload']['image_item_billing']['items']['input_images']['rate'] ?? null;
                if ($input !== null) $stats[$id]['min_input_points'] = min($stats[$id]['min_input_points'] ?? (float)$input, (float)$input);
                $price = (float)($sku['upstream_price'] ?? 0);
                $stats[$id]['sku_count'] = (int)($stats[$id]['sku_count'] ?? 0) + 1;
                if (!isset($stats[$id]['min_price']) || $price < $stats[$id]['min_price']) {
                    $stats[$id]['min_price'] = $price;
                    $stats[$id]['text_token_rates'] = isset($textIds[$id], $sku['source_payload']['effective_rate_config'])
                        ? MarketFileQaTokenPricing::display($sku, 0, $platformRules ??= MarketPriceRuleService::rules(0))
                        : null;
                }
            }
        }
        foreach ($rows as &$row) {
            $id = (int)$row['id'];
            $row = PowerMarketService::formatProduct($row);
            $row['sku_count'] = (int)($stats[$id]['sku_count'] ?? 0);
            $row['min_price'] = (float)($stats[$id]['min_price'] ?? 0);
            $row['min_input_points'] = $stats[$id]['min_input_points'] ?? null;
            $row['has_image_billing_items'] = !empty($stats[$id]['has_image_billing_items']);
            $row['has_token_rates'] = !empty($stats[$id]['has_token_rates']);
            $row['text_token_rates'] = $stats[$id]['text_token_rates'] ?? null;
            $row['status'] = (int)($row['status'] ?? 0) === 1 && $row['sku_count'] > 0 ? 1 : 0;
            $row['status_text'] = $row['status'] === 1 ? '上架' : '下架';
        }
        unset($row);
        return $rows;
    }

    public function count(): int
    {
        return $this->filteredQuery()->count();
    }

    private ?array $matchingIds = null;

    private function filteredQuery()
    {
        $query = PowerMarketProduct::where($this->searchWhere);
        $params = $this->request->get();
        self::applyCatalogFilters($query, $params);
        if (MarketModelFilter::active($params)) {
            $this->matchingIds ??= self::matchingProductIds($query, $params);
            $query->whereIn('id', $this->matchingIds);
        }
        return $query;
    }

    private static function matchingProductIds($query, array $params): array
    {
        if (!MarketModelFilter::active($params)) return $query->column('id');
        $rows = (clone $query)->field('id,resource_type,is_recommended,upstream_channel_code,source_payload')->select()->toArray();
        return array_column(MarketModelFilter::products($rows, $params), 'id');
    }

    public static function shelfProductIds(array $params): array
    {
        $catalog = $params['catalog_type'] ?? '';
        if (!in_array($catalog, ['model', 'series', 'other'], true)) throw new \InvalidArgumentException('请选择有效的模型分类');
        $params['resource_type'] = PowerMarketService::TYPE_MODEL;
        $query = PowerMarketProduct::where('resource_type', PowerMarketService::TYPE_MODEL);
        if (($params['model_type'] ?? '') !== '') $query->where('model_type', $params['model_type']);
        self::applyCatalogFilters($query, $params);
        return self::matchingProductIds($query, $params);
    }

    private static function applyCatalogFilters($query, array $params): void
    {
        MarketModelFilter::applyApiStatus($query, 0, $params['api_status'] ?? '');
        $keyword = trim((string)($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->whereLike('name|product_code|upstream_model_code|upstream_channel_code|upstream_app_code|upstream_api_code', '%' . $keyword . '%');
        }
        $catalog = (string)($params['catalog_type'] ?? '');
        if ($catalog === 'other') {
            $query->where('resource_type', PowerMarketService::TYPE_MODEL)->where('model_type', 'other');
            $category = trim((string)($params['other_category_code'] ?? ''));
            if ($category !== '') {
                $query->whereRaw("COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(source_payload, '$.market_metadata.other_category.code')), ''), 'uncategorized') = ?", [$category]);
            }
        }
        if (in_array($catalog, ['model','series'], true)) {
            $query->whereIn('model_type', ['text', 'image', 'video']);
            $factory = "(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(source_payload, '$.market_metadata.upstream_metadata.pricing.pricing_v2.resource_type')), '') = 'factory_model')";
            // Agent uses factory text models too. Keep them discoverable in the ordinary model catalogue.
            $query->whereRaw($catalog === 'series' ? $factory : "(model_type = 'text' OR NOT $factory)");
        }
        $resourceType = (string)($params['resource_type'] ?? '');
        $status = $params['status'] ?? '';
        if ($resourceType !== PowerMarketService::TYPE_MODEL) {
            if ($status !== '' && $status !== null) {
                $query->where('status', (int)$status === 1 ? 1 : 0);
            }
            return;
        }

        // Upstream availability and marketplace shelf state are separate:
        // status keeps retired models out, while sale_status is what platform
        // operators control through the on/off switch.
        $query->where('status', 1);
        if ($status === '' || $status === null) {
            return;
        }
        $enabledProductIds = array_values(array_unique(array_map(
            'intval',
            PowerMarketSku::where(['status' => 1, 'sale_status' => 1])->column('product_id')
        )));
        if ((int)$status === 1) {
            if ($enabledProductIds === []) {
                $query->whereRaw('1 = 0');
                return;
            }
            $query->whereIn('id', $enabledProductIds);
            return;
        }
        if ($enabledProductIds !== []) {
            $query->whereNotIn('id', $enabledProductIds);
        }
    }

    public function extend(): array
    {
        if ($this->request->get('catalog_type', '') !== 'other') return [];
        $products = PowerMarketProduct::where(['resource_type' => PowerMarketService::TYPE_MODEL, 'model_type' => 'other', 'status' => 1])
            ->field('source_payload')->select()->toArray();
        return ['other_categories' => MarketOtherModelCategory::options($products)];
    }
}
