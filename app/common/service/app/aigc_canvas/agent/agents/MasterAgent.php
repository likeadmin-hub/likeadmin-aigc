<?php

namespace app\common\service\app\aigc_canvas\agent\agents;

use app\common\service\app\aigc_canvas\agent\contracts\AgentInterface;
use app\common\service\app\aigc_canvas\agent\orchestrator\AgentExecutionContext;
use app\common\service\app\aigc_canvas\agent\runtime\AgentLlmGateway;

final class MasterAgent implements AgentInterface
{
    public function code(): string
    {
        return 'master';
    }

    public function run(AgentExecutionContext $context): array
    {
        $request = $context->request();
        $route = $context->route();
        $fallback = self::fallbackPlan($request, $route);
        $decision = AgentLlmGateway::json($context, $this->code(), implode("\n", [
            'You are the Master Agent of an AI design workspace.',
            'Return compact JSON only. Do not expose reasoning.',
            'Allowed agents: planner, copy, visual, canvas.',
            'Use visual only for explicit image/video/design deliverables.',
            'Use canvas only when the result must be inserted into JSON Canvas.',
        ]), [
            'task' => 'plan_agent_execution',
            'user_request' => $request,
            'resolved_route' => $route,
            'memory' => $context->memory(),
            'output_schema' => [
                'goal' => 'string',
                'intent' => 'chat|planning|copywriting|generate_image|generate_video|design_canvas|skill_execution',
                'agents' => [['agent' => 'planner|copy|visual|canvas', 'objective' => 'string']],
                'needs_visual' => 'boolean',
                'needs_video' => 'boolean',
            ],
        ]);
        $agents = self::normalizeAgents((array)($decision['agents'] ?? []));
        if (empty($agents)) {
            return $fallback;
        }
        $isEcommerce = (string)($route['skill_key'] ?? $route['skill_code'] ?? '') === 'ecommerce_detail_page';
        if ($isEcommerce) {
            $byCode = array_column($agents, null, 'agent');
            foreach ($fallback['agents'] as $requiredAgent) {
                $byCode[(string)$requiredAgent['agent']] = $byCode[(string)$requiredAgent['agent']] ?? $requiredAgent;
            }
            $agents = self::normalizeAgents(array_values($byCode));
        }
        return [
            'goal' => trim((string)($decision['goal'] ?? $request)) ?: $request,
            'intent' => (string)($decision['intent'] ?? $route['intent'] ?? 'design_canvas'),
            'agents' => $agents,
            'needs_visual' => in_array('visual', array_column($agents, 'agent'), true),
            'needs_video' => !empty($decision['needs_video']) || self::needsVideo($request),
        ];
    }

    private static function fallbackPlan(string $request, array $route): array
    {
        $isEcommerce = (string)($route['skill_key'] ?? $route['skill_code'] ?? '') === 'ecommerce_detail_page';
        $needsVisual = $isEcommerce || self::needsVisual($request);
        return [
            'goal' => $request,
            'intent' => (string)($route['intent'] ?? ($isEcommerce ? 'skill_execution' : 'design_canvas')),
            'agents' => array_values(array_filter([
                ['agent' => 'planner', 'objective' => $isEcommerce ? 'Plan the ecommerce detail page sections' : 'Create page structure'],
                ['agent' => 'copy', 'objective' => $isEcommerce ? 'Write section copy from product selling points' : 'Write design copy'],
                $needsVisual ? ['agent' => 'visual', 'objective' => 'Create visual elements and media tasks'] : null,
                ['agent' => 'canvas', 'objective' => 'Assemble JSON Canvas'],
            ])),
            'needs_visual' => $needsVisual,
            'needs_video' => self::needsVideo($request),
        ];
    }

    private static function normalizeAgents(array $items): array
    {
        $allowed = ['planner', 'copy', 'visual', 'canvas'];
        $result = [];
        foreach (array_slice($items, 0, 8) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $agent = (string)($item['agent'] ?? '');
            if (!in_array($agent, $allowed, true) || isset($result[$agent])) {
                continue;
            }
            $result[$agent] = ['agent' => $agent, 'objective' => mb_substr((string)($item['objective'] ?? ''), 0, 240, 'UTF-8')];
        }
        if (!empty($result) && !isset($result['canvas'])) {
            $result['canvas'] = ['agent' => 'canvas', 'objective' => 'Assemble JSON Canvas'];
        }
        $ordered = [];
        foreach (['planner', 'copy', 'visual', 'canvas'] as $agent) {
            if (isset($result[$agent])) {
                $ordered[] = $result[$agent];
            }
        }
        return $ordered;
    }

    private static function needsVisual(string $request): bool
    {
        $text = mb_strtolower($request, 'UTF-8');
        foreach (['主视觉', '海报', '图片', '图像', '视觉', '封面', 'kv', 'image', 'poster', 'visual'] as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }
        return false;
    }

    private static function needsVideo(string $request): bool
    {
        $text = mb_strtolower($request, 'UTF-8');
        foreach (['视频', '短片', '动画', 'video'] as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }
        return false;
    }
}
