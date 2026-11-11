<?php
namespace app\api\controller\app\aigc_story_commerce;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_story_commerce\StoryCommerceService as S;
use app\common\service\app\aigc_story_commerce\StoryCommerceJobs as J;
use app\common\service\app\aigc_story_commerce\StoryCommerceTemplates as T;
class CaseController extends BaseApiController
{
    private function respond(callable $call){try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException|\app\common\service\ai\PreSubmissionRejected $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Story commerce API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public array $notNeedLogin=['detail','lists'];
    public function lists(){return $this->respond(fn()=>T::lists((int)$this->request->tenantId,$this->request->get(),true));}
    public function detail(){return $this->respond(fn()=>T::detail((int)$this->request->tenantId,(int)$this->request->get('id',0),true));}
    public function remix(){return $this->respond(fn()=>T::remix((int)$this->request->tenantId,$this->userId,(int)$this->request->post('id',0)));}
}
