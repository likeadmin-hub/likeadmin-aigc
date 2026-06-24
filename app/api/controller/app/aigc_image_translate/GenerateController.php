<?php

namespace app\api\controller\app\aigc_image_translate;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_image_translate\AigcImageTranslateService;
use Exception;

class GenerateController extends BaseApiController
{
    public function estimate()
    {
        try {
            return $this->success('估价成功', AigcImageTranslateService::estimate(
                (int)$this->request->tenantId,
                $this->request->post()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function index()
    {
        try {
            return $this->success('生成成功', AigcImageTranslateService::generate(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}

