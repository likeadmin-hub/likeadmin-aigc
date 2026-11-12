<?php
namespace app\api\controller\app\aigc_motion_sync;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_motion_sync\MotionSyncService as S;
use app\common\service\app\aigc_motion_sync\MotionSyncJobs as J;
use app\common\service\app\aigc_motion_sync\MotionSyncTemplates as T;
class ConfigController extends BaseApiController
{
    private function respond(callable $call){try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException|\app\common\service\ai\PreSubmissionRejected $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Motion sync API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public array $notNeedLogin=['detail','models'];
    public function detail(){return $this->respond(fn()=>S::config((int)$this->request->tenantId));}
    public function models(){return $this->respond(fn()=>['models'=>\app\common\service\power\MarketMotionSyncRuntimeService::options((int)$this->request->tenantId)]);}
}
