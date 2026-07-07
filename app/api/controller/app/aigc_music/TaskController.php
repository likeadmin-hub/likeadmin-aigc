<?php

namespace app\api\controller\app\aigc_music;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_music\AigcMusicService;

class TaskController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcMusicService::taskLists((int)$this->request->tenantId, $this->userId, $this->request->get()));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcMusicService::taskDetail((int)$this->request->tenantId, (int)$this->request->get('id', 0), $this->userId));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function query()
    {
        return $this->detail();
    }
}
