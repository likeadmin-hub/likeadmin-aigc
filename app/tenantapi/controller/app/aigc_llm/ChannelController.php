<?php

namespace app\tenantapi\controller\app\aigc_llm;

use app\common\service\app\aigc_llm\AigcLlmChannelService;
use app\tenantapi\controller\BaseAdminController;

class ChannelController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcLlmChannelService::tenantChannelLists($this->tenantId));
    }

    public function save()
    {
        try {
            AigcLlmChannelService::saveTenantChannel($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcLlmChannelService::statusTenantChannel($this->tenantId, $this->request->post());
            return $this->success('设置成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
