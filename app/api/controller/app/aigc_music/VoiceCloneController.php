<?php

namespace app\api\controller\app\aigc_music;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_music\AigcMusicService;

class VoiceCloneController extends BaseApiController
{
    public function create()
    {
        try {
            return $this->success('创建成功', AigcMusicService::createVoiceClone((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
