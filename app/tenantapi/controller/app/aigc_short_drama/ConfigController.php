<?php

namespace app\tenantapi\controller\app\aigc_short_drama;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        $data = AigcShortDramaService::config($this->tenantId);
        $data = \app\common\service\LikeAdminBrandService::publicPresentation($data, (int)$this->request->get('presentation_schema', 0));
        return $this->success('获取成功', $data);
    }

    public function setup()
    {
        try {
            AigcShortDramaService::saveConfig($this->tenantId, $this->request->post());
            return $this->success('保存成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
}
