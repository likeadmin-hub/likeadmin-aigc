<?php

namespace app\tenantapi\controller\app\aigc_short_drama;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\tenantapi\controller\BaseAdminController;

class ScriptTaskController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcShortDramaService::adminScriptTaskLists($this->tenantId, $this->request->get()));
    }
}
