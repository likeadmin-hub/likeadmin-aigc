<?php

namespace app\api\controller\app\aigc_product_promo_video;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_product_promo_video\AigcProductPromoVideoService;
use Exception;

class PromptController extends BaseApiController
{
    public function write()
    {
        try {
            return $this->success('生成成功', AigcProductPromoVideoService::writePrompt(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function optimize()
    {
        try {
            return $this->success('优化成功', AigcProductPromoVideoService::optimizePrompt(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
