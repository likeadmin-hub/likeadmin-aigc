<?php
namespace app\platformapi\controller\app\aigc_story_commerce;
use app\platformapi\controller\BaseAdminController;
use app\common\service\app\aigc_story_commerce\StoryCommerceService as S;
use app\common\service\app\aigc_story_commerce\StoryCommerceJobs as J;
class ConfigController extends BaseAdminController
{
    private function respond(callable $call) {try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Story commerce API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function detail(){return $this->respond(fn()=>S::config(0));}
    public function setup(){return $this->respond(fn()=>S::setup(0,$this->request->post()));}
    public function models(){return $this->respond(fn()=>S::models(0,(string)$this->request->get('kind','video'),(string)$this->request->get('model_id','')));}
}
