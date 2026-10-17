<?php
namespace app\api\controller\app\aigc_canvas;
use app\common\service\app\aigc_llm\agent\AgentWorkspaceScope;
/** Shares the durable conversation engine, with a mandatory owned canvas scope. */
class ConversationController extends \app\api\controller\app\aigc_llm\AgentController
{
    public function subjects(){return $this->handle(fn($t,$u)=>\app\common\service\app\aigc_canvas\CanvasProjectReferenceService::lists($t,$u,$this->request->get()));}
    public function sourceSubjects(){return $this->handle(fn($t,$u)=>\app\common\service\app\aigc_canvas\CanvasProjectReferenceService::sourceSubjects($t,$u,$this->request->get()));}
    public function importSubject(){return $this->handle(fn($t,$u)=>\app\common\service\app\aigc_canvas\CanvasProjectReferenceService::importSubject($t,$u,$this->request->post()));}
    public function start()
    {
        return $this->handle(fn($tenant,$user)=>\app\common\service\app\aigc_canvas\CanvasConversationService::start($tenant,$user,$this->request->post()));
    }
    protected function workspaceScope(): array
    {
        $params=$this->requestParameters();
        if(empty($params['project_id'])&&in_array(strtolower($this->request->action()),['config','preferences','subjects','upload','start'],true))return ['app'=>'aigc_canvas','project_id'=>0,'page_id'=>'entry'];
        return AgentWorkspaceScope::canvas((int)$this->request->tenantId,$this->userId,$this->requestParameters());
    }
}
