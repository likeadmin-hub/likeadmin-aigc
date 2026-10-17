<?php
namespace app\api\controller\app\aigc_llm;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_llm\agent\{AgentStore,AgentExecutionService,AgentCapabilityCatalog,AgentArtifactService,AgentWorkspaceScope};
use app\common\service\open_platform\{OpenAssets,OpenException};

class AgentController extends BaseApiController
{
    protected function workspaceScope(): array { return []; }
    /** Reject ambiguous scope identifiers before authorization or project reads. */
    protected function requestParameters(): array
    {
        $get=$this->request->get();$post=$this->request->post();
        foreach(['project_id','page_id','session_id','run_id','step_id','id'] as $key){
            foreach([$get,$post] as $source)if(array_key_exists($key,$source)&&!is_scalar($source[$key]))throw new \InvalidArgumentException('请求标识无效');
            if(array_key_exists($key,$get)&&array_key_exists($key,$post)&&(string)$get[$key] !== (string)$post[$key])throw new \InvalidArgumentException('请求标识不一致，请刷新后重试');
        }
        return array_merge($get,$post);
    }
    private function scoped(callable $call)
    {
        $params=$this->requestParameters();
        return AgentWorkspaceScope::within($this->workspaceScope(),function()use($call,$params){
            $t=(int)$this->request->tenantId;$u=$this->userId;
            AgentStore::access($t,$u);
            if(!empty($params['run_id']))AgentWorkspaceScope::assertRun($t,$u,(int)$params['run_id']);
            if(!empty($params['session_id']))AgentWorkspaceScope::assertSession($t,$u,(int)$params['session_id']);
            if(strtolower($this->request->action())==='favorite'){
                $artifact=AgentWorkspaceScope::artifacts(AgentStore::owner('artifact',$t,$u),$t,$u)->where('id',(int)($params['id']??0))->find();
                if(!$artifact)throw new \InvalidArgumentException('作品不属于当前工作区');
            }
            return $call($t,$u);
        });
    }
    protected function handle(callable $call)
    {
        try{
            return $this->success('', $this->scoped($call));
        }catch(\InvalidArgumentException|OpenException $e){return $this->fail($e->getMessage());}
        catch(\Throwable $e){\think\facade\Log::error('Home Agent API: '.get_class($e));return $this->fail('Agent 暂不可用，请检查服务部署后重试');}
    }
    public function sessions(){return $this->handle(fn($t,$u)=>\app\common\service\app\aigc_llm\AigcLlmService::sessionLists($t,$u));}
    public function messages(){return $this->handle(fn($t,$u)=>\app\common\service\app\aigc_llm\AigcLlmService::messageLists($t,$u,(int)$this->request->get('session_id',0)));}
    public function renameSession(){return $this->handle(function($t,$u){\app\common\service\app\aigc_llm\AigcLlmService::renameSession($t,$u,(int)$this->request->post('session_id',0),(string)$this->request->post('title',''));return [];});}
    public function deleteSession(){return $this->handle(function($t,$u){
        $id=(int)$this->request->post('session_id',0);
        if(AgentStore::owner('run',$t,$u)->where('session_id',$id)->whereIn('status',['queued','running','waiting_tasks'])->count())throw new \InvalidArgumentException('请先停止并等待任务完成');
        \app\common\service\app\aigc_llm\AigcLlmService::deleteSession($t,$u,$id);return [];
    });}
    public function config(){return $this->handle(function($t,$u){
        $tools=AgentCapabilityCatalog::all($t,$u);$displays=[];
        if(AgentWorkspaceScope::app()!=='aigc_canvas')foreach(array_unique(array_merge(['aigc_llm'],array_keys($tools))) as $code)$displays[$code]=\app\common\service\app\AppDisplayConfigService::skillPreview($t,$code==='speech_synthesis'?'aigc_llm':$code);
        return ['preferences'=>AgentStore::preferences($t,$u),'models'=>AgentCapabilityCatalog::models($t),'tools'=>array_values($tools),'skill_displays'=>$displays,'skill_configs'=>\app\common\service\app\aigc_llm\agent\AgentSkillConfigService::forTools($t,array_keys($tools))];
    });}
    public function subjects(){return $this->handle(fn($t,$u)=>\app\common\service\app\aigc_llm\agent\AgentSubjectReferences::lists($t,$u,$this->request->get()));}
    public function preferences(){return $this->handle(fn($t,$u)=>AgentStore::preferences($t,$u,$this->request->post()));}
    public function submit(){return $this->handle(fn($t,$u)=>AgentExecutionService::submit($t,$u,$this->request->post()));}
    public function detail(){return $this->handle(fn($t,$u)=>AgentExecutionService::detail($t,$u,(int)$this->request->get('run_id',0)));}
    public function events(){return $this->handle(fn($t,$u)=>AgentExecutionService::events($t,$u,(int)$this->request->get('run_id',0),(int)$this->request->get('after',0)));}
    public function stream()
    {
        $tenant=(int)$this->request->tenantId;$user=$this->userId;$id=(int)$this->request->post('run_id',0);
        // Authenticate and verify ownership before starting a long-lived response.
        try{$this->scoped(fn()=>AgentWorkspaceScope::assertRun($tenant,$user,$id));}
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
    public function planEditor(){return $this->handle(fn($t,$u)=>\app\common\service\app\aigc_llm\agent\AgentPlanService::editor($t,$u,(int)$this->request->get('run_id',0),(int)$this->request->get('step_id',0)));}
    public function editPlan(){return $this->handle(fn($t,$u)=>\app\common\service\app\aigc_llm\agent\AgentPlanService::edit($t,$u,(int)$this->request->post('run_id',0),(int)$this->request->post('step_id',0),(string)$this->request->post('approval_hash',''),(array)$this->request->post('changes',[]),(bool)$this->request->post('remove',false)));}
    public function retryStep(){return $this->handle(fn($t,$u)=>\app\common\service\app\aigc_llm\agent\AgentPlanService::retry($t,$u,(int)$this->request->post('run_id',0),(int)$this->request->post('step_id',0),(string)$this->request->post('version','')));}
    public function checkStep(){return $this->handle(fn($t,$u)=>\app\common\service\app\aigc_llm\agent\AgentPlanService::check($t,$u,(int)$this->request->post('run_id',0),(int)$this->request->post('step_id',0),(string)$this->request->post('version','')));}
    public function resumeReading(){return $this->handle(fn($t,$u)=>\app\common\service\app\aigc_llm\agent\AgentLongReadService::resume($t,$u,(int)$this->request->post('run_id',0)));}
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
        if(strtolower($file->getOriginalExtension())==='pdf'){
            if($file->getSize()>10*1024*1024||$mime!=='application/pdf')throw new \InvalidArgumentException('请选择10MB以内的有效 PDF');
            $type='file';
        }elseif(\app\common\service\app\aigc_llm\agent\AgentDocumentReader::supports($file->getOriginalName())){
            \app\common\service\app\aigc_llm\agent\AgentDocumentReader::extract($file->getPathname(),$file->getOriginalName());
            $type='file';
        }elseif(!in_array($type,['image','video','audio'],true))throw new \InvalidArgumentException('请添加图片、视频、音频或 PDF、TXT、Markdown、CSV、DOCX 文档');
        return OpenAssets::upload($t,$u,$type);
    });}
}
