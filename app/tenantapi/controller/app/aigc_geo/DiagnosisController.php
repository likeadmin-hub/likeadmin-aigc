<?php

namespace app\tenantapi\controller\app\aigc_geo;

use app\common\service\app\aigc_geo\AigcGeoService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class DiagnosisController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcGeoService::diagnosisLists($this->tenantId, $this->request->get()));
    }

    public function run()
    {
        try {
            return $this->success('检测任务已创建', AigcGeoService::runDiagnosis($this->tenantId, 0, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
