<?php

namespace app\platformapi\controller\power;

use app\common\service\app\UpstreamPricingService;
use app\common\service\power\MarketModelFilter;
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

    public function accountBalance()
    {
        if (!$this->request->isGet()) {
            return $this->fail('请使用 GET 请求查询账户余额');
        }
        return $this->success('', UpstreamPricingService::queryAccountBalance())
            ->header(['Cache-Control' => 'no-store']);
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
            (int)$this->request->get('page_size', 15),
            (string)$this->request->get('category_code', ''),
            $this->request->get('api_status', '')
        ));
    }

    public function appDetail()
    {
        return $this->success('获取成功', PowerMarketService::appDetail((string)$this->request->get('app_code', '')));
    }

    public function pricingRules()
    {
        return $this->success('获取成功', \app\common\service\power\MarketPriceRuleService::settings(0));
    }

    public function previewPricing()
    {
        try {
            return $this->success('预览成功', \app\common\service\power\MarketPriceRuleService::preview(0, $this->adminId, $this->request->post()));
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }

    public function applyPricing()
    {
        try {
            return $this->success('价格已更新', \app\common\service\power\MarketPriceRuleService::apply(0, $this->adminId, (string)$this->request->post('token', '')), 1, 1);
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }

    public function savePrices()
    {
        try {
            PowerMarketService::savePrices(
                (int)$this->request->post('product_id', 0),
                (array)$this->request->post('prices', []),
                \app\common\service\power\MarketModelCapabilities::fromRequest($this->request),
                $this->adminId
            );
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function setRecommendation()
    {
        $value = $this->request->post('recommended');
        if (!$this->request->isPost() || !in_array($value, [0, 1, '0', '1'], true)) {
            return $this->fail('推荐状态无效');
        }
        $product = \app\common\model\power\PowerMarketProduct::where([
            'id' => (int)$this->request->post('product_id', 0),
            'resource_type' => PowerMarketService::TYPE_MODEL,
        ])->findOrEmpty();
        if ($product->isEmpty()) return $this->fail('模型不存在');
        $product->save(['is_recommended' => (int)$value]);
        return $this->success('推荐状态已保存');
    }

    public function batchShelf()
    {
        try {
            $updated = PowerMarketService::batchShelf(
                MarketModelFilter::isBulkRequest($this->request->post())
                    ? PowerMarketProductLists::shelfProductIds($this->request->post())
                    : (array)$this->request->post('product_ids', []),
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
            $scope = (string)$this->request->post('catalog_type', '');
            if ($scope !== '' && !in_array($scope, ['text', 'image', 'video', 'other', 'app_api'], true)) throw new Exception('同步分类无效');
            $modelsOnly = $scope !== '' && $scope !== 'app_api';
            return $this->success('同步成功', PowerMarketService::syncFromUpstream($modelsOnly, $modelsOnly ? [$scope] : null, $scope === 'app_api', $scope !== ''), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
