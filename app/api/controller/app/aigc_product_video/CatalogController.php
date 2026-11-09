<?php
namespace app\api\controller\app\aigc_product_video;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_product_video\ProductVideoProducts as P;
class CatalogController extends BaseApiController
{
    private function respond(callable $call){try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Product video catalog: '.get_class($e));return $this->fail('商品资料暂未处理完成，请重试');}}
    public function lists(){return $this->respond(fn()=>P::lists((int)$this->request->tenantId,$this->userId,$this->request->get()));}
    public function save(){return $this->respond(fn()=>P::save((int)$this->request->tenantId,$this->userId,$this->request->post()));}
    public function delete(){return $this->respond(function(){P::delete((int)$this->request->tenantId,$this->userId,(int)$this->request->post('id'));return [];});}
    public function import(){return $this->respond(fn()=>P::import((int)$this->request->tenantId,$this->userId,(string)$this->request->post('url','')));}
}
