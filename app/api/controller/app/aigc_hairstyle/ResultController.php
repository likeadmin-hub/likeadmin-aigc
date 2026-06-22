<?php

namespace app\api\controller\app\aigc_hairstyle;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_hairstyle\AigcHairstyleService;

class ResultController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcHairstyleService::resultLists(
            (int)$this->request->tenantId,
            $this->userId,
            (string)$this->request->get('status', '')
        ));
    }
}
