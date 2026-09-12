<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaRevisionScope as Scope;
use app\common\service\app\aigc_short_drama\ShortDramaEpisodeService as Episodes;
use app\common\service\app\aigc_short_drama\AigcShortDramaService as Plans;
use PHPUnit\Framework\TestCase;

class ShortDramaRevisionScopeTest extends TestCase
{
    private function plan(): array
    {
        return ['title' => '原剧本', 'story_outline' => '主角拒绝签约',
            'subjects' => [['id' => 's1', 'name' => '林浅', 'description' => '设计师']],
            'locations' => [['id' => 'l1', 'name' => '工作室']],
            'storyboard' => array_map(static fn(int $i): array => ['shot_id' => 'stable_' . $i,
                'dialogue' => '旧台词' . $i, 'voice_role' => '林浅', 'visual_description' => '看向窗外',
                'image_prompt' => '原图片提示', 'sound_effect' => '风声', 'camera_movement' => '固定',
                'video_prompt' => "景别：中景\n画面内容：看向窗外\n声音：林浅：旧台词" . $i,
                'selected_image_asset_id' => 123], range(1, 5))];
    }

    private function call(string $method, ...$args)
    {
        $reflection = new \ReflectionMethod(Plans::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke(null, ...$args);
    }

    public function testSpeakerNameDoesNotOverrideNumberedDialogueTarget(): void
    {
        $target = $this->call('inferRevisionTargetFromMessage', '把林浅在第3个分镜的台词改成我不同意', $this->plan());
        self::assertSame('shot_fields', $target['type']);
        self::assertSame(['stable_3'], $target['ids']);
        self::assertSame(['dialogue'], $target['fields']);
        self::assertSame([], $this->call('inferRevisionTargetFromMessage', '改一下林浅的台词', $this->plan()));
    }

    public function testChineseNumbersListsAndRangesFollowDisplayedOrder(): void
    {
        foreach (['修改第三个分镜的台词' => ['stable_3'], '修改第2、4个分镜的对白' => ['stable_2', 'stable_4'],
            '修改分镜二到四的构图' => ['stable_2', 'stable_3', 'stable_4']] as $message => $ids) {
            self::assertSame($ids, Scope::shotTarget($message, $this->plan())['ids']);
        }
    }

    public function testMissingShotCannotFallBackToCharacterEdit(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('指定的分镜不存在');
        $this->call('inferRevisionTargetFromMessage', '把林浅第99个分镜的台词修改', $this->plan());
    }

    public function testDisplayedOrderIsIndependentOfStoredArrayOrder(): void
    {
        $plan = $this->plan();
        $plan['storyboard'] = array_reverse($plan['storyboard']);
        self::assertSame(['stable_3'], Scope::shotTarget('修改第3个分镜台词', $plan)['ids']);
        self::assertSame(['stable_1'], Scope::shotTarget('修改第1个分镜台词', $plan)['ids']);
    }

    public function testActualCompactPromptCarriesScopeAndSupportsPartialResponse(): void
    {
        $base = $this->plan();
        $target = Scope::shotTarget('把第3个分镜的台词改成我不同意', $base);
        $request = ['revision_message' => '把第3个分镜的台词改成我不同意', 'revision_base_result' => $base,
            'revision_target' => $target, 'revision_policy' => ['mode' => 'local_only']];
        $prompt = $this->call('buildCompactScriptPlanPrompt', '合同谈判', $request, '原剧本');
        self::assertStringContainsString('ONLY the selected shot IDs', $prompt);
        self::assertStringContainsString('revision_policy', $prompt);
        self::assertStringContainsString('stable_3', $prompt);
        $merged = $this->call('mergeRevisionBasePlanPayload', ['storyboard' => [['shot_id' => 'stable_3', 'dialogue' => '我不同意']]], $request);
        self::assertSame('我不同意', $merged['storyboard'][2]['dialogue']);
        self::assertSame($base['storyboard'][0], $merged['storyboard'][0]);
        self::assertCount(5, $merged['storyboard']);
    }

    public function testDialogueMergeRejectsUnrequestedModelChangesAndRetainsEmptyDialogue(): void
    {
        $base = $this->plan();
        $result = $base;
        $result['title'] = '模型改错标题';
        $result['subjects'][0]['name'] = '模型改错人物';
        foreach ($result['storyboard'] as &$shot) {
            $shot['dialogue'] = ''; $shot['visual_description'] = '错误画面'; $shot['image_prompt'] = '错误提示';
            $shot['video_prompt'] = '错误视频提示';
        }
        unset($shot);
        $target = Scope::shotTarget('删除第3个分镜的台词', $base);
        $merged = Scope::mergeShots($base, $result, $target);
        self::assertSame($base['title'], $merged['title']);
        self::assertSame($base['subjects'], $merged['subjects']);
        foreach ([0, 1, 3, 4] as $i) self::assertSame($base['storyboard'][$i], $merged['storyboard'][$i]);
        self::assertSame('', $merged['storyboard'][2]['dialogue']);
        self::assertSame($base['storyboard'][2]['image_prompt'], $merged['storyboard'][2]['image_prompt']);
        self::assertSame(123, $merged['storyboard'][2]['selected_image_asset_id']);
        self::assertSame("景别：中景\n画面内容：看向窗外\n声音：无对白；风声", $merged['storyboard'][2]['video_prompt']);
    }

    public function testMissingResultTargetFailsInsteadOfPretendingSuccess(): void
    {
        $base = $this->plan();
        $this->expectException(\Exception::class);
        Scope::mergeShots($base, ['storyboard' => []], Scope::shotTarget('改第3个分镜', $base));
    }

    public function testRewriteIntentIncludesEpisodeAndExcludesNegationAndDialogue(): void
    {
        foreach (['重写本集剧本', '重写这一集', '把本集整体重写', '重新规划本集', '改为4集'] as $message) {
            self::assertTrue($this->call('isFullPlanRevisionRequest', $message), $message);
        }
        foreach (['不要重写本集剧本，只改第三个分镜台词', '不用整体重写', '第3个分镜台词改为“重写本集剧本”', '把第3个分镜整体重写'] as $message) {
            self::assertFalse($this->call('isFullPlanRevisionRequest', $message), $message);
        }
    }

    public function testContinuityIncludesRevisedDialogueEvenWhenSynopsisIsUnchanged(): void
    {
        $plan = $this->plan();
        $before = Episodes::continuitySnapshot(1, $plan);
        $plan['storyboard'][2]['dialogue'] = '我决定签约';
        $after = Episodes::continuitySnapshot(1, $plan);
        self::assertSame($before['summary'], $after['summary']);
        self::assertSame('我决定签约', $after['shots'][2]['dialogue']);
        self::assertNotSame($before, $after);
    }
}
