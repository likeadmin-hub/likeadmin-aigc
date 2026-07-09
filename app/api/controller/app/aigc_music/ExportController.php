<?php

namespace app\api\controller\app\aigc_music;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_music\AigcMusicService;

class ExportController extends BaseApiController
{
    public function wav()
    {
        return $this->handle('wav');
    }

    public function mp4()
    {
        return $this->handle('mp4');
    }

    public function midi()
    {
        return $this->handle('midi');
    }

    public function timing()
    {
        return $this->handle('timing');
    }

    public function vox()
    {
        return $this->handle('vox');
    }

    private function handle(string $type)
    {
        try {
            $resultId = (int)$this->request->post('result_id', $this->request->post('id', 0));
            return $this->success('导出成功', AigcMusicService::exportResult((int)$this->request->tenantId, $this->userId, $resultId, $type, $this->request->post()), 1, 1);
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
