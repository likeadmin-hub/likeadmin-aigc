<?php
namespace app\platformapi\controller\app\aigc_product_video;
use app\platformapi\controller\BaseAdminController;
use app\common\service\app\aigc_product_video\ProductVideoService as S;
use app\common\service\app\aigc_product_video\ProductVideoJobs as J;
class JobController extends BaseAdminController
{
    private function respond(callable $call) {try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Product video API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function lists(){return $this->respond(fn()=>J::lists((int)$this->request->get('tenant_id',0),0,$this->request->get(),true));}
    public function detail(){return $this->respond(fn()=>J::dto(J::owned((int)$this->request->get('tenant_id',0),0,(int)$this->request->get('id',0),true)));}
}
