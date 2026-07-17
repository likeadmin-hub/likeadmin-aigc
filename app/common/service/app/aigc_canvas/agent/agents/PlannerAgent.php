<?php

namespace app\common\service\app\aigc_canvas\agent\agents;

use app\common\service\app\aigc_canvas\agent\contracts\AgentInterface;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;
use app\common\service\app\aigc_canvas\agent\runtime\AgentLlmGateway;
use app\common\service\app\aigc_canvas\agent\tools\AddElementTool;
use app\common\service\app\aigc_canvas\agent\tools\CreatePageTool;

final class PlannerAgent implements AgentInterface
{
    public function code(): string
    {
        return 'planner';
    }

    public function run(AgentExecutionContext $context): array
    {
        $title = self::titleFromRequest($context->request());
        $route = $context->route();
        $isEcommerce = (string)($route['skill_key'] ?? $route['skill_code'] ?? '') === 'ecommerce_detail_page';
        $detailSections = is_array($route['slots']['detail_sections'] ?? null) ? $route['slots']['detail_sections'] : [];
        $sectionCount = max(1, count($detailSections) ?: (int)($route['slots']['section_count'] ?? $route['slots']['quantity'] ?? 5));
        if ($isEcommerce) {
            return self::ecommerceFallback($title, $sectionCount);
        }
        $tools = [(new CreatePageTool())->schema(), (new AddElementTool())->schema()];
        $llm = AgentLlmGateway::call($context, $this->code(), implode("\n", [
            'You are Planner Agent for an infinite design canvas.',
            'Use only the supplied functions. Create one page before adding elements.',
            'Return editable layout elements, never provider calls.',
            'For ecommerce detail pages use a 750px wide vertical page and preserve requested section count.',
        ]), [
            'task' => 'plan_json_canvas_layout',
            'user_request' => $context->request(),
            'route' => $route,
            'section_count' => $sectionCount,
            'canvas_context' => $context->context(),
        ], $tools);
        $calls = self::validPlannerCalls((array)($llm['function_calls'] ?? []));
        if (!empty($calls)) {
            return ['summary' => '已完成页面结构规划。', 'function_calls' => $calls];
        }
        return [
            'summary' => '已规划一个 1440x900 的设计画布页面，包含标题、核心信息、流程/模块和视觉区域。',
            'function_calls' => [
                [
                    'name' => 'create_page',
                    'arguments' => [
                        'page_id' => 'page_1',
                        'title' => $title,
                        'width' => 1440,
                        'height' => 900,
                        'background' => '#ffffff',
                    ],
                ],
                [
                    'name' => 'add_element',
                    'arguments' => [
                        'page_id' => 'page_1',
                        'element' => [
                            'id' => 'design_title',
                            'type' => 'text',
                            'x' => 80,
                            'y' => 80,
                            'width' => 680,
                            'height' => 110,
                            'content' => $title,
                            'style' => ['fontSize' => 34, 'fontWeight' => 700],
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function ecommerceFallback(string $title, int $sectionCount): array
    {
        $height = max(900, $sectionCount * 900);
        return [
            'summary' => "已规划 {$sectionCount} 个电商详情区块。",
            'function_calls' => [[
                'name' => 'create_page',
                'arguments' => ['page_id' => 'ecommerce_page', 'title' => $title, 'width' => 750, 'height' => $height, 'background' => '#f7f7f7'],
            ]],
        ];
    }

    private static function validPlannerCalls(array $calls): array
    {
        $result = [];
        $hasPage = false;
        foreach ($calls as $call) {
            $name = (string)($call['name'] ?? '');
            if (!in_array($name, ['create_page', 'add_element'], true)) {
                continue;
            }
            if ($name === 'create_page') {
                if ($hasPage) {
                    continue;
                }
                $hasPage = true;
            }
            $result[] = $call;
        }
        return $hasPage ? array_slice($result, 0, 40) : [];
    }

    private static function titleFromRequest(string $request): string
    {
        $request = trim($request);
        if ($request === '') {
            return 'AI Design Page';
        }
        $request = preg_replace('/^(帮我|请|给我|生成|做|制作|设计|创建)+/u', '', $request) ?? $request;
        return mb_substr(trim($request) ?: 'AI Design Page', 0, 36, 'UTF-8');
    }
}
