<?php
namespace app\tenantapi\controller\app\aigc_story_commerce;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_story_commerce\StoryCommerceService as S;
use app\common\service\app\aigc_story_commerce\StoryCommerceJobs as J;
class ConfigController extends BaseAdminController
{
    private function respond(callable $call) {try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Story commerce API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function detail(){return $this->respond(fn()=>\app\common\service\app\AppDisplayConfigService::appendToConfig((int)$this->request->tenantId,'aigc_story_commerce',S::settings((int)$this->request->tenantId)+['styles'=>\app\common\service\app\aigc_story_commerce\StoryCommerceCatalog::styles((int)$this->request->tenantId)]));}
    public function setup(){return $this->respond(fn()=>S::setup((int)$this->request->tenantId,$this->request->post()));}
    public function models(){return $this->respond(fn()=>S::models((int)$this->request->tenantId,(string)$this->request->get('kind','video'),(string)$this->request->get('model_id','')));}
}
