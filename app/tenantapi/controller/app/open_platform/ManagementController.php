<?php
namespace app\tenantapi\controller\app\open_platform;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\open_platform\OpenStore;
use app\common\service\open_platform\OpenManagement;
use app\common\service\open_platform\OpenPages;
use app\common\service\open_platform\OpenContracts;
use app\common\service\open_platform\OpenDocuments;
class ManagementController extends BaseAdminController
{
    private function run(callable $fn){try{return $this->success('操作成功',$fn());}catch(\Throwable $e){return $this->fail($e instanceof \app\common\service\open_platform\OpenException?$e->getMessage():'开放平台操作失败，请查看服务日志');}}
    public function detail(){return $this->run(fn()=>OpenStore::settings($this->tenantId));}
    public function setup(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenManagement::saveSettings($this->tenantId,$this->request->post(),false));}
    public function pages(){return $this->run(fn()=>OpenPages::configured($this->tenantId));}
    public function savePages(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenManagement::savePages($this->tenantId,(array)$this->request->post('pages',[])));}
    public function contracts(){return $this->run(fn()=>OpenStore::table('contract')->order('id','desc')->limit(1000)->select()->toArray());}
    public function documents(){return $this->run(fn()=>OpenStore::table('document')->where('tenant_id',$this->tenantId)->order('id','desc')->limit(1000)->select()->toArray());}
    public function saveDocument(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenDocuments::save($this->tenantId,$this->request->post()));}
    public function publishDocument(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenDocuments::publish($this->tenantId,(int)$this->request->post('id')));}
    public function requests(){return $this->run(fn()=>OpenManagement::requests($this->tenantId,null,$this->request->get()));}
    public function requestDetail(){return $this->run(fn()=>OpenManagement::detail($this->tenantId,null,(string)$this->request->get('request_id')));}
    public function recoveryPreview(){return $this->run(function(){return ['preview'=>OpenManagement::detail($this->tenantId,null,(string)$this->request->param('request_id')),'operation'=>'query_only','will_charge'=>false];});}
    
}
