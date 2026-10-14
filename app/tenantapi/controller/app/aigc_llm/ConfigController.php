<?php

namespace app\tenantapi\controller\app\aigc_llm;

use app\common\service\app\aigc_llm\AigcLlmService;
use app\tenantapi\controller\BaseAdminController;

class ConfigController extends BaseAdminController
{
    public function detail()
    {
        return $this->success('获取成功', AigcLlmService::config($this->tenantId)+['agent_text_options'=>\app\common\service\app\aigc_llm\agent\AgentComposerSettings::textOptions($this->tenantId),'skill_configs'=>\app\common\service\app\aigc_llm\agent\AgentSkillConfigService::detail($this->tenantId)]);
    }

    public function setup()
    {
        try {
            AigcLlmService::saveConfig($this->tenantId, $this->request->post());
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success('保存成功', [], 1, 1);
    }
}

