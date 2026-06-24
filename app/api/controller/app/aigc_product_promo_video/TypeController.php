<?php

namespace app\api\controller\app\aigc_product_promo_video;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_product_promo_video\AigcProductPromoVideoService;

class TypeController extends BaseApiController
{
    public array $notNeedLogin = ['lists'];

    public function lists()
    {
        return $this->success('获取成功', AigcProductPromoVideoService::typeLists((int)$this->request->tenantId, true));
    }
}
