<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use Exception;

class PublishController extends BaseApiController
{
    public function submit()
    {
        try {
            return $this->success('success', AigcShortDramaService::submitPublishedWork((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        try {
            return $this->success('success', AigcShortDramaService::publishedWorkDetail((int)$this->request->tenantId, $this->userId, (int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
