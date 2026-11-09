<?php
namespace app\api\controller\app\aigc_product_video;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_product_video\ProductVideoService as S;
use app\common\service\app\aigc_product_video\ProductVideoJobs as J;
use app\common\service\app\aigc_product_video\ProductVideoMedia as M;
class ProjectController extends BaseApiController
{
    private function respond(callable $call) {try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Product video API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function detail(){return $this->respond(fn()=>S::projectDto(S::owned((int)$this->request->tenantId,$this->userId,(int)$this->request->get('id',0))));}
    public function save(){return $this->respond(fn()=>S::save((int)$this->request->tenantId,$this->userId,$this->request->post()));}
    public function adopt(){return $this->respond(fn()=>S::adopt((int)$this->request->tenantId,$this->userId,$this->request->post()));}
}
