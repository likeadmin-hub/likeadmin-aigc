<?php
namespace app\platformapi\controller\app\open_platform;
use app\platformapi\controller\BaseAdminController;
use app\common\service\open_platform\OpenStore;
use app\common\service\open_platform\OpenManagement;
use app\common\service\open_platform\OpenPages;
use app\common\service\open_platform\OpenContracts;
use app\common\service\open_platform\OpenDocuments;
class ManagementController extends BaseAdminController
{
    private function run(callable $fn){try{return $this->success('操作成功',$fn());}catch(\Throwable $e){return $this->fail($e instanceof \app\common\service\open_platform\OpenException?$e->getMessage():'开放平台操作失败，请查看服务日志');}}
    public function detail(){return $this->run(fn()=>OpenStore::settings((int)$this->request->param('tenant_id',0)));}
    public function setup(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenManagement::saveSettings((int)$this->request->param('tenant_id',0),$this->request->post(),true));}
    public function pages(){return $this->run(fn()=>OpenPages::configured((int)$this->request->param('tenant_id',0)));}
    public function savePages(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenManagement::savePages((int)$this->request->param('tenant_id',0),(array)$this->request->post('pages',[])));}
    public function contracts(){return $this->run(fn()=>OpenStore::table('contract')->order('id','desc')->limit(1000)->select()->toArray());}
    public function documents(){return $this->run(fn()=>OpenStore::table('document')->where('tenant_id',(int)$this->request->param('tenant_id',0))->order('id','desc')->limit(1000)->select()->toArray());}
    public function saveDocument(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenDocuments::save((int)$this->request->param('tenant_id',0),$this->request->post()));}
    public function publishDocument(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenDocuments::publish((int)$this->request->param('tenant_id',0),(int)$this->request->post('id')));}
    public function requests(){return $this->run(fn()=>OpenManagement::requests((int)$this->request->get('tenant_id',-1),null,$this->request->get()));}
    public function requestDetail(){return $this->run(fn()=>OpenManagement::detail((int)$this->request->get('tenant_id',-1),null,(string)$this->request->get('request_id')));}
    public function recoveryPreview(){return $this->run(fn()=>OpenManagement::recoveryPreview((int)$this->request->get('tenant_id',-1),(string)$this->request->param('request_id')));}
    public function saveContract(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenContracts::save(OpenContracts::requestInput($this->request)));}
    public function createVerification(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>\app\common\service\open_platform\OpenContractPreview::issue((int)$this->request->post('id'),(int)$this->request->post('key_id'),(string)$this->request->post('path','')));}
    public function publishContract(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenContracts::publish((int)$this->request->post('id')));}
    public function inventory(){return $this->run(fn()=>['tools'=>array_map(fn($code,$tool)=>['code'=>$code,'name'=>$tool['name']],array_keys(\app\common\service\open_platform\OpenToolRegistry::all()),array_values(\app\common\service\open_platform\OpenToolRegistry::all())),'models'=>\think\facade\Db::name('power_market_product')->field('id,name,resource_type')->where('status',1)->order('id','asc')->limit(5000)->select()->toArray()]);}

}
