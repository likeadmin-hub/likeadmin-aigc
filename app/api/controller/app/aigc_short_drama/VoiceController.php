<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use Exception;

class VoiceController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        try {
            return $this->success('success', AigcShortDramaService::voiceLists(
                (int)$this->request->tenantId,
                (int)$this->userId,
                (string)$this->request->get('source', '')
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
