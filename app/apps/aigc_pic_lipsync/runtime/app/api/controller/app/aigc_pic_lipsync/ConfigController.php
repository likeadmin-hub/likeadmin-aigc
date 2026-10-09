<?php

namespace app\api\controller\app\aigc_pic_lipsync;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_pic_lipsync\AigcPicLipsyncService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];
    public function detail() { return $this->success('获取成功', AigcPicLipsyncService::config((int)$this->request->tenantId)); }
}
