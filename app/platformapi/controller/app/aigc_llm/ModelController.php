<?php

namespace app\platformapi\controller\app\aigc_llm;

use app\common\service\app\aigc_llm\AigcLlmChannelService;
use app\platformapi\controller\BaseAdminController;

class ModelController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcLlmChannelService::platformModelLists());
    }

    public function save()
    {
        try {
            AigcLlmChannelService::savePlatformModel($this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcLlmChannelService::deletePlatformModel($this->request->post());
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcLlmChannelService::statusPlatformModel($this->request->post());
            return $this->success('设置成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
