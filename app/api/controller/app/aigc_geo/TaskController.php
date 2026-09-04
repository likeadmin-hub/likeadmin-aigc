<?php

namespace app\api\controller\app\aigc_geo;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_geo\AigcGeoService;
use Exception;

class TaskController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcGeoService::taskLists((int)$this->request->tenantId, $this->request->get(), $this->userId));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcGeoService::taskDetail((int)$this->request->tenantId, (int)$this->request->get('id', 0), $this->userId));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
