<?php

namespace app\common\service\app\aigc_canvas\agent\tools;

use app\common\service\app\aigc_canvas\AigcCanvasSkillService;
use app\common\service\app\aigc_canvas\agent\contracts\FunctionCallSchema;
use app\common\service\app\aigc_canvas\agent\contracts\ToolInterface;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;

final class RetrieveSkillsTool implements ToolInterface
{
    public function code(): string
    {
        return 'retrieve_skills';
    }

    public function schema(): array
    {
        return FunctionCallSchema::function($this->code(), 'Retrieve enabled canvas Agent skills for routing or comparison.', [
            'keyword' => ['type' => 'string'],
            'limit' => ['type' => 'number'],
        ]);
    }

    public function execute(AgentExecutionContext $context, array $arguments): array
    {
        $keyword = mb_strtolower(trim((string)($arguments['keyword'] ?? '')), 'UTF-8');
        $limit = max(1, min(3, (int)($arguments['limit'] ?? 3)));
        $skills = AigcCanvasSkillService::routerSkills($context->tenantId(), 80);
        if ($keyword !== '') {
            $skills = array_values(array_filter($skills, static function (array $skill) use ($keyword): bool {
                $haystack = mb_strtolower(implode(' ', [
                    (string)($skill['skill_key'] ?? ''),
                    (string)($skill['name'] ?? ''),
                    (string)($skill['description'] ?? ''),
                    (string)($skill['trigger_description'] ?? ''),
                ]), 'UTF-8');
                return str_contains($haystack, $keyword);
            }));
        }

        return [
            'tool_calls' => [[
                'tool_code' => $this->code(),
                'status' => 'success',
                'input' => $arguments,
                'output' => [
                    'skills' => array_slice(array_map(static fn(array $skill): array => [
                        'skill_key' => (string)($skill['skill_key'] ?? ''),
                        'name' => (string)($skill['name'] ?? ''),
                        'description' => (string)($skill['description'] ?? ''),
                        'skill_type' => (string)($skill['skill_type'] ?? ''),
                    ], $skills), 0, $limit),
                ],
            ]],
        ];
    }
}
