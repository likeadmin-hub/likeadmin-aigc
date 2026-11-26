<?php
namespace app\platformapi\controller\app\aigc_depth_replication;

use app\common\service\app\aigc_depth_replication\DepthJobs;
use app\common\service\app\AppDisplayConfigService;
use app\platformapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    use \app\common\service\app\aigc_depth_replication\DepthResponse;

    public function detail()
    {
        return $this->respond(fn() => AppDisplayConfigService::appendToConfig(0, DepthJobs::APP, DepthJobs::config(0)));
    }

    public function setup()
    {
        return $this->respond(fn() => DepthJobs::setup(0, $this->request->post()));
    }

}

