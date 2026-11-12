<?php
namespace app\tenantapi\controller\app\aigc_product_video;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_product_video\ProductVideoService as S;
use app\common\service\app\aigc_product_video\ProductVideoJobs as J;
class JobController extends BaseAdminController
{
    private function respond(callable $call) {try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Product video API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function delete(){return $this->respond(function(){J::adminDelete((int)$this->request->tenantId,(int)$this->request->post('id',0));return [];});}
    public function lists(){return $this->respond(fn()=>J::lists((int)$this->request->tenantId,0,$this->request->get(),true));}
    public function detail(){return $this->respond(fn()=>\app\common\service\app\aigc_product_video\ProductVideoTemplates::adminDetail((int)$this->request->tenantId,(int)$this->request->get('id',0)));}
}
