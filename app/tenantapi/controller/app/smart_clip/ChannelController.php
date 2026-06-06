<?php

namespace app\tenantapi\controller\app\smart_clip;

use app\common\service\app\smart_clip\SmartClipChannelService;
use app\tenantapi\controller\BaseAdminController;

class ChannelController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', SmartClipChannelService::tenantLists($this->tenantId));
    }

    public function save()
    {
        try {
            SmartClipChannelService::saveTenant($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function batchSave()
    {
        try {
            SmartClipChannelService::batchSaveTenantSpecs($this->tenantId, $this->request->post());
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
                SmartClipChannelService::saveTenantSpec($this->tenantId, $params);
            } else {
                SmartClipChannelService::saveTenant($this->tenantId, $params);
            }
            return $this->success('设置成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
