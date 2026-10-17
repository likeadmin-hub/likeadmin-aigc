<?php
namespace app\tenantapi\controller\app\aigc_canvas;

use app\common\service\CanvasPortalService;
use app\tenantapi\controller\BaseAdminController;
use InvalidArgumentException;

class TemplateController extends BaseAdminController
{
    public function detail()
    {
        try {
            if ($this->request->get('sources')) return $this->success('获取成功', \app\common\service\app\aigc_canvas\CanvasTemplateService::sources((int)$this->request->tenantId, $this->request->get()));
            return $this->success('获取成功', CanvasPortalService::sectionEditor('cards'));
        } catch (InvalidArgumentException $e) { return $this->fail($e->getMessage()); }
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
