<?php

namespace app\platformapi\controller\app\aigc_video;

use app\common\service\app\aigc_video\AigcVideoChannelService;
use app\platformapi\controller\BaseAdminController;

class SpecController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcVideoChannelService::platformLists());
    }

    public function save()
    {
        try {
            AigcVideoChannelService::savePlatformSpec($this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcVideoChannelService::deletePlatform($this->request->post() + ['type' => 'spec']);
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcVideoChannelService::statusPlatform($this->request->post() + ['type' => 'spec']);
            return $this->success('设置成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
