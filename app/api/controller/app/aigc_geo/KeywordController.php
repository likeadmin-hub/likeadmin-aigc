<?php

namespace app\api\controller\app\aigc_geo;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_geo\AigcGeoService;
use Exception;

class KeywordController extends BaseApiController
{
    public function lists()
    {
        return $this->success('获取成功', AigcGeoService::keywordLists((int)$this->request->tenantId, $this->request->get(), $this->userId));
    }

    public function generate()
    {
        try {
            return $this->success('生成成功', AigcGeoService::generateKeywords((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
