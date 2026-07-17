<?php

namespace app\common\service\app\aigc_canvas\agent\contracts;

use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

interface AgentInterface
{
    public function code(): string;

    public function run(AgentExecutionContext $context): array;
}
