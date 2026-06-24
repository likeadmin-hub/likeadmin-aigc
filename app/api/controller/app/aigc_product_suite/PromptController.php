<?php

namespace app\api\controller\app\aigc_product_suite;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_product_suite\AigcProductSuiteService;
use Exception;

class PromptController extends BaseApiController
{
    public function optimize()
    {
        try {
            return $this->success('优化成功', AigcProductSuiteService::optimizePrompt(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
