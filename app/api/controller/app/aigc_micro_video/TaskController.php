<?php
namespace app\api\controller\app\aigc_micro_video;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_micro_video\MicroVideoService;
use app\common\service\app\aigc_micro_video\MicroVideoSettings;
class TaskController extends BaseApiController
{
    public function lists()
    {
        try { return $this->success("操作成功", MicroVideoService::lists((int)$this->request->tenantId, $this->userId, $this->request->get(), false)); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function detail()
    {
        try { return $this->success("操作成功", MicroVideoService::detail((int)$this->request->tenantId, $this->userId, (int)$this->request->get("id"), false)); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function retry()
    {
        try { return $this->success("操作成功", MicroVideoService::retry((int)$this->request->tenantId, $this->userId, $this->request->post(), false)); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function delete()
    {
        try { MicroVideoService::delete((int)$this->request->tenantId, $this->userId, (int)$this->request->post("id")); return $this->success("操作成功", []); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
}
