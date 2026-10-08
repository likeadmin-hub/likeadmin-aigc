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
        $text = "分镜10：00:38-00:43\n景别：远景\n画面内容：雨滴落在窗台上\n声音：只有雨声";
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
        self::assertStringNotContainsString('分镜10：', $prompt);
        self::assertStringNotContainsString('00:38-00:43', $prompt);
        self::assertStringContainsString('画面内容：', $prompt);
        self::assertStringContainsString('<duration-ms>5000</duration-ms>', $prompt);
    }
    public function testMultiSpeakerSoundIsPreservedInDefaultAndSubmittedProviderPrompt(): void
    {
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'buildShotVideoPrompt');
        $method->setAccessible(true);
        $shot = ['shot_id'=>'1', 'subject_ref_ids'=>['a','b'], 'shot_type'=>'普通画面',
            'dialogue'=>"甲：先别动。\n乙：我没有人物画像。\n甲：听我说。", 'voice_role'=>'甲',
            'visual_description'=>'两人对视', 'sound_effect'=>'脚步声'];
        foreach ([[], ['video_prompt'=>"景别：近景\n画面内容：两人对视"]] as $params) {
            $prompt = $method->invoke(null, $shot, $params, []);
            foreach (['先别动。','我没有人物画像。','听我说。'] as $words) self::assertStringContainsString($words, $prompt);
            self::assertStringNotContainsString('全程无人说话', $prompt);
            self::assertStringNotContainsString('全程不说话', $prompt);
        }
    }

    public function testEmptyShotPreservesNarrationInsteadOfForcingSilence(): void
    {
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'readableShotSoundText');
        $method->setAccessible(true);
        $prompt = $method->invoke(null, ['shot_type'=>'空镜','subject_ref_ids'=>[],
            'dialogue'=>'第二天清晨。','voice_role'=>'','speech_type'=>'narration']);
        self::assertStringContainsString('旁白（画外音）：“第二天清晨。”', $prompt);
        self::assertStringNotContainsString('无人说话', $prompt);
        $offscreen = $method->invoke(null, ['shot_type'=>'空镜','subject_ref_ids'=>[],
            'dialogue'=>'有人吗？','voice_role'=>'甲','speech_type'=>'character']);
        self::assertStringContainsString('甲（画外音）：“有人吗？”', $offscreen);
    }

    public function testExplicitlyAuthoredAudioIsNotOverwrittenByScriptDialogue(): void
    {
        $method = new \ReflectionMethod(AigcShortDramaService::class, 'buildShotVideoPrompt');
        $method->setAccessible(true);
        $prompt = $method->invoke(null, ['shot_id'=>'1','shot_type'=>'普通画面',
            'dialogue'=>'甲：原台词。','voice_role'=>'甲','subject_ref_ids'=>['a']],
            ['video_prompt'=>"画面内容：甲看向窗外\n声音：无对白，只保留雨声"], []);
        self::assertStringContainsString('声音：无对白，只保留雨声', $prompt);
        self::assertStringNotContainsString('原台词', $prompt);
    }
}
