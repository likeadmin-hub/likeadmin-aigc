<?php
namespace app\api\controller\app\aigc_motion_sync;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_motion_sync\MotionSyncService as S;
use app\common\service\app\aigc_motion_sync\MotionSyncJobs as J;
use app\common\service\app\aigc_motion_sync\MotionSyncTemplates as T;
class ProjectController extends BaseApiController
{
    private function respond(callable $call){try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException|\app\common\service\ai\PreSubmissionRejected $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Motion sync API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function detail(){return $this->respond(fn()=>S::projectDto(S::owned((int)$this->request->tenantId,$this->userId,(int)$this->request->get('id',0))));}
    public function save(){return $this->respond(fn()=>S::save((int)$this->request->tenantId,$this->userId,$this->request->post()));}
    public function fromJob(){return $this->respond(fn()=>S::fromJob((int)$this->request->tenantId,$this->userId,(int)$this->request->post('id',0)));}
}
