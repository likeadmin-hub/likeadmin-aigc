<?php
namespace app\api\controller\app\aigc_canvas;
use app\common\service\app\aigc_llm\agent\AgentWorkspaceScope;
/** Shares the durable conversation engine, with a mandatory owned canvas scope. */
class ConversationController extends \app\api\controller\app\aigc_llm\AgentController
{
    public function start()
    {
        return $this->handle(fn($tenant,$user)=>\app\common\service\app\aigc_canvas\CanvasConversationService::start($tenant,$user,$this->request->post()));
    }
    protected function workspaceScope(): array
    {
        $params=array_merge($this->request->get(),$this->request->post());
        if(empty($params['project_id'])&&in_array(strtolower($this->request->action()),['config','preferences','subjects','upload','start'],true))return ['app'=>'aigc_canvas','project_id'=>0,'page_id'=>'entry'];
        return AgentWorkspaceScope::canvas((int)$this->request->tenantId,$this->userId,array_merge($this->request->get(),$this->request->post()));
    }
}
