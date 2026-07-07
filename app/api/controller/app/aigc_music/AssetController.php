<?php

namespace app\api\controller\app\aigc_music;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_music\AigcMusicAssetService;

class AssetController extends BaseApiController
{
    public function uploadAudio()
    {
        try {
            return $this->success('上传成功', AigcMusicAssetService::uploadAudio((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function upload_audio()
    {
        return $this->uploadAudio();
    }
}
