<?php

namespace app\common\service\app\aigc_canvas\agent\onboarding;

use app\common\service\app\aigc_canvas\AigcCanvasService;
use app\common\service\app\aigc_canvas\AigcCanvasSkillService;
use app\common\service\app\aigc_canvas\agent\runtime\AgentResponseProtocol;

class CanvasAgentOnboardingService
{
    public static function detail(int $tenantId, int $userId, array $params = []): array
    {
        $config = AigcCanvasService::onboardingConfig($tenantId);
        $skills = AigcCanvasSkillService::usable($tenantId, ['limit' => 80]);
        $capabilities = self::capabilitiesFromSkills($skills);
        $capabilities = self::filterCapabilities($capabilities, (array)($config['capability_skill_keys'] ?? []));
        if ($capabilities === []) {
            $capabilities = self::defaultCapabilities();
        }

        $quickPrompts = (array)($config['quick_prompts'] ?? []);
        if ($quickPrompts === []) {
            $quickPrompts = self::quickPrompts($capabilities);
        }

        $payload = [
            'type' => 'onboarding',
            'agent_name' => (string)($config['agent_name'] ?? 'Canvas Agent'),
            'welcome_text' => (string)($config['welcome_text'] ?? ''),
            'capabilities' => array_slice($capabilities, 0, 6),
            'quick_prompts' => array_slice(array_values(array_filter(array_map('strval', $quickPrompts))), 0, 8),
            'show_on_empty_thread' => !empty($config['show_on_empty_thread']),
            'resource_status' => AigcCanvasService::resourceStatus($tenantId),
        ];
        $payload['response'] = AgentResponseProtocol::onboarding($payload);
        return $payload;
    }

    private static function capabilitiesFromSkills(array $skills): array
    {
        $items = [];
        foreach ($skills as $skill) {
            if (!is_array($skill)) {
                continue;
            }
            $key = trim((string)($skill['skill_key'] ?? ''));
            $name = trim((string)($skill['name'] ?? ''));
            if ($key === '' || $name === '') {
                continue;
            }
            $examples = is_array($skill['examples'] ?? null)
                ? $skill['examples']
                : (is_array($skill['examples_json'] ?? null) ? $skill['examples_json'] : []);
            $example = trim((string)($examples[0] ?? ''));
            if ($example === '') {
                $example = self::exampleForSkill($key, $name);
            }
            $items[] = [
                'label' => $name,
                'skill_key' => $key,
                'description' => mb_substr((string)($skill['description'] ?? ''), 0, 80, 'UTF-8'),
                'example' => $example,
            ];
        }
        return $items;
    }

    private static function filterCapabilities(array $capabilities, array $keys): array
    {
        $keys = array_values(array_filter(array_map('strval', $keys)));
        if ($keys === []) {
            return $capabilities;
        }
        $byKey = [];
        foreach ($capabilities as $item) {
            $key = (string)($item['skill_key'] ?? '');
            if ($key !== '') {
                $byKey[$key] = $item;
            }
        }
        $result = [];
        foreach ($keys as $key) {
            if (isset($byKey[$key])) {
                $result[] = $byKey[$key];
            }
        }
        return $result;
    }

    private static function defaultCapabilities(): array
    {
        return [
            ['label' => '通用生图', 'skill_key' => 'general_image', 'description' => '海报、插画、封面和主视觉', 'example' => '生成一张科技感新品海报'],
            ['label' => '电商详情图', 'skill_key' => 'ecommerce_detail_page', 'description' => '商品主图、卖点图和详情页', 'example' => '给我生成2张淘宝商品详情图'],
            ['label' => '海报设计', 'skill_key' => 'poster_design', 'description' => '活动、课程、节日和品牌海报', 'example' => '做一张新品发布会海报'],
            ['label' => '视频生成', 'skill_key' => 'video_generation', 'description' => '文生视频、图生视频和产品短片', 'example' => '把这张产品图做成5秒短视频'],
            ['label' => '脚本策划', 'skill_key' => 'script_planning', 'description' => '短视频脚本、广告脚本和分镜策划', 'example' => '帮我写一个新品发布短视频脚本'],
        ];
    }

    private static function quickPrompts(array $capabilities): array
    {
        $prompts = [];
        foreach ($capabilities as $item) {
            $example = trim((string)($item['example'] ?? ''));
            if ($example !== '') {
                $prompts[] = $example;
            }
        }
        return array_values(array_unique(array_slice(array_merge($prompts, [
            '给我生成2张淘宝图',
            '做一张品牌海报',
            '生成一个15秒视频脚本',
            '把这张图改得更高级',
        ]), 0, 8)));
    }

    private static function exampleForSkill(string $key, string $name): string
    {
        return match ($key) {
            'ecommerce_detail_page' => '给我生成2张淘宝商品详情图',
            'poster_design' => '做一张新品发布海报',
            'video_generation' => '把这张图做成5秒短视频',
            'script_planning' => '帮我写一个短视频脚本',
            default => '使用' . $name . '帮我完成这次创作',
        };
    }
}
