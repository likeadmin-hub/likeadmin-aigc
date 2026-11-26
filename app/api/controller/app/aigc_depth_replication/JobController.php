<?php
namespace app\api\controller\app\aigc_depth_replication;

use app\common\service\app\aigc_depth_replication\DepthJobs;
use app\common\service\app\aigc_depth_replication\Lighting;
use app\api\controller\BaseApiController;

class JobController extends BaseApiController
{
    use \app\common\service\app\aigc_depth_replication\DepthResponse;
    public array $notNeedLogin = ['config'];

    public function config()
    {
        return $this->respond(fn() => DepthJobs::config((int)$this->request->tenantId));
    }

    public function create()
    {
        return $this->respond(fn() => DepthJobs::create((int)$this->request->tenantId, $this->userId, $this->request->post()));
    }

    public function lists()
    {
        return $this->respond(fn() => DepthJobs::lists((int)$this->request->tenantId, $this->userId, (int)$this->request->get('before',0)));
    }

    public function detail()
    {
        return $this->respond(fn() => DepthJobs::dto(DepthJobs::owned((int)$this->request->tenantId, $this->userId, (int)$this->request->get('id',0)), true));
    }

    public function frames()
    {
        return $this->respond(fn() => DepthJobs::frames(DepthJobs::owned((int)$this->request->tenantId, $this->userId, (int)$this->request->get('id',0)), (int)$this->request->get('start',0)));
    }

    public function lighting()
    {
        return $this->respond(fn() => ['table'=>Lighting::table((array)$this->request->post('settings',[]))]);
    }

}
