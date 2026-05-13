<?php

namespace app\tenantapi\controller\app\aigc_llm;

use app\common\service\app\aigc_llm\AigcLlmChannelService;
use app\tenantapi\controller\BaseAdminController;

class ModelController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcLlmChannelService::tenantModelLists($this->tenantId));
    }

    public function save()
    {
        try {
            AigcLlmChannelService::saveTenantModel($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcLlmChannelService::statusTenantModel($this->tenantId, $this->request->post());
            return $this->success('设置成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
