<?php

namespace app\platformapi\controller\power;

use app\common\service\power\PowerMarketService;
use app\platformapi\controller\BaseAdminController;
use app\platformapi\lists\power\PowerMarketProductLists;
use Exception;

class MarketController extends BaseAdminController
{
    public function lists()
    {
        return $this->dataLists(new PowerMarketProductLists());
    }

    public function detail()
    {
        return $this->success('获取成功', PowerMarketService::detail((int)$this->request->get('id', 0)));
    }

    public function apps()
    {
        return $this->success('获取成功', PowerMarketService::apps(
            (string)$this->request->get('keyword', ''),
            $this->request->get('status', ''),
            (int)$this->request->get('page_no', 1),
            (int)$this->request->get('page_size', 15)
        ));
    }

    public function appDetail()
    {
        return $this->success('获取成功', PowerMarketService::appDetail((string)$this->request->get('app_code', '')));
    }

    public function savePrices()
    {
        try {
            PowerMarketService::savePrices(
                (int)$this->request->post('product_id', 0),
                (array)$this->request->post('prices', [])
            );
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function batchShelf()
    {
        try {
            $updated = PowerMarketService::batchShelf(
                (array)$this->request->post('product_ids', []),
                (int)$this->request->post('sale_status', 0)
            );
            return $this->success('设置成功', ['updated' => $updated], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function types()
    {
        return $this->success('获取成功', PowerMarketService::productTypes());
    }

    public function sync()
    {
        try {
            return $this->success('同步成功', PowerMarketService::syncFromUpstream(), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
