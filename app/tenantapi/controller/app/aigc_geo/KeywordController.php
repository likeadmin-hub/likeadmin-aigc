<?php

namespace app\tenantapi\controller\app\aigc_geo;

use app\common\service\app\aigc_geo\AigcGeoService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class KeywordController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcGeoService::keywordLists($this->tenantId, $this->request->get()));
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcGeoService::saveKeyword($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function generate()
    {
        try {
            return $this->success('生成成功', AigcGeoService::generateKeywords($this->tenantId, 0, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
