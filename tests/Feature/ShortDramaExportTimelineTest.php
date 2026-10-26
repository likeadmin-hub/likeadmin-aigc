<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaExportTimeline;
use PHPUnit\Framework\TestCase;

class ShortDramaExportTimelineTest extends TestCase
{
    private function shots(): array
    {
        return [
            ['shot_id' => '1', 'selected_video_asset_id' => 12, 'selected_image_asset_id' => 11, 'recommended_duration_seconds' => 5],
            ['shot_id' => '2', 'selected_image_asset_id' => 21, 'recommended_duration_seconds' => 3],
        ];
    }

    private function assets(): array
    {
        return array_map(static fn(array $row): array => $row + ['status' => 'ready', 'delete_time' => 0], [
            ['id' => 11, 'shot_id' => '1', 'asset_type' => 'shot_image'],
            ['id' => 12, 'shot_id' => '1', 'asset_type' => 'shot_video'],
            ['id' => 21, 'shot_id' => '2', 'asset_type' => 'shot_image'],
            ['id' => 31, 'shot_id' => '', 'asset_type' => 'bgm_audio'],
            ['id' => 32, 'shot_id' => '', 'asset_type' => 'bgm_audio'],
        ]);
    }

    private function timeline(): array
    {
        return [['shot_id' => '1', 'asset_id' => 11, 'duration' => 8], ['shot_id' => '2', 'asset_id' => 21, 'duration' => 3]];
    }

    public function testExplicitImageWinsOverAnOlderVideoAndKeepsEditorDuration(): void
    {
        $snapshot = ShortDramaExportTimeline::capture($this->shots(), $this->assets(), ['timeline' => $this->timeline(), 'bgm_enabled' => false]);
        $resolved = ShortDramaExportTimeline::resolve($snapshot, $this->assets());
        self::assertSame([11, 21], array_column($resolved['assets'], 'id'));
        self::assertSame(8.0, $resolved['assets'][0]['duration']);
        self::assertSame([], $resolved['bgm']);
    }

    public function testChosenBgmIsFrozenAndNewerResultsDoNotReplaceInputs(): void
    {
        $snapshot = ShortDramaExportTimeline::capture($this->shots(), $this->assets(), ['timeline' => $this->timeline(), 'bgm_enabled' => true, 'bgm_asset_id' => 31]);
        $assets = [...$this->assets(), ['id' => 100, 'shot_id' => '1', 'asset_type' => 'shot_video', 'status' => 'ready']];
        $resolved = ShortDramaExportTimeline::resolve($snapshot, $assets);
        self::assertSame(31, $resolved['bgm']['id']);
        self::assertSame(11, $resolved['assets'][0]['id']);
    }

    public function testLegacyRequestsKeepSelectionAndLatestMusicDefaults(): void
    {
        $snapshot = ShortDramaExportTimeline::capture($this->shots(), $this->assets(), []);
        self::assertSame([12, 21], array_column($snapshot['clips'], 'asset_id'));
        self::assertSame(32, $snapshot['bgm_asset_id']);
    }

    /** @dataProvider invalidInputs */
    public function testInvalidTimelineCannotStartAnExport(string $case): void
    {
        $shots = $this->shots(); $assets = $this->assets(); $timeline = $this->timeline();
        $options = ['bgm_enabled' => false];
        if ($case === 'missing') array_pop($timeline);
        if ($case === 'reordered') $timeline = array_reverse($timeline);
        if ($case === 'foreign') $timeline[0]['asset_id'] = 999;
        if ($case === 'other-shot') $timeline[0]['asset_id'] = 21;
        if ($case === 'deleted') $assets[0]['delete_time'] = 100;
        if ($case === 'pending') $assets[0]['status'] = 'pending';
        if ($case === 'duration') $timeline[0]['duration'] = -1;
        if ($case === 'bgm') $options = ['bgm_enabled' => true, 'bgm_asset_id' => 11];
        $this->expectException(\Exception::class);
        ShortDramaExportTimeline::capture($shots, $assets, $options + ['timeline' => $timeline]);
    }

    public static function invalidInputs(): array
    {
        return array_map(static fn(string $case): array => [$case], ['missing', 'reordered', 'foreign', 'other-shot', 'deleted', 'pending', 'duration', 'bgm']);
    }

    public function testRemovedFrozenInputDoesNotFallBackToAnotherResult(): void
    {
        $snapshot = ShortDramaExportTimeline::capture($this->shots(), $this->assets(), ['timeline' => $this->timeline()]);
        $assets = $this->assets(); $assets[0]['delete_time'] = 1;
        $this->expectException(\Exception::class);
        ShortDramaExportTimeline::resolve($snapshot, $assets);
    }

    public function testExportIntegrationScopesInputsAndRejectsClientSnapshots(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/common/service/app/aigc_short_drama/AigcShortDramaService.php');
        self::assertStringContainsString("unset(\$params['_export_timeline'], \$params['params']['_export_timeline'])", $source);
        $start = strpos($source, 'private static function exportTimelineAssets');
        $end = strpos($source, 'private static function orderedShotVideoAssets', $start);
        $methods = substr($source, $start, $end - $start);
        self::assertSame(2, substr_count($methods, "'tenant_id' => \$tenantId, 'user_id' => \$userId, 'project_id' => \$projectId"));
        self::assertStringContainsString("\$query->where('task_id', \$taskId)", $methods);
    }
}
