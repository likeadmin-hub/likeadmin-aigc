<?php

namespace app\api\controller\app\aigc_geo;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_geo\AigcGeoService;
use Exception;

class DiagnosisController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcGeoService::diagnosisLists((int)$this->request->tenantId, $this->request->get(), $this->userId));
    }

    public function run()
    {
        try {
            return $this->success('检测任务已创建', AigcGeoService::runDiagnosis((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
