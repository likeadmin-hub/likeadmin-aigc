<?php

namespace app\api\controller\app\aigc_outpaint;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_outpaint\AigcOutpaintService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('获取成功', AigcOutpaintService::config((int)$this->request->tenantId));
    }
}
