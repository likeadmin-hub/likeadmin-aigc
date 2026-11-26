<?php
namespace app\platformapi\controller\app\aigc_depth_replication;

use app\common\service\app\aigc_depth_replication\DepthJobs;
use app\platformapi\controller\BaseAdminController;

class JobController extends BaseAdminController
{
    use \app\common\service\app\aigc_depth_replication\DepthResponse;

    public function lists()
    {
        return $this->respond(fn() => DepthJobs::lists((int)$this->request->get('tenant_id',0), null, (int)$this->request->get('before',0), true));
    }

}

