<?php

namespace app\common\service\app\aigc_canvas\agent\agents;

use app\common\service\app\aigc_canvas\agent\contracts\AgentInterface;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;
use app\common\service\app\aigc_canvas\agent\runtime\AgentLlmGateway;
use app\common\service\app\aigc_canvas\agent\tools\AddElementTool;
use app\common\service\app\aigc_llm\AigcLlmService;
use Exception;

final class CopyAgent implements AgentInterface
{
    public function code(): string
    {
        return 'copy';
    }

    public function run(AgentExecutionContext $context): array
    {
        $route = $context->route();
        $isEcommerce = (string)($route['skill_key'] ?? $route['skill_code'] ?? '') === 'ecommerce_detail_page';
        $detailSections = is_array($route['slots']['detail_sections'] ?? null) ? $route['slots']['detail_sections'] : [];
        $sectionCount = max(1, count($detailSections) ?: (int)($route['slots']['section_count'] ?? $route['slots']['quantity'] ?? 5));
        if ($isEcommerce) {
            $copy = self::copyFromDetailSections($detailSections, $sectionCount);
            return [
                'summary' => "已生成 {$sectionCount} 个详情页文案区块。",
                'copy' => $copy,
                'function_calls' => [],
            ];
        }
        $llm = AgentLlmGateway::call($context, $this->code(), implode("\n", [
            'You are Copy Agent for editable JSON Canvas designs.',
            'Use add_element to place concise Chinese copy.',
            'Do not call media tools. Do not invent product specifications.',
            'For ecommerce detail pages create copy for the requested number of vertical sections.',
        ]), [
            'task' => 'write_canvas_copy',
            'user_request' => $context->request(),
            'route' => $route,
            'section_count' => $sectionCount,
            'planner_result' => $context->result('planner'),
        ], [(new AddElementTool())->schema()]);
        $calls = array_values(array_filter((array)($llm['function_calls'] ?? []), static fn($call) => (string)($call['name'] ?? '') === 'add_element'));
        if (!empty($calls)) {
            return ['summary' => '已生成设计文案模块。', 'copy' => [], 'function_calls' => array_slice($calls, 0, 24)];
        }
        $copy = $isEcommerce ? self::ecommerceFallbackCopy($context, $sectionCount) : self::fallbackCopy($context->request());
        $functionCalls = $isEcommerce ? self::ecommerceFallbackCalls($copy) : [[
            'name' => 'add_element',
            'arguments' => [
                'page_id' => 'page_1',
                'element' => [
                    'id' => 'design_brief',
                    'type' => 'text',
                    'x' => 80,
                    'y' => 220,
                    'width' => 620,
                    'height' => 420,
                    'content' => implode("\n\n", $copy),
                    'style' => ['fontSize' => 16, 'lineHeight' => 1.8],
                ],
            ],
        ]];
        return [
            'summary' => '已生成设计文案模块。',
            'copy' => $copy,
            'function_calls' => $functionCalls,
        ];
    }

    private static function ecommerceFallbackCopy(AgentExecutionContext $context, int $count): array
    {
        $slots = $context->route()['slots'] ?? [];
        $product = trim((string)($slots['product_info'] ?? '商品')) ?: '商品';
        $sellingPoints = $slots['selling_points'] ?? [];
        if (is_string($sellingPoints)) {
            $sellingPoints = preg_split('/[，,、;；\n]+/u', $sellingPoints) ?: [];
        }
        $sellingPoints = array_values(array_filter(array_map('trim', (array)$sellingPoints)));
        $names = ['首屏主视觉', '核心卖点', '痛点对比', '产地与信任', '工艺与材质', '核心体验', '差异化优势', '产品细节', '使用场景', '品牌背书', '规格参数', '购买引导'];
        $copy = [];
        for ($index = 0; $index < $count; $index++) {
            $point = $sellingPoints[$index % max(1, count($sellingPoints))] ?? '突出产品价值与真实使用体验';
            $name = $names[$index] ?? '详情区块' . ($index + 1);
            $copy[] = $name . "：{$product}，{$point}";
        }
        return $copy;
    }

