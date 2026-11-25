<?php
namespace app\tenantapi\controller\app\aigc_video_replication;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\app\aigc_product_video\ProductVideoService as S;
use app\common\service\app\aigc_product_video\ProductVideoPolicy;
use app\common\service\app\aigc_product_video\VideoReplicationTemplates as T;
class CaseController extends BaseAdminController
{
    private function builtinScope(): int {$tenant=(int)$this->request->tenantId;if($tenant<1)throw new \InvalidArgumentException('租户身份无效');S::available($tenant);return $tenant;}
    public function builtin(){return $this->respond(fn()=>\app\common\service\app\StudioBuiltinTemplates::publish(\app\common\service\app\aigc_product_video\VideoReplicationTemplates::APP,(int)$this->request->post('id',0),$this->request->post(),$this->builtinScope()));}
    public function builtinFromJob(){return $this->respond(fn()=>\app\common\service\app\StudioBuiltinTemplates::fromJob(\app\common\service\app\aigc_product_video\VideoReplicationTemplates::APP,(int)$this->request->post('job_id',0),$this->request->post(),$this->builtinScope()));}

    private function respond(callable $call){try{return $this->success('操作成功',ProductVideoPolicy::forReplication($call));}catch(\InvalidArgumentException|\app\common\service\ai\PreSubmissionRejected $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Video replication templates: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function options(){return $this->respond(fn()=>T::options((int)$this->request->tenantId,$this->request->get()));}
    public function lists(){return $this->respond(fn()=>T::lists((int)$this->request->tenantId,$this->request->get()));}
    public function detail(){return $this->respond(fn()=>T::detail((int)$this->request->tenantId,(int)$this->request->get('id',0)));}
    public function save(){return $this->respond(fn()=>T::save((int)$this->request->tenantId,$this->request->post()));}
    public function fromJob(){return $this->respond(fn()=>T::fromJob((int)$this->request->tenantId,(int)$this->request->post('job_id',0),$this->request->post()));}
    public function status(){return $this->respond(function(){T::change((int)$this->request->tenantId,$this->request->post(),'status');return [];});}
    public function delete(){return $this->respond(function(){T::change((int)$this->request->tenantId,$this->request->post(),'delete');return [];});}
}
