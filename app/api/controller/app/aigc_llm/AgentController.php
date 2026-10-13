<?php
namespace app\api\controller\app\aigc_llm;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_llm\agent\{AgentStore,AgentExecutionService,AgentCapabilityCatalog,AgentArtifactService};
use app\common\service\open_platform\{OpenAssets,OpenException};

class AgentController extends BaseApiController
{
    private function handle(callable $call)
    {
        try{
            AgentStore::access((int)$this->request->tenantId,$this->userId);
            return $this->success('', $call((int)$this->request->tenantId,$this->userId));
        }catch(\InvalidArgumentException|OpenException $e){return $this->fail($e->getMessage());}
        catch(\Throwable $e){\think\facade\Log::error('Home Agent API: '.get_class($e));return $this->fail('Agent 暂不可用，请检查服务部署后重试');}
    }
    public function config(){return $this->handle(fn($t,$u)=>['preferences'=>AgentStore::preferences($t,$u),'models'=>AgentCapabilityCatalog::models($t),'tools'=>array_values(AgentCapabilityCatalog::all($t,$u))]);}
    public function preferences(){return $this->handle(fn($t,$u)=>AgentStore::preferences($t,$u,$this->request->post()));}
    public function submit(){return $this->handle(fn($t,$u)=>AgentExecutionService::submit($t,$u,$this->request->post()));}
    public function detail(){return $this->handle(fn($t,$u)=>AgentExecutionService::detail($t,$u,(int)$this->request->get('run_id',0)));}
    public function events(){return $this->handle(fn($t,$u)=>AgentExecutionService::events($t,$u,(int)$this->request->get('run_id',0),(int)$this->request->get('after',0)));}
    public function confirm(){return $this->handle(fn($t,$u)=>AgentExecutionService::confirm($t,$u,(int)$this->request->post('run_id',0),(string)$this->request->post('approval_hash','')));}
    public function stop(){return $this->handle(fn($t,$u)=>AgentExecutionService::stop($t,$u,(int)$this->request->post('run_id',0)));}
    public function runs(){return $this->handle(function($t,$u){
        $ids=AgentStore::owner('run',$t,$u)->where('session_id',(int)$this->request->get('session_id',0))->order('id','desc')->limit(30)->column('id');
        return array_map(fn($id)=>AgentExecutionService::detail($t,$u,(int)$id),$ids);
    });}
    public function artifacts(){return $this->handle(fn($t,$u)=>AgentArtifactService::lists($t,$u,(int)$this->request->get('session_id',0)));}
    public function favorite(){return $this->handle(function($t,$u){AgentArtifactService::favorite($t,$u,(int)$this->request->post('id',0),(bool)\app\common\service\WorkspaceAssetService::normalizeFavorite($this->request->post('favorite',null,null)));return [];});}
    public function upload(){return $this->handle(function($t,$u){
        $file=$this->request->file('file');if(!$file||$file->getSize()>50*1024*1024)throw new \InvalidArgumentException('请选择50MB以内的素材');
        $mime=$file->getMime();$type=explode('/',$mime)[0];
        if(!in_array($type,['image','video','audio'],true))throw new \InvalidArgumentException('当前支持图片、视频和音频素材');
        return OpenAssets::upload($t,$u,$type);
    });}
}
