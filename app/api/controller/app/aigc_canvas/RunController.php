<?php

namespace app\api\controller\app\aigc_canvas;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_canvas\AigcCanvasService;

class RunController extends BaseApiController
{
    public function mediaDetail()
    {
        return $this->success('获取成功', \app\common\service\app\aigc_canvas\CanvasMediaPreviewService::detail((int)$this->request->tenantId, $this->userId, $this->request->get()));
    }

    public function lists()
    {
        return $this->success('获取成功', AigcCanvasService::runLists((int)$this->request->tenantId, $this->userId, $this->request->get()));
    }
}
