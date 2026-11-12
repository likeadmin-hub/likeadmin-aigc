<?php
namespace app\api\controller\app\aigc_product_video;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_product_video\ProductVideoService as S;
use app\common\service\app\aigc_product_video\ProductVideoJobs as J;
use app\common\service\app\aigc_product_video\ProductVideoMedia as M;
class MediaController extends BaseApiController
{
    private function respond(callable $call) {try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Product video API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function import(){return $this->respond(fn()=>M::import((int)$this->request->tenantId,$this->userId,(string)$this->request->post('url','')));}
}
