<?php

namespace app\common\service\app\aigc_canvas\agent\agents;

use app\common\service\app\aigc_canvas\agent\contracts\AgentInterface;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;
use app\common\service\app\aigc_canvas\agent\planning\EcommerceDetailSectionPlanner;
use app\common\service\app\aigc_canvas\agent\runtime\AgentLlmGateway;
use app\common\service\app\aigc_canvas\agent\tools\AddElementTool;
use app\common\service\app\aigc_canvas\agent\tools\GenerateImageTool;
use app\common\service\app\aigc_canvas\agent\tools\GenerateVideoTool;

final class VisualAgent implements AgentInterface
{
    public function code(): string
    {
        return 'visual';
    }

    public function run(AgentExecutionContext $context): array
    {
        $master = $context->result('master');
        $route = $context->route();
        $isEcommerce = (string)($route['skill_key'] ?? $route['skill_code'] ?? '') === 'ecommerce_detail_page';
        $detailSections = EcommerceDetailSectionPlanner::normalizeSections((array)($route['slots']['detail_sections'] ?? []));
        $sectionCount = max(1, count($detailSections) ?: (int)($route['slots']['section_count'] ?? $route['slots']['quantity'] ?? 5));
        if ($isEcommerce) {
            $calls = self::ecommerceFallbackCalls($context, $sectionCount);
            return [
                'summary' => "已规划 {$sectionCount} 个独立详情页视觉区块。",
                'visual_prompt' => '',
                'function_calls' => $calls,
            ];
        }
        $tools = [(new AddElementTool())->schema(), (new GenerateImageTool())->schema(), (new GenerateVideoTool())->schema()];
        $llm = AgentLlmGateway::call($context, $this->code(), implode("\n", [
            'You are Visual Agent for an infinite design canvas.',
            'Use add_element for editable media placeholders and generate_image/generate_video for explicit media deliverables.',
            'Each media tool call must target one placeholder with target_element_id.',
            'Never create more media tasks than requested.',
        ]), [
            'task' => 'create_visual_plan_and_tool_calls',
            'user_request' => $context->request(),
            'route' => $route,
            'section_count' => $sectionCount,
            'copy_result' => $context->result('copy'),
            'media_config' => [
                'image' => $context->toolOptions('generate_image'),
                'video' => $context->toolOptions('generate_video'),
            ],
        ], $tools);
        $calls = (array)($llm['function_calls'] ?? []);
        if ($isEcommerce) {
            $calls = self::validEcommerceCalls($calls, $sectionCount);
            if (empty($calls)) {
                $calls = self::ecommerceFallbackCalls($context, $sectionCount);
            }
            return ['summary' => "已规划 {$sectionCount} 个详情页视觉区块。", 'visual_prompt' => '', 'function_calls' => $calls];
        }
        $calls = array_values(array_filter($calls, static fn($call) => in_array((string)($call['name'] ?? ''), ['add_element', 'generate_image', 'generate_video'], true)));
        if (!empty($calls)) {
            return ['summary' => '已完成主视觉规划。', 'visual_prompt' => '', 'function_calls' => array_slice($calls, 0, 24)];
        }
        $prompt = self::visualPrompt($context->request());
        $calls = [
            [
                'name' => 'add_element',
                'arguments' => [
                    'page_id' => 'page_1',
                    'element' => [
                        'id' => 'visual_slot',
                        'type' => 'image',
                        'x' => 780,
                        'y' => 120,
                        'width' => 560,
                        'height' => 420,
                        'content' => '主视觉生成区域',
                        'style' => ['fit' => 'cover'],
                    ],
                ],
            ],
            [
                'name' => 'generate_image',
                'arguments' => [
                    'prompt' => $prompt,
                    'quantity' => 1,
                    'ratio' => '16:9',
                ],
            ],
        ];
        if (!empty($master['needs_video'])) {
            $calls[] = [
                'name' => 'generate_video',
                'arguments' => [
                    'prompt' => $prompt . '，镜头缓慢推进，适合品牌发布会开场短片',
                    'duration' => 5,
                    'ratio' => '16:9',
                ],
            ];
        }
        return [
            'summary' => '已规划主视觉，并按当前默认模型提交媒体生成任务。',
            'visual_prompt' => $prompt,
            'function_calls' => $calls,
        ];
    }

