<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaSameSceneCuts, ShortDramaEpisodeDuration, AigcShortDramaService};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaSameSceneCutsTest extends TestCase
{
    private function request(bool $enabled = true): array
    {
        return ['episode_duration_policy' => ShortDramaEpisodeDuration::snapshot([], 0, 0, []),
            'same_scene_cut_policy' => ShortDramaSameSceneCuts::snapshot(['id' => 'test', 'capabilities' => ['supports_same_scene_cuts' => $enabled]])];
    }

    public function testUnknownAndLipSyncPathsDoNotEnableAutomaticCuts(): void
    {
        self::assertFalse(ShortDramaSameSceneCuts::snapshot(['id' => 'famous-model'])['enabled']);
        self::assertFalse(ShortDramaSameSceneCuts::snapshot(['capabilities' => ['supports_same_scene_cuts' => 'true']])['enabled']);
        self::assertFalse(ShortDramaSameSceneCuts::enabled($this->request() + ['video_mode' => 'lip_sync']));
        self::assertStringContainsString('每张卡默认连续镜头', ShortDramaSameSceneCuts::instruction($this->request(false)));
        self::assertTrue(ShortDramaSameSceneCuts::enabled($this->request()));
    }

    public function testCutsAreContiguousAndLimitedAndBound(): void
    {
        $plan = ['subjects' => [['id' => 'a', 'name' => '甲'], ['id' => 'b', 'name' => '乙']]];
        $shot = ['recommended_duration_seconds' => 10, 'subject_ref_ids' => ['a', 'b'], 'camera_movement' => '0–3秒甲近景；3–7秒乙反打；7–10秒甲反应'];
        ShortDramaSameSceneCuts::assertShot($shot, $plan, $this->request());
        self::assertTrue(true);
        $shot['subject_ref_ids'] = ['a'];
        $this->expectException(\RuntimeException::class);
        ShortDramaSameSceneCuts::assertShot($shot, $plan, $this->request());
    }

    public function testNewDurationGuardRejectsSilentCoercion(): void
    {
        ShortDramaEpisodeDuration::assertRenderableDuration(10, 10);
        $this->expectException(\RuntimeException::class);
        ShortDramaEpisodeDuration::assertRenderableDuration(1.5, 4);
    }

    public function testDirectorPromptPreservesCutsAfterEditAndDialogueAttribution(): void
    {
        $camera = '0–3秒甲近景；3–7秒乙反打；7–10秒甲反应';
        $shot = ['shot_id' => '1', 'recommended_duration_seconds' => 10, 'scene_ref_id' => 'room',
            'subject_ref_ids' => ['a', 'b'], 'visual_description' => '甲坐在乙对面', 'camera_movement' => $camera,
            'voice_role' => '甲', 'dialogue' => "甲：钥匙找到了吗？\n乙：在这里。", 'speech_type' => 'character'];
        $edit = new ReflectionMethod(AigcShortDramaService::class, 'editableShotData');
        $edit->setAccessible(true);
        $saved = $edit->invoke(null, $shot);
        self::assertSame($camera, $saved['camera_movement']);
        $saved['subject_ref_ids'] = json_decode($saved['subject_ref_ids'], true);
        $build = new ReflectionMethod(AigcShortDramaService::class, 'buildShotVideoPrompt');
        $build->setAccessible(true);
        $prompt = $build->invoke(null, $shot + $saved, ['duration' => 10], ['subjects' => [['id' => 'a', 'name' => '甲'], ['id' => 'b', 'name' => '乙']], 'locations' => [['id' => 'room', 'name' => '客厅']]]);
        self::assertStringContainsString($camera, $prompt);
        $sound = new ReflectionMethod(AigcShortDramaService::class, 'readableShotSoundText');
        $sound->setAccessible(true);
        self::assertStringContainsString('角色依次说话', $sound->invoke(null, $shot));
        self::assertStringNotContainsString('甲开口说：“甲', $sound->invoke(null, $shot));
    }
}
