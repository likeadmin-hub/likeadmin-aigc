<?php
namespace app\platformapi\controller\app\aigc_canvas;

use app\common\service\app\aigc_canvas\CanvasPublicTemplateService;
use app\common\service\app\aigc_canvas\CanvasRequestInput;
use app\platformapi\controller\BaseAdminController;
use InvalidArgumentException;

class TemplateController extends BaseAdminController
{
    public function detail()
    {
        try {
            $params=$this->request->get();
            return $this->success('获取成功',!empty($params['sources']) ? CanvasPublicTemplateService::sources($params) : CanvasPublicTemplateService::editor());
        } catch (InvalidArgumentException $e) { return $this->fail($e->getMessage()); }
    }
    public function publish()
    {
        try { return $this->success('公共模板已发布',CanvasPublicTemplateService::publish(CanvasRequestInput::post($this->request)),1,1); }
        catch (InvalidArgumentException $e) { return $this->fail($e->getMessage()); }
    }
}
