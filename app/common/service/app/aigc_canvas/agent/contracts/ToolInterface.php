<?php

namespace app\common\service\app\aigc_canvas\agent\contracts;

use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

interface ToolInterface
{
    public function code(): string;

    public function schema(): array;

    public function execute(AgentExecutionContext $context, array $arguments): array;
}
