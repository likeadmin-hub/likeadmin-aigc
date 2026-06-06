<?php

namespace app\api\controller\app\smart_clip;

use app\api\controller\BaseApiController;
use app\common\service\app\smart_clip\SmartClipService;

class TemplateController extends BaseApiController
{
    public function lists()
    {
        try {
            return $this->success('获取成功', SmartClipService::templateLists((int)$this->request->tenantId, $this->request->get()));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', SmartClipService::templateDetail((int)$this->request->tenantId, (string)$this->request->get('id', '')));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
