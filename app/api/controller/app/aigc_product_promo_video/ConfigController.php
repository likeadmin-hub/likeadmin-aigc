<?php

namespace app\api\controller\app\aigc_product_promo_video;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_product_promo_video\AigcProductPromoVideoService;

class ConfigController extends BaseApiController
{
    public array $notNeedLogin = ['detail'];

    public function detail()
    {
        return $this->success('获取成功', AigcProductPromoVideoService::config((int)$this->request->tenantId));
    }
}
