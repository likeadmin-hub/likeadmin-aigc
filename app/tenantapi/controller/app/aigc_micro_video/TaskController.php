<?php
namespace app\tenantapi\controller\app\aigc_micro_video;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_micro_video\MicroVideoService;
use app\common\service\app\aigc_micro_video\MicroVideoSettings;
class TaskController extends BaseAdminController
{
    public function lists()
    {
        try { return $this->success("操作成功", MicroVideoService::lists($this->tenantId, 0, $this->request->get(), true)); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function detail()
    {
        try { return $this->success("操作成功", MicroVideoService::detail($this->tenantId, 0, (int)$this->request->get("id"), true)); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function retry()
    {
        try { return $this->success("操作成功", MicroVideoService::retry($this->tenantId, 0, $this->request->post(), true)); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function delete()
    {
        try { MicroVideoService::delete($this->tenantId, 0, (int)$this->request->post("id")); return $this->success("操作成功", []); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
}
