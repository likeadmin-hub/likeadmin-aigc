<?php
declare(strict_types=1);
namespace app\tenantapi\controller\decorate;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\decorate\agent\DecorationAgentService as Agent;
use app\common\cache\TenantAdminAuthCache;

final class AgentController extends BaseAdminController
{
    /** New routes must not inherit the middleware's unknown-route allow rule. */
    private function access(bool $write=false,bool $create=false): void
    {
        if($this->tenantId<=0||$this->adminId<=0)throw new \RuntimeException('租户后台身份无效');
        if((int)($this->adminInfo['root']??0)===1)return;
        $uris=array_map('strtolower',(array)(new TenantAdminAuthCache($this->adminId))->getAdminUri());
        $required=$write?['decorate.page/save']:['decorate.page/detail','decorate.template/detail','decorate.page/save'];
        if(!array_intersect($required,$uris)||($create&&!in_array('decorate.page/add',$uris,true)))throw new \RuntimeException('没有对应装修操作权限');
    }
    private function call(string $method,bool $write=false): mixed
    {
        try{
            $p=$this->request->param();
            if(in_array($method,['extractionOptions','quoteDesignAsset','extractDesignAsset'],true))$p=$this->request->param('',null,null);
            // trim() converts native JSON booleans to strings. Preserve this strict opt-in's wire type.
            if(array_key_exists('design_first',$p))$p['design_first']=$this->request->param('design_first',null,null);
            if($method==='reviewDesign'&&array_key_exists('model_selection',$p))$p['model_selection']=$this->request->param('model_selection',null,null);
            if($method==='reviewDesign'&&array_key_exists('review_notes',$p))$p['review_notes']=$this->request->param('review_notes',null,null);
            if(in_array($method,['quoteDesignAsset','extractDesignAsset'],true)&&array_key_exists('authorize_generation',$p))$p['authorize_generation']=$this->request->param('authorize_generation',null,null);
            if($method==='apply'&&!empty($p['candidate_id'])){$candidate=Agent::candidate($this->tenantId,$this->adminId,(int)$p['candidate_id']);if($candidate['new_page']??false)$p['scope']='new_page';}$this->access($write,($p['scope']??'')==='new_page'&&in_array($method,['start','apply'],true));
            if($method==='bootstrap')return $this->success('获取成功',Agent::bootstrap($this->tenantId,$this->adminId,$p));
            if($method==='session')$result=Agent::session($this->tenantId,$this->adminId,(int)($p['session_id']??$p['id']??0));
            elseif($method==='run')$result=Agent::run($this->tenantId,$this->adminId,(int)($p['run_id']??$p['id']??0));
            elseif($method==='events')$result=Agent::events($this->tenantId,$this->adminId,(int)($p['run_id']??0),(int)($p['after']??$p['after_id']??0));
            elseif($method==='cancel')$result=Agent::cancel($this->tenantId,$this->adminId,(int)($p['run_id']??0));
            elseif(in_array($method,['candidate','discard'],true))$result=Agent::$method($this->tenantId,$this->adminId,(int)($p['candidate_id']??0));
            else $result=Agent::$method($this->tenantId,$this->adminId,$p);
            return $this->success('操作成功',$result);
        }catch(\Throwable $e){return $this->fail($e->getMessage(),['message'=>$e->getMessage()]+($e instanceof \app\common\service\decorate\agent\DecorationAgentDesignExtractionRejected?['submission_state'=>'not_created']:[]));}
    }
    public function bootstrap(){return $this->call('bootstrap');}
    public function createSession(){return $this->call('createSession',true);}
    public function sessions(){return $this->call('sessions');}
    public function session(){return $this->call('session');}
    public function quote(){return $this->call('quote',true);}
    public function start(){return $this->call('start',true);}
    public function continue(){return $this->call('start',true);}
    public function run(){return $this->call('run');}
    public function stream()
    {
        try {
            if (!$this->request->isGet()) throw new \RuntimeException('进度连接仅支持GET');
            $this->access();
            $id=filter_var($this->request->get('run_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $after=filter_var($this->request->get('after',0),FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if ($id===false || $after===false) throw new \RuntimeException('装修进度编号无效');
            $tenant=$this->tenantId;$admin=$this->adminId;
            // Authenticate, authorize and check ownership before returning streaming headers.
            $initial=Agent::run($tenant,$admin,$id);
            return new \app\common\service\open_platform\OpenStreamResponse(function()use($tenant,$admin,$id,$after,$initial){
                @ini_set('zlib.output_compression','0');
                while (ob_get_level()>0) { if (!@ob_end_flush()) break; }
                $stream=new \app\common\service\decorate\agent\DecorationAgentStream(
                    fn()=>Agent::run($tenant,$admin,$id),
                    function(string $frame): void { echo $frame;flush(); }
                );
                $stream->send($initial,$after);
            },['Content-Type'=>'text/event-stream; charset=utf-8','Cache-Control'=>'no-cache, no-transform','X-Accel-Buffering'=>'no']);
        } catch (\Throwable $e) { return $this->fail($e->getMessage(),['message'=>$e->getMessage()]); }
    }
    public function events(){return $this->call('events');}
    public function cancel(){return $this->call('cancel',true);}
    public function candidate(){return $this->call('candidate');}
    public function candidateCaptureAssets(){if(!$this->request->isPost())return $this->fail('截图素材读取仅支持POST');return $this->call('candidateCaptureAssets');}
    public function discard(){return $this->call('discard',true);}
    public function apply(){return $this->call('apply',true);}
    public function rollback(){return $this->call('rollback',true);}
    public function reviewDesign(){return $this->call('reviewDesign',true);}
    public function extractionOptions(){return $this->call('extractionOptions');}
    public function quoteDesignAsset(){return $this->call('quoteDesignAsset',true);}
    public function extractDesignAsset(){return $this->call('extractDesignAsset',true);}
    public function confirmDesign(){return $this->call('confirmDesign',true);}
    public function retryImage(){return $this->call('retryImage',true);}
    public function replaceImage(){return $this->call('replaceImage',true);}
}
