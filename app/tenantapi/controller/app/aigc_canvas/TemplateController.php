<?php
namespace app\tenantapi\controller\app\aigc_canvas;

use app\common\service\CanvasPortalService;
use app\common\service\app\aigc_canvas\CanvasBuiltinPresetSourceService;
use app\tenantapi\controller\BaseAdminController;
use InvalidArgumentException;

class TemplateController extends BaseAdminController
{
    public function detail()
    {
        try {
            if ($this->request->get('sources')) return $this->success('获取成功', \app\common\service\app\aigc_canvas\CanvasTemplateService::sources((int)$this->request->tenantId, $this->request->get()));
            return $this->success('获取成功', CanvasPortalService::sectionEditor('cards')+['builtin_source_write'=>CanvasBuiltinPresetSourceService::capability((int)$this->request->tenantId)]);
        } catch (InvalidArgumentException $e) { return $this->fail($e->getMessage()); }
    }
    public function publish()
    {
        try {
            $input=$this->request->post();
            if (($input['action'] ?? '')==='export_builtin') {
                return $this->success('导出成功', \app\common\service\app\aigc_canvas\CanvasBuiltinPresetExporter::export((int)$this->request->tenantId,$input));
            }
            if (($input['action'] ?? '')==='save_builtin_source') {
                return $this->success('已保存到内置预设目录',CanvasBuiltinPresetSourceService::save((int)$this->request->tenantId,$input));
            }
            if (!empty($input['action'])) throw new InvalidArgumentException('模板操作无效');
            return $this->success('模板已发布', CanvasPortalService::saveSection('cards', $input)+['builtin_source_write'=>CanvasBuiltinPresetSourceService::capability((int)$this->request->tenantId)], 1, 1);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
    }
}
