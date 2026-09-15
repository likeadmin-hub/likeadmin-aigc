<?php

namespace app\tenantapi\controller\app\aigc_short_drama;

use app\common\service\app\aigc_short_drama\canvas\CanvasTenantConfigService;
use app\tenantapi\controller\BaseAdminController;
use Exception;

/** Tenant configuration surface for the isolated short-drama canvas. */
class CanvasConfigController extends BaseAdminController
{
    public function detail()
    {
        try { return $this->success('获取成功', CanvasTenantConfigService::detail($this->tenantId)); }
        catch (Exception $e) { return $this->fail('短剧画布配置暂不可用'); }
    }

    public function setup()
    {
        try { return $this->success('保存成功', CanvasTenantConfigService::save($this->tenantId, $this->request->post()), 1, 1); }
        catch (Exception $e) { return $this->fail($e->getMessage()); }
    }
}
