<?php
namespace app\tenantapi\controller\app\aigc_depth_replication;

use app\common\service\app\aigc_depth_replication\DepthJobs;
use app\common\service\app\AppDisplayConfigService;
use app\tenantapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    use \app\common\service\app\aigc_depth_replication\DepthResponse;

    public function detail()
    {
        return $this->respond(fn() => AppDisplayConfigService::appendToConfig((int)$this->request->tenantId, DepthJobs::APP, DepthJobs::config((int)$this->request->tenantId)));
    }

    public function setup()
    {
        return $this->respond(fn() => DepthJobs::setup((int)$this->request->tenantId, $this->request->post()));
    }

}