    private static function copyFromDetailSections(array $sections, int $count): array
    {
        $copy = [];
        foreach ($sections as $index => $section) {
            if (!is_array($section)) {
                continue;
            }
            $title = trim((string)($section['title'] ?? '详情区块' . ($index + 1)));
            $content = $section['copy_content'] ?? [];
            if (is_string($content)) {
                $text = trim($content);
            } else {
                $content = is_array($content) ? $content : [];
                $parts = array_values(array_filter([
                    trim((string)($content['headline'] ?? '')),
                    trim((string)($content['subheadline'] ?? '')),
                    trim((string)($content['raw'] ?? '')),
                ]));
                $text = implode("\n", array_values(array_unique($parts)));
            }
            $copy[] = trim($title . ($text !== '' ? "\n" . $text : ''));
        }
        if (!empty($copy)) {
            return $copy;
        }
        return array_map(static fn(int $index): string => '详情区块' . ($index + 1), range(0, max(0, $count - 1)));
    }

    private static function ecommerceFallbackCalls(array $copy): array
    {
        $calls = [];
        foreach ($copy as $index => $content) {
            $calls[] = [
                'name' => 'add_element',
                'arguments' => [
                    'page_id' => 'ecommerce_page',
                    'element' => [
                        'id' => sprintf('ecommerce_copy_%02d', $index + 1),
                        'type' => 'text',
                        'x' => 40,
                        'y' => $index * 900 + 54,
                        'width' => 670,
                        'height' => 150,
                        'content' => $content,
                        'style' => ['fontSize' => 28, 'fontWeight' => 700, 'lineHeight' => 1.45, 'color' => '#111111'],
                    ],
                ],
            ];
        }
        return $calls;
    }

    private static function generateCopy(AgentExecutionContext $context): array
    {
        if (!self::shouldUseLlmCopy($context)) {
            return self::fallbackCopy($context->request());
        }
        try {
            $result = AigcLlmService::generateText($context->tenantId(), $context->userId(), [
                'content' => json_encode([
                    'task' => 'write_design_copy',
                    'language' => 'Simplified Chinese',
                    'rules' => [
                        'Write concise copy for a design canvas.',
                        'Return JSON only: {"sections":["..."]}.',
                        'Use 4 to 6 sections. Each section should be short and useful.',
                    ],
                    'user_request' => $context->request(),
                    'memory' => $context->memory(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'system_prompt' => 'You are Copy Agent for a design canvas. Output compact JSON only.',
                'max_tokens' => 1200,
                'source_app_code' => 'aigc_canvas',
                'source_type' => 'design_agent_copy',
            ]);
            $json = self::parseJson((string)($result['content'] ?? ''));
            $sections = array_values(array_filter(array_map('strval', (array)($json['sections'] ?? []))));
            if (!empty($sections)) {
                return array_slice($sections, 0, 6);
            }
        } catch (Exception) {
            // Use deterministic fallback copy below.
        }
        return self::fallbackCopy($context->request());
    }

    private static function shouldUseLlmCopy(AgentExecutionContext $context): bool
    {
        $route = $context->route();
        return !empty($route['deep_copy']) || !empty($route['use_llm_copy']);
    }

    private static function fallbackCopy(string $request): array
    {
        $topic = mb_substr(trim($request) ?: '设计任务', 0, 42, 'UTF-8');
        return [
            '项目目标：围绕“' . $topic . '”建立清晰的设计叙事和页面结构。',
            '核心信息：突出主题、关键卖点、流程节点和最终交付结果。',
            '流程建议：预热沟通、发布会开场、产品亮点介绍、核心演示、互动答疑、收尾转化。',
            '视觉方向：使用明确层级、充足留白、主视觉区域和模块化信息布局提升可读性。',
            '下一步：确认目标受众、使用场景、尺寸比例和必要素材后，可继续细化完整设计稿。',
        ];
    }

    private static function parseJson(string $content): array
    {
        $text = trim($content);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $json = json_decode($text, true);
        if (!is_array($json) && preg_match('/\{.*\}/s', $text, $match)) {
            $json = json_decode($match[0], true);
        }
        return is_array($json) ? $json : [];
    }
}
