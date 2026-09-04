<?php

namespace app\api\controller\app\aigc_watermark_removal;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_watermark_removal\AigcWatermarkRemovalService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];
    public function detail() { return $this->success('获取成功', AigcWatermarkRemovalService::config((int)$this->request->tenantId)); }
}
