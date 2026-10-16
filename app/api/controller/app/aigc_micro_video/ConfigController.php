<?php
namespace app\api\controller\app\aigc_micro_video;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_micro_video\MicroVideoService;
use app\common\service\app\aigc_micro_video\MicroVideoSettings;
class ConfigController extends BaseApiController
{
    public function detail()
    {
        try { return $this->success("操作成功", MicroVideoSettings::detail((int)$this->request->tenantId, false)); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
}
