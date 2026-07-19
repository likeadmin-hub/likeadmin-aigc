<?php

namespace app\tenantapi\controller\power;

use app\common\service\power\TenantPowerMarketService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class MarketController extends BaseAdminController
{
    public function models()
    {
        return $this->success('获取成功', TenantPowerMarketService::models(
            (int)$this->adminInfo['tenant_id'],
            (string)$this->request->get('keyword', ''),
            $this->request->get('status', ''),
            (int)$this->request->get('page_no', 1),
            (int)$this->request->get('page_size', 15),
            (string)$this->request->get('model_type', '')
        ));
    }

    public function apps()
    {
        return $this->success('获取成功', TenantPowerMarketService::apps(
            (int)$this->adminInfo['tenant_id'],
            (string)$this->request->get('keyword', ''),
            $this->request->get('status', ''),
            (int)$this->request->get('page_no', 1),
            (int)$this->request->get('page_size', 15)
        ));
    }

    public function detail()
    {
        return $this->success('获取成功', TenantPowerMarketService::detail(
            (int)$this->adminInfo['tenant_id'],
            (int)$this->request->get('id', 0)
        ));
    }

    public function appDetail()
    {
        return $this->success('获取成功', TenantPowerMarketService::appDetail(
            (int)$this->adminInfo['tenant_id'],
            (string)$this->request->get('app_code', '')
        ));
    }

    public function savePrices()
    {
        try {
            TenantPowerMarketService::savePrices(
                (int)$this->adminInfo['tenant_id'],
                (int)$this->request->post('product_id', 0),
                (array)$this->request->post('prices', [])
            );
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function saveDisplay()
    {
        try {
            TenantPowerMarketService::saveProductDisplay(
                (int)$this->adminInfo['tenant_id'],
                (int)$this->request->post('product_id', 0),
                $this->request->post()
            );
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function batchShelf()
    {
        try {
            $result = TenantPowerMarketService::batchShelf(
                (int)$this->adminInfo['tenant_id'],
                (array)$this->request->post('product_ids', []),
                (int)$this->request->post('sale_status', 0),
                (bool)$this->request->post('all', false),
                (string)$this->request->post('keyword', ''),
                (string)$this->request->post('model_type', ''),
                $this->request->post('status', '')
            );
            return $this->success('设置成功', $result, 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