    private static function validEcommerceCalls(array $calls, int $sectionCount): array
    {
        $allowed = array_values(array_filter($calls, static fn($call) => in_array((string)($call['name'] ?? ''), ['add_element', 'generate_image'], true)));
        $mediaCalls = array_values(array_filter($allowed, static fn($call) => (string)($call['name'] ?? '') === 'generate_image'));
        if (count($mediaCalls) !== $sectionCount) {
            return [];
        }
        foreach ($mediaCalls as $call) {
            if (empty($call['arguments']['target_element_id'])) {
                return [];
            }
        }
        return array_slice($allowed, 0, 24);
    }

    private static function ecommerceFallbackCalls(AgentExecutionContext $context, int $count): array
    {
        $route = $context->route();
        $slots = is_array($route['slots'] ?? null) ? $route['slots'] : [];
        $sections = EcommerceDetailSectionPlanner::normalizeSections((array)($slots['detail_sections'] ?? []));
        if (empty($sections)) {
            return [];
        }
        $ratio = (string)($context->toolOptions('generate_image')['ratio'] ?? $slots['ratio'] ?? '3:4');
        $references = array_values(array_filter(array_map(
            static fn(array $item): string => trim((string)($item['url'] ?? $item['uri'] ?? '')),
            array_filter((array)($context->context()['uploaded_references'] ?? []), 'is_array')
        )));
        $runId = max(0, (int)($route['agent_run_id'] ?? 0));
        $elementPrefix = $runId > 0 ? 'ecommerce_' . $runId . '_visual' : 'ecommerce_visual';
        $calls = [];
        foreach ($sections as $index => $section) {
            $elementId = sprintf('%s_%02d', $elementPrefix, $index + 1);
            $column = $index % 4;
            $row = intdiv($index, 4);
            $sectionKey = (string)($section['section_key'] ?? 'section_' . ($index + 1));
            $title = (string)($section['title'] ?? '详情区块' . ($index + 1));
            $prompt = trim((string)($section['image_prompt'] ?? ''));
            $calls[] = [
                'name' => 'add_element',
                'arguments' => [
                    'page_id' => 'ecommerce_page',
                    'element' => [
                        'id' => $elementId,
                        'type' => 'image',
                            'x' => $column * 364,
                            'y' => $row * 241,
                            'width' => 340,
                            'height' => 217,
                            'content' => '',
                            'prompt' => $prompt,
                            'title' => $title,
                            'metadata' => [
                                'status' => 'queued',
                                'prompt' => $prompt,
                                'content' => '',
                                'section_index' => (int)($section['section_index'] ?? $index + 1),
                                'section_key' => $sectionKey,
                                'target_element_id' => $elementId,
                                'reference_images' => $references,
                                'referenceImages' => array_map(
                                    static fn(string $url): array => ['url' => $url, 'role' => 'reference_image'],
                                    $references
                                ),
                                'agent_run_id' => (int)($route['agent_run_id'] ?? 0),
                            ],
                            'style' => ['fit' => 'cover'],
                        ],
                    ],
            ];
            $calls[] = [
                'name' => 'generate_image',
                'arguments' => [
                    'prompt' => $prompt,
                    'quantity' => 1,
                    'ratio' => (string)($section['ratio'] ?? '') ?: $ratio,
                    'target_element_id' => $elementId,
                    'section_key' => $sectionKey,
                ],
            ];
        }
        return $calls;
    }

    private static function visualPrompt(string $request): string
    {
        return trim($request) . '，高级商业设计，清晰层级，现代排版，主视觉构图，适合无限画布设计方案展示';
    }
}
