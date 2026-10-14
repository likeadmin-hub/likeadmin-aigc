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
    public function config(){return $this->handle(function($t,$u){
        $tools=AgentCapabilityCatalog::all($t,$u);$displays=[];
        foreach(array_unique(array_merge(['aigc_llm'],array_keys($tools))) as $code)$displays[$code]=\app\common\service\app\AppDisplayConfigService::skillPreview($t,$code);
        return ['preferences'=>AgentStore::preferences($t,$u),'models'=>AgentCapabilityCatalog::models($t),'tools'=>array_values($tools),'skill_displays'=>$displays,'skill_configs'=>\app\common\service\app\aigc_llm\agent\AgentSkillConfigService::forTools($t,array_keys($tools))];
    });}
    public function preferences(){return $this->handle(fn($t,$u)=>AgentStore::preferences($t,$u,$this->request->post()));}
    public function submit(){return $this->handle(fn($t,$u)=>AgentExecutionService::submit($t,$u,$this->request->post()));}
    public function detail(){return $this->handle(fn($t,$u)=>AgentExecutionService::detail($t,$u,(int)$this->request->get('run_id',0)));}
    public function events(){return $this->handle(fn($t,$u)=>AgentExecutionService::events($t,$u,(int)$this->request->get('run_id',0),(int)$this->request->get('after',0)));}
    public function stream()
    {
        $tenant=(int)$this->request->tenantId;$user=$this->userId;$id=(int)$this->request->post('run_id',0);
        // Authenticate and verify ownership before starting a long-lived response.
        try{AgentStore::access($tenant,$user);AgentStore::run($tenant,$user,$id);}
        catch(\InvalidArgumentException|OpenException $e){return $this->fail($e->getMessage());}
        @ini_set('zlib.output_compression','0');
        @ini_set('implicit_flush','1');
        while(ob_get_level()>0)@ob_end_flush();
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        $emit=static function(string $event,array $data): void {
            echo 'event: '.$event."\ndata: ".AgentStore::encode($data)."\n\n";@flush();
        };
        try{\app\common\service\app\aigc_llm\agent\AgentStreamService::follow($tenant,$user,$id,$emit,static fn()=>!connection_aborted());}
        catch(\Throwable $e){\think\facade\Log::error('Home Agent stream: '.get_class($e));$emit('error',['message'=>'连接中断，正在重新连接；任务仍在后台执行']);}
        exit;
    }
    public function confirm(){return $this->handle(fn($t,$u)=>AgentExecutionService::confirm($t,$u,(int)$this->request->post('run_id',0),(string)$this->request->post('approval_hash','')));}
    public function stop(){return $this->handle(fn($t,$u)=>AgentExecutionService::stop($t,$u,(int)$this->request->post('run_id',0)));}
    public function runs(){return $this->handle(function($t,$u){
        $ids=AgentStore::owner('run',$t,$u)->where('session_id',(int)$this->request->get('session_id',0))->order('id','desc')->limit(30)->column('id');
        return array_map(fn($id)=>AgentExecutionService::detail($t,$u,(int)$id),$ids);
    });}
    public function artifacts(){return $this->handle(fn($t,$u)=>AgentArtifactService::lists($t,$u,(int)$this->request->get('session_id',0),(int)$this->request->get('before_id',0),$this->request->get('scope','')==='all',(string)$this->request->get('kind','')));}
    public function favorite(){return $this->handle(function($t,$u){AgentArtifactService::favorite($t,$u,(int)$this->request->post('id',0),(bool)\app\common\service\WorkspaceAssetService::normalizeFavorite($this->request->post('favorite',null,null)));return [];});}
    public function upload(){return $this->handle(function($t,$u){
        $file=$this->request->file('file');if(!$file||$file->getSize()>50*1024*1024)throw new \InvalidArgumentException('请选择50MB以内的素材');
        $mime=$file->getMime();$type=explode('/',$mime)[0];
        if(!in_array($type,['image','video','audio'],true))throw new \InvalidArgumentException('当前支持图片、视频和音频素材');
        return OpenAssets::upload($t,$u,$type);
    });}
}
