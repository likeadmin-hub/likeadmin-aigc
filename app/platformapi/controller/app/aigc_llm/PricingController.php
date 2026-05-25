<?php

namespace app\platformapi\controller\app\aigc_llm;

use app\common\model\app\aigc_llm\AigcLlmModel;
use app\common\service\app\UpstreamPricingService;
use app\platformapi\controller\BaseAdminController;

class PricingController extends BaseAdminController
{
    public function model()
    {
        try {
            $id = (int)$this->request->get('id', $this->request->post('id', 0));
            $row = $id > 0 ? AigcLlmModel::where(['tenant_id' => 0, 'id' => $id])->findOrEmpty() : null;
            if (!$row || $row->isEmpty()) {
                return $this->fail('模型不存在');
            }
            return $this->success('获取成功', UpstreamPricingService::queryModel((string)$row['model'], (string)$row['channel_code']));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
