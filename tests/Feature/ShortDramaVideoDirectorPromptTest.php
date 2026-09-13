<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use PHPUnit\Framework\TestCase;

class ShortDramaVideoDirectorPromptTest extends TestCase
{
    private function build(array $params): string
    {
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'buildShotVideoPrompt');
        $method->setAccessible(true);
        return $method->invoke(null, [
            'shot_id' => '10', 'recommended_duration_seconds' => 5,
            'time_range' => '00:38-00:43', 'shot_type' => '空镜',
            'visual_description' => '雨滴落在窗台上', 'subject_ref_ids' => [],
            'video_prompt' => '分镜10：00:38-00:43',
        ], $params, []);
    }

    public function testSubmittedDirectorTextDoesNotRegainDeletedTimelineHeader(): void
    {
        $text = "景别：远景\n画面内容：雨滴落在窗台上\n声音：只有雨声";
        foreach (['video_prompt', 'visible_prompt', 'message'] as $key) {
            foreach ([[$key => $text], ['params' => [$key => $text]]] as $input) {
                $prompt = $this->build($input + ['duration' => 8]);
                self::assertStringNotContainsString('分镜10：', $prompt);
                self::assertStringNotContainsString('00:38-00:43', $prompt);
                self::assertStringContainsString('雨滴落在窗台上', $prompt);
                self::assertStringContainsString('<duration-ms>8000</duration-ms>', $prompt);
                self::assertStringContainsString('当前绑定场景：', $prompt);
            }
        }
    }

    public function testSystemDefaultStillCompletesDirectorColumns(): void
    {
        $prompt = $this->build(['duration' => 5]);
        self::assertStringContainsString('分镜10：', $prompt);
        self::assertStringContainsString('画面内容：', $prompt);
        self::assertStringContainsString('<duration-ms>5000</duration-ms>', $prompt);
    }
}
