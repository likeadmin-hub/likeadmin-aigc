<?php
namespace app\platformapi\controller\app\aigc_video_replication;
use app\platformapi\controller\BaseAdminController;
use app\common\service\app\aigc_product_video\ProductVideoService as S;
use app\common\service\app\aigc_product_video\ProductVideoPolicy;
use app\common\service\app\aigc_product_video\VideoReplicationTemplates as T;
class CaseController extends BaseAdminController
{
    private function respond(callable $call){try{return $this->success('操作成功',ProductVideoPolicy::forReplication($call));}catch(\InvalidArgumentException|\app\common\service\ai\PreSubmissionRejected $e){return $this->fail($e->getMessage());}catch(\Throwable $e){\think\facade\Log::warning('Video replication templates: '.get_class($e));return $this->fail('操作暂未完成，请稍后重试');}}
    public function options(){return $this->respond(fn()=>T::options(0,$this->request->get()));}
    public function lists(){return $this->respond(fn()=>T::lists(0,$this->request->get()));}
    public function detail(){return $this->respond(fn()=>T::detail(0,(int)$this->request->get('id',0)));}
    public function save(){return $this->respond(fn()=>T::save(0,$this->request->post()));}
    public function fromJob(){return $this->respond(fn()=>T::fromJob(0,(int)$this->request->post('job_id',0),$this->request->post()));}
    public function status(){return $this->respond(function(){T::change(0,$this->request->post(),'status');return [];});}
    public function delete(){return $this->respond(function(){T::change(0,$this->request->post(),'delete');return [];});}
}
