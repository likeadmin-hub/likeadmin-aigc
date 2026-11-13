<?php
namespace app\platformapi\controller\app\aigc_store_visit;
use app\platformapi\controller\BaseAdminController;
use app\common\service\app\aigc_store_visit\StoreVisitService as S;
use app\common\service\app\aigc_store_visit\StoreVisitJobs as J;
class JobController extends BaseAdminController
{
    private function respond(callable $call) {try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Story commerce API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function delete(){return $this->respond(function(){J::adminDelete((int)$this->request->post('tenant_id',0),(int)$this->request->post('id',0));return [];});}
    public function lists(){return $this->respond(fn()=>J::lists((int)$this->request->get('tenant_id',0),0,$this->request->get(),true));}
    public function detail(){return $this->respond(fn()=>\app\common\service\app\aigc_store_visit\StoreVisitTemplates::adminDetail((int)$this->request->get('tenant_id',0),(int)$this->request->get('id',0)));}
}
