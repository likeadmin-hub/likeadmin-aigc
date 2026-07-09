<?php

namespace app\tenantapi\controller\app\aigc_music;

use app\common\service\app\aigc_music\AigcMusicChannelService;
use app\tenantapi\controller\BaseAdminController;

class ChannelController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcMusicChannelService::tenantLists($this->tenantId));
    }

    public function save()
    {
        try {
            AigcMusicChannelService::saveTenant($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function batchSave()
    {
        try {
            AigcMusicChannelService::batchSaveTenantSpecs($this->tenantId, $this->request->post());
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
                AigcMusicChannelService::saveTenantSpec($this->tenantId, $params);
            } else {
                AigcMusicChannelService::saveTenant($this->tenantId, $params);
            }
            return $this->success('设置成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
