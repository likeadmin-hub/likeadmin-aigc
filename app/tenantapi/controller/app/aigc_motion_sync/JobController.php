<?php
namespace app\tenantapi\controller\app\aigc_motion_sync;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_motion_sync\MotionSyncService as S;
use app\common\service\app\aigc_motion_sync\MotionSyncJobs as J;
use app\common\service\app\aigc_motion_sync\MotionSyncTemplates as T;
class JobController extends BaseAdminController
{
    private function respond(callable $call){try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException|\app\common\service\ai\PreSubmissionRejected $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Motion sync API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function detail(){return $this->respond(fn()=>J::dto(J::owned((int)$this->request->tenantId,0,(int)$this->request->get('id',0),true),true));}
    public function lists(){return $this->respond(fn()=>J::lists((int)$this->request->tenantId,0,$this->request->get(),true));}
    public function delete(){return $this->respond(function(){J::delete((int)$this->request->tenantId,0,(int)$this->request->post('id',0),true);return [];});}
}
