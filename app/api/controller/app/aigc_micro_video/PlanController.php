<?php
namespace app\api\controller\app\aigc_micro_video;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_micro_video\MicroVideoService;
use app\common\service\app\aigc_micro_video\MicroVideoSettings;
class PlanController extends BaseApiController
{
    public function create()
    {
        try { return $this->success("操作成功", MicroVideoService::createPlan((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function save()
    {
        try { return $this->success("操作成功", MicroVideoService::savePlan((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
}
