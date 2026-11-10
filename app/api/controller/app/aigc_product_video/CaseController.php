<?php
namespace app\api\controller\app\aigc_product_video;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_product_video\ProductVideoTemplates as T;
class CaseController extends BaseApiController
{
    public array $notNeedLogin=['lists','detail'];
    private function respond(callable $call){try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Product video template API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function lists(){return $this->respond(fn()=>T::lists((int)$this->request->tenantId,$this->request->get(),true));}
    public function detail(){return $this->respond(fn()=>T::detail((int)$this->request->tenantId,(int)$this->request->get('id',0),true));}
    public function remix(){return $this->respond(fn()=>T::remix((int)$this->request->tenantId,$this->userId,(int)$this->request->post('id',0)));}
}
