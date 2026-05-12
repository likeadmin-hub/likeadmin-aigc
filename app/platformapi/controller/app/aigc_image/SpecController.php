<?php

namespace app\platformapi\controller\app\aigc_image;

use app\common\service\app\aigc_image\AigcImageChannelService;
use app\platformapi\controller\BaseAdminController;

class SpecController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcImageChannelService::platformLists());
    }

    public function save()
    {
        try {
            AigcImageChannelService::savePlatformSpec($this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcImageChannelService::deletePlatform($this->request->post() + ['type' => 'spec']);
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcImageChannelService::statusPlatform($this->request->post() + ['type' => 'spec']);
            return $this->success('设置成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
