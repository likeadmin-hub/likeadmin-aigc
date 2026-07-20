<?php

namespace app\api\controller\app\aigc_video;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_video\AigcVideoService;

class TaskController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcVideoService::taskLists((int)$this->request->tenantId, $this->userId));
    }

    public function detail()
    {
        try {
            $id = (int)$this->request->get('id', 0);
            return $this->success('获取成功', AigcVideoService::taskDetail((int)$this->request->tenantId, $id, $this->userId));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function refresh()
    {
        try {
            $id = (int)$this->request->post('id', 0);
            return $this->success('刷新成功', AigcVideoService::refreshMarketTask((int)$this->request->tenantId, $id, $this->userId));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
