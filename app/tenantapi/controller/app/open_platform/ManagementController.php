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
    private function run(callable $fn){try{\app\common\service\open_platform\OpenAdminPermission::assert('tenant_admin',$this->adminInfo,(string)$this->request->action());return $this->success('操作成功',$fn());}catch(\Throwable $e){return $this->fail($e instanceof \app\common\service\open_platform\OpenException?$e->getMessage():'开放平台操作失败，请查看服务日志');}}
    public function detail(){return $this->run(fn()=>OpenManagement::adminSettings($this->tenantId));}
    public function setup(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenManagement::saveSettings($this->tenantId,$this->request->post(),false));}
    public function pages(){return $this->run(fn()=>OpenPages::configured($this->tenantId));}
    public function savePages(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenManagement::savePages($this->tenantId,(array)$this->request->post('pages',[])));}
    public function contracts(){return $this->run(fn()=>OpenManagement::contractsForTenant($this->tenantId));}
    public function documents(){return $this->run(fn()=>OpenStore::table('document')->where('tenant_id',$this->tenantId)->order('id','desc')->limit(1000)->select()->toArray());}
    public function saveDocument(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenDocuments::save($this->tenantId,$this->request->post()));}
    public function publishDocument(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenDocuments::publish($this->tenantId,(int)$this->request->post('id')));}
    public function requests(){return $this->run(fn()=>OpenManagement::requests($this->tenantId,null,$this->request->get()));}
    public function requestDetail(){return $this->run(fn()=>OpenManagement::detail($this->tenantId,null,(string)$this->request->get('request_id')));}
    public function recoveryPreview(){return $this->run(fn()=>OpenManagement::recoveryPreview($this->tenantId,(string)$this->request->param('request_id')));}
    public function resumeTracking(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(function(){\app\common\service\open_platform\OpenAdminRecoveryPermission::assert('tenant_admin',$this->adminInfo);return \app\common\service\open_platform\OpenRecoveryAction::resume($this->tenantId,(string)$this->request->post('request_id'),(string)$this->request->post('preview_token'),'tenant_admin',$this->adminId);});}

    public function setMarketExposure(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenManagement::setMarketExposure($this->tenantId,(array)$this->request->post('product_ids',[]),(bool)$this->request->post('enabled',false),false));}
    public function inventory(){return $this->run(fn()=>['tools'=>array_map(fn($code,$tool)=>['code'=>$code,'name'=>$tool['name']],array_keys(\app\common\service\open_platform\OpenToolRegistry::all()),array_values(\app\common\service\open_platform\OpenToolRegistry::all())),'models'=>\think\facade\Db::name('power_market_product')->field('id,name,resource_type')->where('status',1)->order('id','asc')->limit(5000)->select()->toArray()]);}

}
