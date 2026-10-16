<?php
namespace app\api\controller\app\aigc_canvas;
use app\common\service\app\aigc_llm\agent\AgentWorkspaceScope;
/** Shares the durable conversation engine, with a mandatory owned canvas scope. */
class ConversationController extends \app\api\controller\app\aigc_llm\AgentController
{
    protected function workspaceScope(): array
    {
        return AgentWorkspaceScope::canvas((int)$this->request->tenantId,$this->userId,array_merge($this->request->get(),$this->request->post()));
    }
}
