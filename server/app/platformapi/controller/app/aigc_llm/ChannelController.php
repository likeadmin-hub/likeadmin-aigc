<?php

namespace app\platformapi\controller\app\aigc_llm;

use app\common\service\app\aigc_llm\AigcLlmChannelService;
use app\platformapi\controller\BaseAdminController;

class ChannelController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcLlmChannelService::platformChannelLists());
    }

    public function save()
    {
        try {
            AigcLlmChannelService::savePlatformChannel($this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcLlmChannelService::deletePlatformChannel($this->request->post());
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcLlmChannelService::statusPlatformChannel($this->request->post());
            return $this->success('设置成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
