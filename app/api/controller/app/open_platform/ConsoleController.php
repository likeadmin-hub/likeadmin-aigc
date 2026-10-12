<?php
namespace app\api\controller\app\open_platform;
use app\api\controller\BaseApiController;
use app\common\service\open_platform\OpenKeyService;
use app\common\service\open_platform\OpenManagement;
use app\common\service\open_platform\OpenStore;
use app\common\service\open_platform\OpenPages;
use app\common\service\open_platform\OpenToolRegistry;
class ConsoleController extends BaseApiController
{
    private function run(callable $fn){try{return $this->success('获取成功',$fn());}catch(\Throwable $e){return $this->fail($e instanceof \app\common\service\open_platform\OpenException?$e->getMessage():'开放平台暂不可用');}}
    public function overview(){return $this->run(function(){$tenant=(int)$this->request->tenantId;$user=\app\common\model\user\User::where(['id'=>$this->userId,'tenant_id'=>$tenant])->findOrEmpty();return ['enabled'=>OpenStore::settings($tenant)['enabled']&&OpenStore::settings(0)['enabled'],'balance'=>(float)($user['user_money']??0),'base_path'=>'/api/v1','pages'=>OpenPages::configured($tenant),'usage'=>OpenManagement::usage($tenant,$this->userId,[]),'analytics'=>OpenManagement::analytics($tenant,$this->userId,(int)$this->request->get('days',7))];});}
    public function keys(){return $this->run(fn()=>OpenKeyService::lists((int)$this->request->tenantId,$this->userId));}
    public function createKey(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenKeyService::create((int)$this->request->tenantId,$this->userId,$this->request->post()));}
    public function setKey(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(function(){OpenKeyService::revoke((int)$this->request->tenantId,$this->userId,(int)$this->request->post('id'),(int)$this->request->post('status'));return [];});}
    public function rotateKey(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenKeyService::rotate((int)$this->request->tenantId,$this->userId,(int)$this->request->post('id')));}
    public function pages(){return $this->run(fn()=>OpenPages::configured((int)$this->request->tenantId));}
    public function catalog(){return $this->run(fn()=>\app\common\service\open_platform\OpenCatalog::forConsole((int)$this->request->tenantId,$this->userId));}
    public function requests(){return $this->run(fn()=>OpenManagement::requests((int)$this->request->tenantId,$this->userId,$this->request->get()));}
    public function requestDetail(){return $this->run(fn()=>OpenManagement::detail((int)$this->request->tenantId,$this->userId,(string)$this->request->get('request_id')));}
    public function debugTask(){if(!$this->request->isGet())return $this->fail('仅支持 GET 请求');return $this->run(function(){foreach(['path','product_id','scope','upstream_task_id','executor','method'] as $field)if(array_key_exists($field,$this->request->get()))throw new \app\common\service\open_platform\OpenException('invalid_request','历史查询只接受原请求标识');return \app\common\service\open_platform\OpenHistoryDebug::descriptor((int)$this->request->tenantId,$this->userId,(string)$this->request->get('request_id'));});}
    public function tasks(){return $this->run(fn()=>OpenManagement::requests((int)$this->request->tenantId,$this->userId,$this->request->get()+['tasks'=>true]));}
    public function usage(){return $this->run(fn()=>OpenManagement::usage((int)$this->request->tenantId,$this->userId,$this->request->get(),true));}
    public function callbacks(){return $this->run(fn()=>OpenManagement::callbacks((int)$this->request->tenantId,$this->userId,(string)$this->request->get('request_id')));}
    public function replayCallback(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(function(){\app\common\service\open_platform\OpenWebhooks::replay((int)$this->request->tenantId,$this->userId,(int)$this->request->post('id'));return [];});}

    public function updateKey(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(fn()=>OpenKeyService::update((int)$this->request->tenantId,$this->userId,(int)$this->request->post('id'),$this->request->post()));}
    public function debugPermit(){return $this->run(function(){$config=OpenStore::requireEnabled((int)$this->request->tenantId);if(empty($config['features']['playground'])||empty(OpenStore::settings(0)['features']['playground']))throw new \app\common\service\open_platform\OpenException('feature_disabled','在线调试已关闭',403);return ['permitted'=>true];});}
    public function quote(){if(!$this->request->isPost())return $this->fail('仅支持 POST 请求');return $this->run(function(){$raw=json_decode($this->request->getInput());$params=$raw->params??$this->request->post('params',[]);if(!($params instanceof \stdClass)&&(!is_array($params)||array_is_list($params)&&$params!==[]))throw new \app\common\service\open_platform\OpenException('invalid_request','请求参数必须是 JSON 对象');return OpenManagement::quote((int)$this->request->tenantId,$this->userId,(string)$this->request->post('code'),(array)$params);});}
    public function usageExport(){return $this->run(function(){if(empty(OpenStore::settings((int)$this->request->tenantId)['features']['exports'])||empty(OpenStore::settings(0)['features']['exports']))throw new \app\common\service\open_platform\OpenException('feature_disabled','导出已关闭',403);return OpenManagement::usage((int)$this->request->tenantId,$this->userId,$this->request->get());});}

}
