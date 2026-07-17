<?php

namespace app\api\controller\app\aigc_canvas;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_canvas\AigcCanvasSkillService;
use Exception;

class SkillController extends BaseApiController
{
    public function usable()
    {
        try {
            return $this->success('success', AigcCanvasSkillService::usable((int)$this->request->tenantId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        try {
            return $this->success('success', AigcCanvasSkillService::userDetail((int)$this->request->tenantId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
