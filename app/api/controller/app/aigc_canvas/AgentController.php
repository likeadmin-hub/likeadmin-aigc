<?php
namespace app\api\controller\app\aigc_canvas;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_canvas\{CanvasAssetService,CanvasRequestInput};
/** Native asset URL compatibility for open browser tabs; no legacy Agent runtime. */
final class AgentController extends BaseApiController
{
    public function assetRegister()
    {
        try {
            return $this->success('登记成功', CanvasAssetService::registerAsset((int)$this->request->tenantId, $this->userId, CanvasRequestInput::post($this->request)), 1, 1);
        } catch (\Exception $e) { return $this->fail($e->getMessage()); }
    }
}
