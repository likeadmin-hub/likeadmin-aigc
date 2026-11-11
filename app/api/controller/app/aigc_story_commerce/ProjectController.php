<?php
namespace app\api\controller\app\aigc_story_commerce;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_story_commerce\StoryCommerceService as S;
use app\common\service\app\aigc_story_commerce\StoryCommerceJobs as J;
class ProjectController extends BaseApiController
{
    private function respond(callable $call) {try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Story commerce API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function detail(){return $this->respond(fn()=>S::projectDto(S::owned((int)$this->request->tenantId,$this->userId,(int)$this->request->get('id',0))));}
    public function save(){return $this->respond(fn()=>S::save((int)$this->request->tenantId,$this->userId,$this->request->post()));}
    public function fromJob(){return $this->respond(fn()=>S::fromJob((int)$this->request->tenantId,$this->userId,(int)$this->request->post('job_id',0)));}
    public function adopt(){return $this->respond(fn()=>S::adopt((int)$this->request->tenantId,$this->userId,$this->request->post()));}
}
