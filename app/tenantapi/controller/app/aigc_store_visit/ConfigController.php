<?php
namespace app\tenantapi\controller\app\aigc_store_visit;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_store_visit\StoreVisitService as S;
use app\common\service\app\aigc_store_visit\StoreVisitJobs as J;
class ConfigController extends BaseAdminController
{
    private function respond(callable $call) {try{return $this->success('操作成功',$call());}catch(\InvalidArgumentException $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Story commerce API: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function detail(){return $this->respond(fn()=>S::config((int)$this->request->tenantId));}
    public function setup(){return $this->respond(function(){$p=$this->request->post();unset($p['genres']);return S::setup((int)$this->request->tenantId,$p);});}
    public function genres(){return $this->respond(fn()=>['genres'=>S::settings((int)$this->request->tenantId)['genres']]);}
    public function saveGenres(){return $this->respond(fn()=>S::setup((int)$this->request->tenantId,['genres'=>(array)$this->request->post('genres',[])]));}
    public function models(){return $this->respond(fn()=>S::models((int)$this->request->tenantId,(string)$this->request->get('kind','video'),(string)$this->request->get('model_id','')));}
}
