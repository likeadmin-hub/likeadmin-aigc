<?php
namespace app\tenantapi\controller\app\aigc_story_commerce;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_story_commerce\StoryCommerceService as S;
use app\common\service\app\aigc_story_commerce\StoryCommerceJobs as J;
use app\common\service\app\aigc_story_commerce\StoryCommerceTemplates as T;
class CaseController extends BaseAdminController
{
    private function respond(callable $call){try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException|\app\common\service\ai\PreSubmissionRejected $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Story commerce API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function options(){return $this->respond(fn()=>S::config((int)$this->request->tenantId)+S::models((int)$this->request->tenantId,'video',(string)$this->request->get('model_id','')));}
    public function lists(){return $this->respond(fn()=>T::lists((int)$this->request->tenantId,$this->request->get()));}
    public function detail(){return $this->respond(fn()=>T::detail((int)$this->request->tenantId,(int)$this->request->get('id',0)));}
    public function save(){return $this->respond(fn()=>T::save((int)$this->request->tenantId,$this->request->post()));}
    public function fromJob(){return $this->respond(fn()=>T::fromJob((int)$this->request->tenantId,(int)$this->request->post('job_id',0),$this->request->post()));}
    public function status(){return $this->respond(function(){T::status((int)$this->request->tenantId,(int)$this->request->post('id',0),(int)$this->request->post('status',0));return [];});}
    public function delete(){return $this->respond(function(){T::delete((int)$this->request->tenantId,(int)$this->request->post('id',0));return [];});}
}
