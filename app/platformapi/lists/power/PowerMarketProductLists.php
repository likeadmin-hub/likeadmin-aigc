<?php

namespace app\platformapi\lists\power;

use app\common\lists\ListsSearchInterface;
use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\service\power\PowerMarketService;
use app\platformapi\lists\BaseAdminDataLists;

class PowerMarketProductLists extends BaseAdminDataLists implements ListsSearchInterface
{
    public function setSearch(): array
    {
        return [
            '%like%' => ['name', 'product_code', 'upstream_model_code', 'upstream_app_code', 'upstream_api_code'],
            '=' => ['resource_type', 'status', 'model_type'],
        ];
    }

    public function lists(): array
    {
        $query = PowerMarketProduct::where($this->searchWhere);
        $keyword = trim((string)$this->request->get('keyword', ''));
        if ($keyword !== '') {
            $query->whereLike('name|product_code|upstream_model_code|upstream_channel_code|upstream_app_code|upstream_api_code', '%' . $keyword . '%');
        }
        $rows = $query
            ->order(['status' => 'desc', 'update_time' => 'desc', 'id' => 'desc'])
            ->limit($this->limitOffset, $this->limitLength)
            ->select()
            ->toArray();
        $ids = array_values(array_filter(array_column($rows, 'id')));
        $stats = [];
        if ($ids !== []) {
            foreach (PowerMarketSku::whereIn('product_id', $ids)->where('status', 1)->select()->toArray() as $sku) {
                if ((int)($sku['sale_status'] ?? 1) !== 1) {
                    continue;
                }
                $id = (int)$sku['product_id'];
                $price = (float)($sku['upstream_price'] ?? 0);
                $stats[$id]['sku_count'] = (int)($stats[$id]['sku_count'] ?? 0) + 1;
                $stats[$id]['min_price'] = isset($stats[$id]['min_price']) ? min($stats[$id]['min_price'], $price) : $price;
            }
        }
        foreach ($rows as &$row) {
            $id = (int)$row['id'];
            $row = PowerMarketService::formatProduct($row);
            $row['sku_count'] = (int)($stats[$id]['sku_count'] ?? 0);
            $row['min_price'] = (float)($stats[$id]['min_price'] ?? 0);
            $row['status'] = (int)($row['status'] ?? 0) === 1 && $row['sku_count'] > 0 ? 1 : 0;
            $row['status_text'] = $row['status'] === 1 ? '上架' : '下架';
        }
        unset($row);
        return $rows;
    }

    public function count(): int
    {
        $query = PowerMarketProduct::where($this->searchWhere);
        $keyword = trim((string)$this->request->get('keyword', ''));
        if ($keyword !== '') {
            $query->whereLike('name|product_code|upstream_model_code|upstream_channel_code|upstream_app_code|upstream_api_code', '%' . $keyword . '%');
        }
        return $query->count();
    }
}
