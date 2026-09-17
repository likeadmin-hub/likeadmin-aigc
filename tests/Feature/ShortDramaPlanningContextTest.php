<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaPlanningBudget;
use app\common\service\app\aigc_short_drama\ShortDramaPlanningContext;
use PHPUnit\Framework\TestCase;

class ShortDramaPlanningContextTest extends TestCase
{
    private function request(): array
    {
        return [
            'workflow_variant' => 'story_outline_v2',
            'prompt' => '这段原始灵感与附件内容只应在故事设定阶段使用',
            'multi_episode' => true,
            'multi_episode_stage' => 'episodes',
            'episode_count' => 3,
            'episode_total_count' => 3,
            'episode_batch_start' => 2,
            'episode_batch_end' => 2,
            'confirmed_story_task_id' => 'story_task',
            'confirmed_story_version' => 4,
            'locked_subject_references' => [[
                'id' => '12', 'name' => '美女', 'gender' => 'female', 'category' => 'character',
                'description' => '独立设计师', 'image' => 'https://cdn.example/subject.png',
                'three_view_image' => 'https://cdn.example/views.png',
            ]],
            'confirmed_story_snapshot' => [
                'title' => '海风里的约定', 'type_judgement' => '都市情感', 'core_theme' => '信任',
                'story_outline' => '美女在海边寻找旧信，逐步化解误会。',
                'subjects' => [['id' => 'subject_1', 'name' => '美女', 'description' => '女主角', 'image_prompt' => str_repeat('镜头', 1000)]],
                'locations' => [['id' => 'location_1', 'name' => '海边', 'description' => '礁石与灯塔']],
                'series_bible' => ['series_arc' => '误会到和解', 'continuity_rules' => ['旧信始终由美女保管']],
            ],
            'revision_base_result' => [
                'title' => '海风里的约定', 'story_outline' => '完整故事设定',
                'episodes' => [[
                    'episode_number' => 2, 'title' => '旧信', 'story_outline' => '美女找到了旧信。',
                    'conflict_point' => '林浅拒绝解释。', 'ending_hook' => '信里出现陌生署名。',
                    'scenes' => [['unneeded' => str_repeat('分镜', 1000)]],
                ]],
            ],
            'episode_batch_context' => [
                'previous_episodes' => [
                    ['episode_number' => 1, 'title' => '开始', 'story_outline' => '两人相遇。', 'ending_hook' => '旧信掉落。', 'shots' => [['large' => true]]],
                ],
                'previous_batch_ending_hook' => '旧信掉落。',
            ],
        ];
    }

    public function testOutlineContextKeepsIdentityAndContinuityButExcludesVisualUrls(): void
    {
        $values = ShortDramaPlanningContext::values($this->request());
        self::assertSame('美女', $values['subject_references'][0]['name']);
        self::assertSame('female', $values['subject_references'][0]['gender']);
        self::assertArrayNotHasKey('image', $values['subject_references'][0]);
        self::assertArrayNotHasKey('three_view_image', $values['subject_references'][0]);
        self::assertSame('旧信掉落。', $values['episode_batch_context']['previous_batch_ending_hook']);
        self::assertArrayNotHasKey('scenes', $values['revision_base_result']['episodes'][0]);

        $template = ShortDramaPlanningContext::templateRequest($this->request());
        $serialized = json_encode($template, JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('美女', $serialized);
        self::assertStringContainsString('旧信始终由美女保管', $serialized);
        self::assertStringNotContainsString('https://cdn.example/subject.png', $serialized);
        self::assertStringNotContainsString('https://cdn.example/views.png', $serialized);
        self::assertStringNotContainsString('image_prompt', $serialized);
        self::assertStringNotContainsString('这段原始灵感与附件内容', $serialized);
        self::assertSame(ShortDramaPlanningContext::VERSION, $template['context_pack_version']);
    }

    public function testOnlyConfirmedStoryReplacesOriginalInspirationForOutline(): void
    {
        $request = $this->request();
        self::assertSame('', ShortDramaPlanningContext::stagePrompt('一万字原始附件内容', $request));
        $request['multi_episode_stage'] = 'story';
        self::assertSame('一万字原始附件内容', ShortDramaPlanningContext::stagePrompt('一万字原始附件内容', $request));
    }

    public function testMixedUtf8CapacityDoesNotCountEachChineseCharacterAsThreeTokens(): void
    {
        $budget = ShortDramaPlanningBudget::calculate(str_repeat('长', 5000), [], 1, false);
        self::assertSame(5000, $budget['input_estimate']);
        self::assertSame(15000, $budget['input_bytes']);
        self::assertSame('mixed_utf8_heuristic', $budget['estimator']);
    }

    public function testOutlineRepairBudgetStaysWithinTheStageContract(): void
    {
        $budget = ['output_capacity' => 11818, 'max_tokens' => 1200];
        self::assertSame(1600, ShortDramaPlanningBudget::repairMaxTokens($budget, 1));
        self::assertSame(5648, ShortDramaPlanningBudget::repairMaxTokens($budget, 3, true));
    }
}
