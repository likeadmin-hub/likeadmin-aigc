<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Plans;
use PHPUnit\Framework\TestCase;

class ShortDramaRepairResultGuardTest extends TestCase
{
    private function call(string $method, ...$args)
    {
        $method = new \ReflectionMethod(Plans::class, $method);
        $method->setAccessible(true);
        return $method->invoke(null, ...$args);
    }

    private function shot(int $number, string $description): array
    {
        return [
            'shot_id' => (string)$number,
            'title' => '分镜' . $number,
            'visual_description' => $description,
            'composition' => '中景，三分法构图',
            'camera_movement' => '固定镜头',
            'recommended_duration_seconds' => 3,
        ];
    }

    public function testPartialRepairCannotDiscardExistingStoryboardShots(): void
    {
        $baseline = [
            'title' => '首轮完整剧本',
            'subjects' => [['id' => 'subject_1', 'name' => 'Uana', 'description' => '完整主体设定']],
            'locations' => [['id' => 'location_1', 'name' => '发布会现场', 'description' => '完整场景设定']],
            'storyboard' => array_map(fn(int $number): array => $this->shot($number, '首轮分镜' . $number), range(1, 22)),
        ];
        $repair = [
            'title' => '修复后的剧本',
            'subjects' => [['id' => 'subject_1', 'name' => 'Uana', 'description' => '修复后的主体设定']],
            'locations' => [['id' => 'location_1', 'name' => '发布会现场']],
            'storyboard' => array_map(fn(int $number): array => $this->shot($number, '修复分镜' . $number), range(1, 6)),
        ];

        $merged = $this->call('mergeRepairPlanResult', $baseline, $repair);

        self::assertSame('修复后的剧本', $merged['title']);
        self::assertCount(22, $merged['storyboard']);
        self::assertSame('修复分镜1', $merged['storyboard'][0]['visual_description']);
        self::assertSame('首轮分镜22', $merged['storyboard'][21]['visual_description']);
        self::assertSame('修复后的主体设定', $merged['subjects'][0]['description']);
        self::assertSame('完整场景设定', $merged['locations'][0]['description']);
    }

    public function testRepairTokenBudgetMatchesEpisodeGenerationBudget(): void
    {
        $budget = $this->call('scriptPlanRepairMaxTokens', [
            'multi_episode' => false,
            'episode_count' => 1,
            'series_context' => ['title' => '前序剧集'],
            'episode_id' => 9815,
        ]);

        self::assertGreaterThanOrEqual(12000, $budget);
    }

    public function testCappedRepairCannotOverwriteAValidDialogueRole(): void
    {
        $baseline = [
            'storyboard' => [[
                'shot_id' => '1',
                'visual_description' => '医生站在诊室门外',
                'dialogue' => '最多还有三个月。',
                'voice_role' => '医生',
                'speech_type' => 'character',
            ]],
        ];
        $repair = [
            'storyboard' => [[
                'shot_id' => '1',
                'visual_description' => '修复后的诊室画面',
                'voice_role' => '',
                'speech_type' => 'narration',
            ]],
        ];

        $merged = $this->call('mergeRepairPlanResult', $baseline, $repair, true, []);

        self::assertSame('医生', $merged['storyboard'][0]['voice_role']);
        self::assertSame('character', $merged['storyboard'][0]['speech_type']);
        self::assertSame('医生站在诊室门外', $merged['storyboard'][0]['visual_description']);
    }
}
