<?php

namespace app\tenantapi\controller\app\aigc_geo;

use app\common\service\app\aigc_geo\AigcGeoService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class ArticleController extends BaseAdminController
{
    public function lists()
    {
        return $this->success('获取成功', AigcGeoService::articleLists($this->tenantId, $this->request->get()));
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcGeoService::articleDetail($this->tenantId, (int)$this->request->get('id', 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function save()
    {
        try {
            return $this->success('保存成功', AigcGeoService::saveArticle($this->tenantId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function generate()
    {
        try {
            return $this->success('生成成功', AigcGeoService::generateArticle($this->tenantId, 0, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function publish()
    {
        try {
            return $this->success('发布任务已创建', AigcGeoService::publishArticle($this->tenantId, (int)$this->request->post('id', 0), $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
