<?php
namespace app\tenantapi\controller\app\aigc_micro_video;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_micro_video\MicroVideoService;
use app\common\service\app\aigc_micro_video\MicroVideoSettings;
use app\common\service\app\aigc_micro_video\MicroVideoCatalog;
use app\common\service\app\aigc_micro_video\MicroVideoStore;
use app\common\service\app\AppDisplayConfigService;
use think\facade\Db;

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
            $config = MicroVideoSettings::detail($this->tenantId, true, (int)$this->request->get('catalog_summary', 0) === 1);
            return $this->success("操作成功", AppDisplayConfigService::appendToConfig($this->tenantId, MicroVideoStore::APP, $config));
        } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
    public function setup()
    {
        try {
            $params = $this->request->post();
            Db::transaction(function () use ($params) {
                $business = $params;
                unset($business['display_config']);
                if ($business) {
                    MicroVideoSettings::save($this->tenantId, $business);
                }
                AppDisplayConfigService::saveFromConfigPayload($this->tenantId, MicroVideoStore::APP, $params);
            });
            return $this->success("操作成功", []);
        } catch (\Throwable $e) { return $this->fail(MicroVideoService::friendly($e)); }
    }
}
