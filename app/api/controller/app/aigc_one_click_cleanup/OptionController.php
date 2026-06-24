<?php

namespace app\api\controller\app\aigc_one_click_cleanup;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_one_click_cleanup\AigcOneClickCleanupService;

class OptionController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        return $this->success('获取成功', AigcOneClickCleanupService::optionLists((int)$this->request->tenantId, true));
    }
}
