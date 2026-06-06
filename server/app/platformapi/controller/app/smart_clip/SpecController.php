<?php

namespace app\platformapi\controller\app\smart_clip;

use app\common\service\app\smart_clip\SmartClipChannelService;
use app\platformapi\controller\BaseAdminController;

class SpecController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', SmartClipChannelService::platformLists());
    }

    public function save()
    {
        try {
            SmartClipChannelService::savePlatformSpec($this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function batchSave()
    {
        try {
            SmartClipChannelService::batchSavePlatformSpecs($this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            SmartClipChannelService::deletePlatform($this->request->post() + ['type' => 'spec']);
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            SmartClipChannelService::statusPlatform($this->request->post() + ['type' => 'spec']);
            return $this->success('设置成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
