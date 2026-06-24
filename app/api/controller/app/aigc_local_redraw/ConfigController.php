<?php

namespace app\api\controller\app\aigc_local_redraw;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_local_redraw\AigcLocalRedrawService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('获取成功', AigcLocalRedrawService::config((int)$this->request->tenantId));
    }
}
