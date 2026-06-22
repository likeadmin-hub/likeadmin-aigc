<?php

namespace app\api\controller\app\aigc_hairstyle;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_hairstyle\AigcHairstyleService;
use Exception;

class TaskController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcHairstyleService::taskLists(
            (int)$this->request->tenantId,
            $this->userId,
            $this->request->get()
        ));
    }

    public function detail()
    {
        try {
            $id = (int)$this->request->get('id', 0);
            return $this->success('获取成功', AigcHairstyleService::taskDetail((int)$this->request->tenantId, $id, $this->userId));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
