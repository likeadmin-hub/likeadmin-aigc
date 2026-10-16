<?php
namespace app\tenantapi\controller\app\aigc_canvas;

use app\common\service\CanvasPortalService;
use app\tenantapi\controller\BaseAdminController;
use InvalidArgumentException;

class TemplateController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', CanvasPortalService::sectionEditor('cards'));
    }
    public function publish()
    {
        try {
            return $this->success('模板已发布', CanvasPortalService::saveSection('cards', $this->request->post()), 1, 1);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }
}
