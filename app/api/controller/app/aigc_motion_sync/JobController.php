<?php
namespace app\api\controller\app\aigc_motion_sync;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_motion_sync\MotionSyncService as S;
use app\common\service\app\aigc_motion_sync\MotionSyncJobs as J;
use app\common\service\app\aigc_motion_sync\MotionSyncTemplates as T;
class JobController extends BaseApiController
{
    private function respond(callable $call){try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException|\app\common\service\ai\PreSubmissionRejected $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Motion sync API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function estimate(){return $this->respond(fn()=>J::estimate((int)$this->request->tenantId,$this->userId,$this->request->post()));}
    public function create(){return $this->respond(fn()=>J::create((int)$this->request->tenantId,$this->userId,$this->request->post()));}
    public function detail(){return $this->respond(fn()=>J::dto(J::owned((int)$this->request->tenantId,$this->userId,(int)$this->request->get('id',0))));}
    public function lists(){return $this->respond(fn()=>J::lists((int)$this->request->tenantId,$this->userId,$this->request->get()));}
    public function cancel(){return $this->respond(fn()=>J::cancel((int)$this->request->tenantId,$this->userId,(int)$this->request->post('id',0)));}
    public function recover(){return $this->respond(fn()=>J::recover((int)$this->request->tenantId,$this->userId,(int)$this->request->post('id',0)));}
    public function delete(){return $this->respond(function(){J::delete((int)$this->request->tenantId,$this->userId,(int)$this->request->post('id',0));return [];});}
}
