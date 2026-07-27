<?php

namespace app\common\service\app\aigc_canvas;

use Exception;
use think\facade\Db;

/** Deterministic, no-provider-cost Skill contract evaluation. */
class AigcCanvasSkillEvaluationService
{
    public static function lists(int $tenantId, array $params = []): array
    {
        AigcCanvasSkillService::seedBuiltinSkills($tenantId);
        self::seedBuiltinCases($tenantId);
        $query = Db::name('aigc_canvas_skill_evaluation_case')->where(['tenant_id' => $tenantId, 'delete_time' => 0]);
        $skillKey = trim((string)($params['skill_key'] ?? ''));
        if ($skillKey !== '') {
            $query->where('skill_key', $skillKey);
        }
        return $query->order('id', 'desc')->select()->toArray();
    }

    /**
     * Idempotent, cost-free baseline cases for the built-in product catalog.
     * They validate contracts only and never invoke a model or media provider.
     */
    public static function seedBuiltinCases(int $tenantId): void
    {
        try {
            $cases = self::builtinCases();
            $names = array_column($cases, 'name');
            $exists = Db::name('aigc_canvas_skill_evaluation_case')
                ->where(['tenant_id' => $tenantId, 'delete_time' => 0])
                ->whereIn('name', $names)
                ->column('name');
            $now = time();
            foreach ($cases as $case) {
                if (in_array($case['name'], $exists, true) || AigcCanvasSkillService::resolveSkill($tenantId, $case['skill_key']) === []) {
                    continue;
                }
                Db::name('aigc_canvas_skill_evaluation_case')->insert([
                    'tenant_id' => $tenantId,
                    'skill_key' => $case['skill_key'],
                    'name' => $case['name'],
                    'input_json' => json_encode($case['input'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'canvas_fixture_json' => json_encode($case['canvas_fixture'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'expected_route_json' => json_encode($case['expected_route'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'expected_next_action' => $case['expected_next_action'],
                    'tags_json' => json_encode($case['tags'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => 1,
                    'create_time' => $now,
                    'update_time' => $now,
                    'delete_time' => 0,
                ]);
            }
        } catch (\Throwable) {
            // A missing migration must not block the runtime on older deployments.
        }
    }

    public static function save(int $tenantId, array $params): array
    {
        $id = (int)($params['id'] ?? 0);
        $skillKey = trim((string)($params['skill_key'] ?? ''));
        if ($skillKey === '' || AigcCanvasSkillService::resolveSkill($tenantId, $skillKey) === []) {
            throw new Exception('The evaluation case must reference an existing Skill');
        }
        $data = [
            'tenant_id' => $tenantId,
            'skill_key' => $skillKey,
            'name' => mb_substr(trim((string)($params['name'] ?? $skillKey)), 0, 160, 'UTF-8'),
            'input_json' => self::json($params['input_json'] ?? $params['input'] ?? []),
            'canvas_fixture_json' => self::json($params['canvas_fixture_json'] ?? $params['canvas_fixture'] ?? []),
            'expected_route_json' => self::json($params['expected_route_json'] ?? $params['expected_route'] ?? []),
            'expected_next_action' => trim((string)($params['expected_next_action'] ?? '')),
            'tags_json' => self::json($params['tags_json'] ?? $params['tags'] ?? []),
            'status' => !empty($params['status']) ? 1 : 0,
            'update_time' => time(),
        ];
        if ($id > 0) {
            Db::name('aigc_canvas_skill_evaluation_case')->where(['id' => $id, 'tenant_id' => $tenantId, 'delete_time' => 0])->update($data);
        } else {
            $data['create_time'] = time();
            $data['delete_time'] = 0;
            $id = (int)Db::name('aigc_canvas_skill_evaluation_case')->insertGetId($data);
        }
        return (array)Db::name('aigc_canvas_skill_evaluation_case')->where('id', $id)->find();
    }

    public static function run(int $tenantId, array $params = []): array
    {
        $cases = self::lists($tenantId, $params);
        $results = [];
        foreach ($cases as $case) {
            if ((int)($case['status'] ?? 0) !== 1) {
                continue;
            }
            $input = self::decode($case['input_json'] ?? []);
            $fixture = self::decode($case['canvas_fixture_json'] ?? []);
            $expected = self::decode($case['expected_route_json'] ?? []);
            $skill = AigcCanvasSkillService::resolveSkill($tenantId, (string)$case['skill_key']);
            $contract = $skill === [] ? [] : AigcCanvasSkillService::compileForAgent($skill, (string)($input['content'] ?? ''), $fixture, true);
            $errors = [];
            foreach ((array)($expected['missing_slots'] ?? []) as $slot) {
                if (!in_array($slot, (array)($contract['missing_slots'] ?? []), true)) {
                    $errors[] = 'missing_slots:' . $slot;
                }
            }
            foreach ((array)($expected['allowed_tools'] ?? []) as $tool) {
                if (!in_array($tool, (array)($contract['allowed_tools'] ?? []), true)) {
                    $errors[] = 'allowed_tools:' . $tool;
                }
            }
            $actualAction = empty($contract['missing_slots']) ? 'execute' : 'clarify';
            if (($case['expected_next_action'] ?? '') !== '' && (string)$case['expected_next_action'] !== $actualAction) {
                $errors[] = 'next_action';
            }
            $results[] = ['id' => (int)$case['id'], 'name' => (string)$case['name'], 'skill_key' => (string)$case['skill_key'], 'passed' => $errors === [], 'errors' => $errors, 'contract' => $contract];
        }
        $passed = count(array_filter($results, static fn(array $item): bool => $item['passed']));
        return ['total' => count($results), 'passed' => $passed, 'failed' => count($results) - $passed, 'pass_rate' => $results === [] ? 0 : round($passed * 100 / count($results), 2), 'results' => $results];
    }

    private static function json($value): string
    {
        if (is_string($value)) {
            return $value;
        }
        return json_encode(is_array($value) ? $value : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decode($value): array
    {
        if (is_array($value)) return $value;
        $decoded = is_string($value) ? json_decode($value, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    private static function builtinCases(): array
    {
        $selectedImage = ['selected_elements' => [['id' => 'image-1', 'type' => 'image']]];
        return [
            [
                'name' => 'P0 通用生图正例',
                'skill_key' => 'general_image',
                'input' => ['content' => '生成一张雨后街道的电影感插画'],
                'canvas_fixture' => [],
                'expected_route' => ['allowed_tools' => ['generate_image']],
                'expected_next_action' => 'execute',
                'tags' => ['p0', '正例'],
            ],
            [
                'name' => 'P0 通用生图缺主体',
                'skill_key' => 'general_image',
                'input' => ['content' => '帮我生成一张图'],
                'canvas_fixture' => [],
                'expected_route' => ['missing_slots' => ['subject']],
                'expected_next_action' => 'clarify',
                'tags' => ['p0', '缺槽位'],
            ],
            [
                'name' => 'P0 图片编辑选区引用',
                'skill_key' => 'image_edit',
                'input' => ['content' => '把背景改成蓝色'],
                'canvas_fixture' => $selectedImage,
                'expected_route' => ['allowed_tools' => ['generate_image']],
                'expected_next_action' => 'execute',
                'tags' => ['p0', '选区'],
            ],
            [
                'name' => 'P0 图片编辑缺参考图',
                'skill_key' => 'image_edit',
                'input' => ['content' => '把背景改成蓝色'],
                'canvas_fixture' => [],
                'expected_route' => ['missing_slots' => ['reference_asset']],
                'expected_next_action' => 'clarify',
                'tags' => ['p0', '反例', '缺槽位'],
            ],
            [
                'name' => 'P1 电商主图商品引用',
                'skill_key' => 'ecommerce_main_image',
                'input' => ['content' => '给不锈钢保温杯做一张淘宝白底主图'],
                'canvas_fixture' => [],
                'expected_route' => ['allowed_tools' => ['generate_image']],
                'expected_next_action' => 'execute',
                'tags' => ['p1', '电商', '正例'],
            ],
            [
                'name' => 'P1 电商卖点图缺卖点',
                'skill_key' => 'ecommerce_selling_point',
                'input' => ['content' => '给耳机做一张电商图片'],
                'canvas_fixture' => [],
                'expected_route' => ['missing_slots' => ['selling_point']],
                'expected_next_action' => 'clarify',
                'tags' => ['p1', '电商', '缺槽位'],
            ],
            [
                'name' => 'P1 图生视频选区引用',
                'skill_key' => 'image_to_video',
                'input' => ['content' => '让镜头缓慢推进'],
                'canvas_fixture' => $selectedImage,
                'expected_route' => ['allowed_tools' => ['generate_video']],
                'expected_next_action' => 'execute',
                'tags' => ['p1', '视频', '选区'],
            ],
            [
                'name' => 'P1 Logo 设计缺品牌信息',
                'skill_key' => 'logo_design',
                'input' => ['content' => '帮我设计一个 Logo'],
                'canvas_fixture' => [],
                'expected_route' => ['missing_slots' => ['brand_name']],
                'expected_next_action' => 'clarify',
                'tags' => ['p1', '品牌', '反例'],
            ],
            [
                'name' => 'P2 演示视觉正例',
                'skill_key' => 'presentation_design',
                'input' => ['content' => '为 AI 产品路演设计八页演示视觉'],
                'canvas_fixture' => [],
                'expected_route' => ['allowed_tools' => ['generate_text', 'generate_image']],
                'expected_next_action' => 'execute',
                'tags' => ['p2', '高级能力', '正例'],
            ],
            [
                'name' => 'P2 短剧前期缺梗概',
                'skill_key' => 'short_drama_preproduction',
                'input' => ['content' => '帮我做短剧前期制作'],
                'canvas_fixture' => [],
                'expected_route' => ['missing_slots' => ['story_brief']],
                'expected_next_action' => 'clarify',
                'tags' => ['p2', '短剧', '缺槽位'],
            ],
        ];
    }
}
