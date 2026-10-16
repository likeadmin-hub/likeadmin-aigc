<?php
namespace app\api\controller\app\aigc_micro_video;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_micro_video\MicroVideoService;
use app\common\service\app\aigc_micro_video\MicroVideoSettings;
class GenerateController extends BaseApiController
{
    public function estimate()
    {
        try { return $this->success("操作成功", MicroVideoService::estimate((int)$this->request->tenantId, $this->userId, $this->request->post(), false)); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function index()
    {
        try { return $this->success("操作成功", MicroVideoService::submit((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
}
