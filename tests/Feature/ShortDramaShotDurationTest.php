<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use app\common\service\app\aigc_short_drama\ShortDramaShotDuration as Duration;
use app\common\service\app\aigc_short_drama\ShortDramaPromptCatalog as Catalog;
use app\common\service\app\aigc_short_drama\ShortDramaPromptWorkspace as Workspace;
use PHPUnit\Framework\TestCase;

class ShortDramaShotDurationTest extends TestCase
{
    private function call(string $name, ...$args) {
        $method = new \ReflectionMethod(Service::class, $name);
        $method->setAccessible(true);
        return $method->invokeArgs(null, $args);
    }

    public function testGeneratedAndEditedShotsShareTheSameRange(): void
    {
        foreach ([0 => 5, 2 => 4, 3 => 4, 4 => 4, 8 => 8, 15 => 15, 16 => 15] as $input => $expected) {
            $shot = ['shot_id' => 'a', 'visual_description' => '甲拿起信件', 'recommended_duration_seconds' => $input];
            self::assertSame((float)$expected, $this->call('normalizeGeneratedStoryboard', [$shot])[0]['recommended_duration_seconds']);
            self::assertSame((float)$expected, $this->call('editableShotData', $shot)['recommended_duration_seconds']);
        }
        self::assertSame(4.5, Duration::normalize(4.5));
        self::assertSame(5.0, Duration::normalize(null));
        self::assertSame(5.0, Duration::normalize(INF));
    }

    public function testReviewAndRepairDoNotTruncateLongValidShots(): void
    {
        foreach ([4, 8, 15] as $seconds) {
            $plan = ['subjects' => [['id' => 's']], 'locations' => [['id' => 'l']],
                'storyboard' => [['shot_id' => 'a', 'scene_ref_id' => 'l', 'subject_ref_ids' => ['s'],
                    'visual_description' => '甲拿起信件', 'recommended_duration_seconds' => $seconds]]];
            [$repaired] = $this->call('codeRepairPlanResult', $plan);
            self::assertEquals($seconds, $repaired['storyboard'][0]['recommended_duration_seconds']);
            self::assertStringNotContainsString('shot.duration.invalid', json_encode($this->call('reviewPlanResult', $plan)));
        }
    }

    public function testTimelineSplittingPreservesExactTotalsAndBounds(): void
    {
        for ($seconds = 4; $seconds <= 300; $seconds++) {
            $parts = $this->call('splitTimelineDuration', $seconds);
            self::assertSame($seconds, array_sum($parts));
            foreach ($parts as $part) self::assertTrue(Duration::contains($part));
        }
        self::assertSame([15], $this->call('splitTimelineDuration', 15));
        self::assertSame([8, 8], $this->call('splitTimelineDuration', 16));
        $this->expectException(\InvalidArgumentException::class);
        $this->call('splitTimelineDuration', 3);
    }

    public function testDurationBalancingAndCountHintsUseNewLimits(): void
    {
        foreach ([16, 30, 31, 60] as $target) {
            $result = $this->call('balanceStoryboardDuration', [['shot_id' => 'a', 'recommended_duration_seconds' => 15]],
                [['id' => 'l', 'name' => '街道']], [], '甲出门', ['target_duration_seconds' => $target]);
            self::assertEquals($target, array_sum(array_column($result['storyboard'], 'recommended_duration_seconds')));
            foreach ($result['storyboard'] as $shot) self::assertTrue(Duration::contains($shot['recommended_duration_seconds']));
        }
        $rule = $this->call('storyboardTargetRule', '甲出门', ['target_duration_seconds' => 60]);
        self::assertSame(4, $rule['min_shots']); self::assertSame(15, $rule['max_shots']);
    }

    public function testSavedInstructionsCannotRestoreTheOldRange(): void
    {
        foreach ([[], ['script' => ['mode' => 'custom', 'body' => 'Every shot is 2-5 seconds. 保留人物关系']]] as $settings) {
            $snapshot = Workspace::resolve(701, ['mode' => 'documents', 'document_settings' => $settings], []);
            foreach ([[], ['multi_episode' => true, 'multi_episode_stage' => 'production', 'episode_count' => 3]] as $request) {
                $messages = Catalog::run($snapshot, fn() => $this->call('assembleScriptPromptRequest', 701, '甲在街道寻找信件', $request, '信'));
                self::assertStringContainsString(Duration::INSTRUCTION, $messages['system_prompt']);
                self::assertStringNotContainsString('2-5 seconds', $messages['system_prompt']);
            }
        }
        // Frozen legacy workspace values are normalized at use, not rewritten in storage.
        $snapshot = ['mode' => 'workspace', 'values' => ['script.shot_granularity' => 'Every shot is 2-5 seconds.']];
        $rendered = Catalog::run($snapshot, fn() => Catalog::text('script.shot_granularity'));
        self::assertSame('Every shot is 4-15 seconds.', $rendered);
        self::assertSame('Every shot is 2-5 seconds.', $snapshot['values']['script.shot_granularity']);
        $template = $this->call('renderScriptPlanPromptTemplate', 'Every shot is 2-5 seconds. {{prompt}}', '', '对白：等待2-5秒', [], '');
        self::assertStringContainsString('Every shot is 4-15 seconds.', $template);
        self::assertStringContainsString('对白：等待2-5秒', $template);
    }

    public function testProductionSourcesCannotReintroduceTheRetiredRange(): void
    {
        $root = __DIR__ . '/../../app/common/service/app/aigc_short_drama/';
        foreach (['AigcShortDramaService.php', 'prompts/catalog.json', 'prompts/documents.json'] as $file) {
            $text = file_get_contents($root . $file);
            self::assertDoesNotMatchRegularExpression('/2\s*[-–~～]\s*5\s*(?:秒|seconds?)/u', $text, $file);
            self::assertStringNotContainsString('max(2, min(5, $duration', $text, $file);
            self::assertStringNotContainsString('$duration < 2 || $duration > 5', $text, $file);
        }
    }
}
