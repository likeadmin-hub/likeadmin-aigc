<?php
namespace app\platformapi\controller\app\aigc_product_video;
use app\platformapi\controller\BaseAdminController;
use app\common\service\app\aigc_product_video\ProductVideoTemplates as T;
class CaseController extends BaseAdminController
{
    private function scope():int{return (int)$this->request->get('tenant_id',0);}
    private function respond(callable $call){try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Product video template API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function builtin(){return $this->respond(fn()=>\app\common\service\app\aigc_product_video\ProductVideoBuiltins::publish((int)$this->request->post('id',0),$this->request->post()));}
    public function create(){return $this->respond(fn()=>T::create($this->scope(),$this->request->post()));}
    public function lists(){return $this->respond(fn()=>T::lists($this->scope(),$this->request->get()));}
    public function detail(){return $this->respond(fn()=>T::detail($this->scope(),(int)$this->request->get('id',0)));}
    public function options(){return $this->respond(fn()=>T::options($this->scope(),$this->request->get()));}
    public function fromJob(){return $this->respond(fn()=>T::fromJob($this->scope(),(int)$this->request->post('job_id',0),$this->request->post()));}
    public function save(){return $this->respond(fn()=>T::save($this->scope(),$this->request->post()));}
    public function status(){return $this->respond(function(){T::status($this->scope(),(int)$this->request->post('id',0),(int)$this->request->post('status',0),$this->request->post());return [];});}
    public function delete(){return $this->respond(function(){T::delete($this->scope(),(int)$this->request->post('id',0),$this->request->post());return [];});}
}
