<?php

namespace app\platformapi\controller\app\aigc_music;

use app\common\service\app\aigc_music\AigcMusicService;
use app\platformapi\controller\BaseAdminController;

class TaskController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcMusicService::platformTaskLists($this->request->get()));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcMusicService::platformTaskDetail((int)$this->request->get('id', 0)));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
