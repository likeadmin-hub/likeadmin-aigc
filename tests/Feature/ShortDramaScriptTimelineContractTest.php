<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaScriptTimelineContractTest extends TestCase
{
    public function testTimelineAcceptsCommonUnicodeRangeSeparators(): void
    {
        $segments = $this->invoke('extractTimelineSegments', "0‑8s 第一段画面\n8–18秒 第二段画面\n18—30s 第三段画面");

        self::assertCount(3, $segments);
        self::assertSame([0, 8, 18], array_column($segments, 'start_seconds'));
        self::assertSame([8, 18, 30], array_column($segments, 'end_seconds'));
    }

    /** @dataProvider standardTimelineFormats */
    public function testTimelineAcceptsStandardTimecodeFormats(string $prompt, array $starts, array $ends): void
    {
        $segments = $this->invoke('extractTimelineSegments', $prompt);
        self::assertSame($starts, array_column($segments, 'start_seconds'));
        self::assertSame($ends, array_column($segments, 'end_seconds'));
    }

    public static function standardTimelineFormats(): array
    {
        return [
            'minutes and seconds' => ["00:00-00:08 开场\n00:08-00:18 冲突", [0, 8], [8, 18]],
            'hours minutes seconds' => ["00:00:00-00:00:08 开场\n00:00:08-00:00:18 冲突", [0, 8], [8, 18]],
            'srt arrows and milliseconds' => ["00:00:00,000 --> 00:00:08,500 开场\n00:00:08,500 --> 00:00:18,000 冲突", [0, 8.5], [8.5, 18]],
            'full width punctuation' => ["００：００－００：０８ 开场\n００：０８→００：１８ 冲突", [0, 8], [8, 18]],
            'minute second notation' => ["0分00秒至0分08秒 开场\n0分08秒到0分18秒 冲突", [0, 8], [8, 18]],
            'english seconds' => ["0sec-8sec 开场\n8.5s -> 18.5seconds 冲突", [0, 8.5], [8, 18.5]],
        ];
    }

    public function testTimelineRemainsAuthoritativeWhenTotalDurationIsSelected(): void
    {
        $prompt = "0‑8s 第一段明确画面\n8‑18s 第二段明确画面\n18‑30s 第三段明确画面";
        $storyboard = $this->invoke(
            'repairStoryboardCoverage',
            [],
            [[
                'id' => 'location_1',
                'name' => '室内场景',
                'description' => '普通室内环境',
                'story_order' => 1,
            ]],
            [],
            $prompt,
            ['target_duration_seconds' => 30],
            ''
        )['storyboard'];

        self::assertCount(3, $storyboard);
        self::assertSame(30.0, array_sum(array_map(static fn(array $shot): float => (float)$shot['recommended_duration_seconds'], $storyboard)));
        self::assertStringContainsString('第一段明确画面', $storyboard[0]['visual_description']);
        self::assertStringContainsString('第二段明确画面', $storyboard[1]['visual_description']);
        self::assertStringContainsString('第三段明确画面', $storyboard[2]['visual_description']);
        self::assertSame('interior', $storyboard[0]['interior_exterior']);
    }

    public function testRepeatedTimelineUsesTheDetailedRunAndCoversItsFinalSeconds(): void
    {
        $prompt = "0‑8s 开场\n8‑18s 发展\n18‑30s 高潮\n30‑38s 转场\n38‑46s 主镜头\n46‑52s 收拢\n52‑60s 落版\n\n"
            . "0‑8秒：圣光照亮莲花台。\n8‑18秒：飞天持柳枝缓慢起舞。\n18‑30秒：双飞天与玉莲同框。\n30‑38秒：飞天侧身转向背面。\n38‑46秒：背面弹琵琶。\n46‑52秒：莲影收拢为发光莲花。\n52‑60秒：金色莲花品牌标志与浅彩虹稳定定格，无产品出现。";
        $segments = $this->invoke('extractTimelineSegments', $prompt);
        $storyboard = $this->invoke(
            'repairStoryboardCoverage',
            [],
            [[
                'id' => 'location_1',
                'name' => '洞窟内景',
                'description' => '金色柔光洞窟',
                'story_order' => 1,
            ]],
            [],
            $prompt,
            ['target_duration_seconds' => 60],
            ''
        )['storyboard'];

        self::assertCount(7, $segments);
        self::assertStringContainsString('金色莲花品牌标志', $segments[6]['text']);
        self::assertCount(7, $storyboard);
        self::assertSame(60.0, array_sum(array_map(static fn(array $shot): float => (float)$shot['recommended_duration_seconds'], $storyboard)));
        self::assertSame('分镜7 52-60s', $storyboard[6]['title']);
        self::assertStringContainsString('金色莲花品牌标志', $storyboard[6]['visual_description']);
        self::assertStringNotContainsString('0‑8s', $storyboard[6]['visual_description']);
        self::assertNotSame('：', mb_substr($storyboard[6]['visual_description'], 0, 1, 'UTF-8'));
    }

    public function testTimelineDurationWinsWhenItConflictsWithTheSelectedDuration(): void
    {
        $duration = $this->invoke(
            'planningTargetDurationSeconds',
            "0‑8s 第一段明确画面\n8‑18s 第二段明确画面\n18‑30s 第三段明确画面",
            ['target_duration_seconds' => 45]
        );

        self::assertSame(30, $duration);
    }

    public function testMillisecondTimelinePreservesTheExactTotal(): void
    {
        $storyboard = $this->invoke('repairStoryboardCoverage', [], [['id' => 'location_1', 'name' => '室内']], [],
            "00:00:00,000 --> 00:00:08,500 开场\n00:00:08,500 --> 00:00:18,000 收束", [], '')['storyboard'];
        self::assertSame(18.0, array_sum(array_column($storyboard, 'recommended_duration_seconds')));
        self::assertSame(8.5, $storyboard[0]['recommended_duration_seconds']);
    }

    public function testSelectedDurationIsBalancedToTheExactTotal(): void
    {
        $storyboard = array_map(static fn(int $index): array => [
            'shot_id' => (string)$index,
            'title' => '分镜' . $index,
            'scene_ref_id' => 'location_1',
            'recommended_duration_seconds' => 4.5,
        ], range(1, 12));
        $result = $this->invoke(
            'balanceStoryboardDuration',
            $storyboard,
            [['id' => 'location_1', 'name' => '室内场景', 'description' => '室内']],
            [],
            '测试故事',
            ['target_duration_seconds' => 60],
            ''
        );

        self::assertSame(60.0, array_sum(array_map(static fn(array $shot): float => (float)$shot['recommended_duration_seconds'], $result['storyboard'])));
        self::assertSame(12, count($result['storyboard']));
    }

    public function testSelectedDurationUsesItsOwnShotCountRule(): void
    {
        $rule = $this->invoke('storyboardTargetRule', '测试故事', ['target_duration_seconds' => 60], [['id' => 'location_1']]);

        self::assertSame('selected_duration', $rule['code']);
        self::assertSame(4, $rule['min_shots']);
        self::assertSame(15, $rule['max_shots']);
    }

    public function testAutoDurationIgnoresRetiredTenantStoryboardRules(): void
    {
        $request = [
            'storyboard_rules' => [[
                'code' => 'suspense', 'label' => '悬疑剧情', 'keywords' => ['悬疑', '反转'],
                'min_shots' => 30, 'max_shots' => 40, 'sort' => 10, 'enabled' => true,
            ], [
                'code' => 'ordinary', 'label' => '常规剧情', 'keywords' => ['都市'],
                'min_shots' => 20, 'max_shots' => 35, 'sort' => 20, 'enabled' => true,
            ]],
        ];
        $rule = $this->invoke('storyboardTargetRule', '都市悬疑反转故事', $request, []);

        self::assertSame([], $rule);
    }

    public function testV3SkeletonCannotReactivateRetiredGenreRules(): void
    {
        $request = [
            'storyboard_rules' => [[
                'code' => 'daily', 'label' => '日常喜剧', 'keywords' => ['日常', '喜剧'],
                'min_shots' => 12, 'max_shots' => 24, 'sort' => 10, 'enabled' => true,
            ], [
                'code' => 'suspense', 'label' => '悬疑反转', 'keywords' => ['悬疑', '反转', '线索'],
                'min_shots' => 30, 'max_shots' => 40, 'sort' => 20, 'enabled' => true,
            ]],
        ];
        $skeleton = [
            'type_judgement' => '悬疑反转',
            'story_outline' => '侦探根据线索揭露真相。',
            'locations' => [['id' => 'location_1', 'name' => '旧宅']],
            'scene_beats' => [['scene_ref_id' => 'location_1', 'goal' => '发现关键线索', 'entry' => '调查', 'exit' => '揭露']],
        ];

        $rule = $this->invoke('storyboardTargetRuleForSkeleton', $skeleton, $request, '轻松日常故事');

        self::assertSame([], $rule);
    }

    public function testV3SkeletonKeepsItsContentDrivenCounts(): void
    {
        $skeleton = [
            'scene_beats' => [
                ['scene_ref_id' => 'location_1', 'goal' => '开场', 'entry' => 'A', 'exit' => 'B', 'shot_count' => 28],
                ['scene_ref_id' => 'location_2', 'goal' => '冲突', 'entry' => 'B', 'exit' => 'C', 'shot_count' => 32],
                ['scene_ref_id' => 'location_3', 'goal' => '结局', 'entry' => 'C', 'exit' => 'D', 'shot_count' => 28],
            ],
        ];

        $budgeted = $this->invoke('applyStoryboardBudgetToSkeleton', $skeleton, [
            'code' => 'daily', 'min_shots' => 12, 'max_shots' => 24,
        ]);

        self::assertSame($skeleton, $budgeted);
    }

    public function testV3FinalGuardIgnoresHistoricalGenreQuota(): void
    {
        $this->invoke('assertStoryboardBudgetSatisfied', [
            'locations' => [['id' => 'location_1']],
            'storyboard' => array_fill(0, 25, ['shot_id' => 'x']),
        ], [
            'storyboard_target_rule' => ['code' => 'daily', 'min_shots' => 12, 'max_shots' => 24],
        ], '普通故事');
        self::assertTrue(true); // No genre-count exception.
    }

    public function testCleanupKeepsAutoRepairedStoryboardShots(): void
    {
        $result = $this->invoke('cleanStoryboardResultData', 0, 0, 0, '', [
            'storyboard' => [[
                'shot_id' => '1',
                'is_auto_repaired' => true,
                'visual_description' => '镜头聚焦本段关键细节',
                'recommended_duration_seconds' => 5,
            ]],
        ]);

        self::assertFalse($result['changed']);
        self::assertCount(1, $result['result']['storyboard']);
    }

    public function testCleanupNeverDeletesAnOrdinaryGeneratedShotOnRead(): void
    {
        $result = $this->invoke('cleanStoryboardResultData', 0, 0, 0, '', [
            'storyboard' => [[
                'shot_id' => '1',
                'visual_description' => '镜头聚焦本段关键细节',
                'recommended_duration_seconds' => 3,
            ]],
        ]);

        self::assertFalse($result['changed']);
        self::assertCount(1, $result['result']['storyboard']);
    }

    public function testCleanupNeverChangesATimelineLockedStoryboard(): void
    {
        $result = $this->invoke('cleanStoryboardResultData', 0, 0, 0, '', [
            'storyboard_breaking_diagnostics' => ['timeline_override' => true],
            'storyboard' => [[
                'shot_id' => '1',
                'visual_description' => '镜头聚焦本段关键细节',
                'recommended_duration_seconds' => 3,
            ]],
        ]);

        self::assertFalse($result['changed']);
        self::assertCount(1, $result['result']['storyboard']);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $arguments);
    }
}
