<?php
namespace app\api\controller\app\aigc_micro_video;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_micro_video\MicroVideoService;
use app\common\service\app\aigc_micro_video\MicroVideoSettings;
class PromptController extends BaseApiController
{
    public function write()
    {
        try { return $this->success("操作成功", MicroVideoService::writePrompt((int)$this->request->tenantId, $this->userId, $this->request->post())); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
}
