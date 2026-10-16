<?php
namespace app\tenantapi\controller\app\aigc_micro_video;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_micro_video\MicroVideoService;
use app\common\service\app\aigc_micro_video\MicroVideoSettings;
class DirectionController extends BaseAdminController
{
    public function lists()
    {
        try { return $this->success("操作成功", MicroVideoSettings::directions($this->tenantId, true)); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function save()
    {
        try { return $this->success("操作成功", MicroVideoSettings::saveDirection($this->tenantId, $this->request->post())); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function status()
    {
        try { MicroVideoSettings::changeDirection($this->tenantId, $this->request->post()); return $this->success("操作成功", []); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function delete()
    {
        try { MicroVideoSettings::changeDirection($this->tenantId, $this->request->post(), true); return $this->success("操作成功", []); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
}
