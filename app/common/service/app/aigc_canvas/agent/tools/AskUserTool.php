<?php

namespace app\common\service\app\aigc_canvas\agent\tools;

use app\common\service\app\aigc_canvas\agent\contracts\FunctionCallSchema;
use app\common\service\app\aigc_canvas\agent\contracts\ToolInterface;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

final class AskUserTool implements ToolInterface
{
    public function code(): string
    {
        return 'ask_user';
    }

    public function schema(): array
    {
        return FunctionCallSchema::function($this->code(), 'Ask the user for missing information before executing a tool chain.', [
            'question' => ['type' => 'string'],
            'missing_slots' => ['type' => 'array'],
            'reason' => ['type' => 'string'],
        ], ['question']);
    }

    public function execute(AgentExecutionContext $context, array $arguments): array
    {
        $question = trim((string)($arguments['question'] ?? ''));
        if ($question === '') {
            $question = '请补充必要信息后我再继续。';
        }
        $emit = $context->emit();
        if (is_callable($emit)) {
            $emit('agent.clarification.request', [
                'question' => $question,
                'missing_slots' => is_array($arguments['missing_slots'] ?? null) ? $arguments['missing_slots'] : [],
                'reason' => (string)($arguments['reason'] ?? ''),
            ]);
        }

        return [
            'tool_calls' => [[
                'tool_code' => $this->code(),
                'status' => 'success',
                'input' => $arguments,
                'output' => ['question' => $question],
            ]],
        ];
    }
}
