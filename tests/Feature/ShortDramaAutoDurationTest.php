<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaAutoDurationTest extends TestCase
{
    private function call(string $name, ...$args)
    {
        $method = new ReflectionMethod(AigcShortDramaService::class, $name);
        $method->setAccessible(true);
        return $method->invoke(null, ...$args);
    }

    public function testUnspecifiedDurationIsAutomatic(): void
    {
        $request = $this->call('normalizeCreateRequest', ['prompt' => '女孩找回丢失的猫'], []);
        self::assertSame(0, $request['target_duration_seconds']);
        self::assertSame('auto', $request['duration_source']);
        self::assertSame(0, $this->call('planningTargetDurationSeconds', '女孩找回丢失的猫', $request));
        $rule = $this->call('storyboardTargetRule', '女孩找回丢失的猫', $request);
        self::assertSame(1, $rule['min_shots']);
        self::assertSame(0, $rule['max_shots']);
        self::assertSame(1, $this->call('minimumStoryboardShotCount', '女孩找回丢失的猫', $request, 8));
    }

    public function testExplicitAndHistoricalTargetsArePreserved(): void
    {
        foreach ([5, 30, 60, 180] as $seconds) {
            $request = $this->call('normalizeCreateRequest', ['prompt' => '女孩找回猫', 'target_duration_seconds' => $seconds], []);
            self::assertSame('user', $request['duration_source']);
            self::assertSame($seconds, $this->call('planningTargetDurationSeconds', '女孩找回猫', $request));
            self::assertSame($seconds, $this->call('planningTargetDurationSeconds', '旧任务', ['target_duration_seconds' => $seconds]));
        }
    }

    public function testAutomaticCoverageAndBalancingDoNotPadOrShortenShots(): void
    {
        foreach ([1, 7, 25] as $count) {
            $shots = array_map(static fn($n) => ['shot_id' => (string)$n, 'recommended_duration_seconds' => 4], range(1, $count));
            $locations = [['id' => 'location_1', 'name' => '街道'], ['id' => 'location_2', 'name' => '家']];
            foreach (['repairStoryboardCoverage', 'balanceStoryboardDuration'] as $method) {
                $result = $this->call($method, $shots, $locations, [], '女孩找回猫', [], '女孩找回猫');
                self::assertSame($shots, $result['storyboard']);
                self::assertSame([], $result['issues_fixed']);
            }
        }
    }
}
