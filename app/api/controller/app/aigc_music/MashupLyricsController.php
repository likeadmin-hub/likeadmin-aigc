<?php

namespace app\api\controller\app\aigc_music;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_music\AigcMusicService;

class MashupLyricsController extends BaseApiController
{
    public function index()
    {
        try {
            return $this->success('生成成功', AigcMusicService::mashupLyrics((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
