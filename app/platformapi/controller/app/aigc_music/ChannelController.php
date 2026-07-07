<?php

namespace app\platformapi\controller\app\aigc_music;

use app\common\service\app\aigc_music\AigcMusicChannelService;
use app\platformapi\controller\BaseAdminController;

class ChannelController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcMusicChannelService::platformLists());
    }

    public function save()
    {
        try {
            AigcMusicChannelService::savePlatform($this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function delete()
    {
        try {
            AigcMusicChannelService::deletePlatform($this->request->post());
            return $this->success('删除成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function status()
    {
        try {
            AigcMusicChannelService::statusPlatform($this->request->post());
            return $this->success('设置成功', [], 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
