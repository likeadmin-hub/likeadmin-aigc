<?php
namespace app\platformapi\controller\app\aigc_motion_sync;
use app\platformapi\controller\BaseAdminController;
use app\common\service\app\aigc_motion_sync\MotionSyncService as S;
use app\common\service\app\aigc_motion_sync\MotionSyncJobs as J;
use app\common\service\app\aigc_motion_sync\MotionSyncTemplates as T;
class CaseController extends BaseAdminController
{
    private function respond(callable $call){try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException|\app\common\service\ai\PreSubmissionRejected $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Motion sync API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function lists(){return $this->respond(fn()=>T::lists(0,$this->request->get()));}
    public function detail(){return $this->respond(fn()=>T::detail(0,(int)$this->request->get('id',0)));}
    public function save(){return $this->respond(fn()=>T::save(0,$this->request->post()));}
    public function fromJob(){return $this->respond(fn()=>T::fromJob(0,(int)$this->request->post('job_id',0),$this->request->post()));}
    public function status(){return $this->respond(function(){T::status(0,(int)$this->request->post('id',0),(int)$this->request->post('status',0));return [];});}
    public function delete(){return $this->respond(function(){T::delete(0,(int)$this->request->post('id',0));return [];});}
}
