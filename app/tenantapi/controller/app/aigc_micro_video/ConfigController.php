<?php
namespace app\tenantapi\controller\app\aigc_micro_video;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_micro_video\MicroVideoService;
use app\common\service\app\aigc_micro_video\MicroVideoSettings;
use app\common\service\app\aigc_micro_video\MicroVideoCatalog;
class ConfigController extends BaseAdminController
{
    public function detail()
    {
        try {
            $id=(string)$this->request->get('model_specs','');
            if($id!==''){
                $models=MicroVideoCatalog::models($this->tenantId,false,'reference',[$id]);
                return $this->success("操作成功", ['model'=>$models[0]??null]);
            }
            return $this->success("操作成功", MicroVideoSettings::detail($this->tenantId,true,(int)$this->request->get('catalog_summary',0)===1));
        } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function setup()
    {
        try { MicroVideoSettings::save($this->tenantId, $this->request->post()); return $this->success("操作成功", []); } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
}
