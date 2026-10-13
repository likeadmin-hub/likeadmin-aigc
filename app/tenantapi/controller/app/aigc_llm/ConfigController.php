<?php

namespace app\tenantapi\controller\app\aigc_llm;

use app\common\service\app\aigc_llm\AigcLlmService;
use app\tenantapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcLlmService::config($this->tenantId)+['skill_configs'=>\app\common\service\app\aigc_llm\agent\AgentSkillConfigService::detail($this->tenantId)]);
    }

    public function setup()
    {
        AigcLlmService::saveConfig($this->tenantId, $this->request->post());
        return $this->success('保存成功', [], 1, 1);
    }
}

