<?php
namespace app\tenantapi\controller\app\aigc_micro_video;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_micro_video\MicroVideoService;
use app\common\service\app\aigc_micro_video\MicroVideoSettings;
class ConfigController extends BaseAdminController
{
    public function detail()
    {
        try { return $this->success("操作成功", MicroVideoSettings::detail($this->tenantId, true)); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function setup()
    {
        try { MicroVideoSettings::save($this->tenantId, $this->request->post()); return $this->success("操作成功", []); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
}
