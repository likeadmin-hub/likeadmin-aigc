<?php

namespace app\tenantapi\controller\app\aigc_video;

use app\common\service\app\aigc_video\AigcVideoChannelService;
use app\tenantapi\controller\BaseAdminController;

class ChannelController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcVideoChannelService::tenantLists($this->tenantId));
    }

    public function save()
    {
        try {
            AigcVideoChannelService::saveTenant($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            $params = $this->request->post();
            if (($params['type'] ?? '') === 'spec') {
                AigcVideoChannelService::saveTenantSpec($this->tenantId, $params);
            } else {
                AigcVideoChannelService::saveTenant($this->tenantId, $params);
            }
            return $this->success('设置成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

