<?php
namespace Tests\Feature;
use app\common\service\power\MarketPicLipsyncAppRuntimeService as Runtime;
use app\common\service\power\MarketApplicationApiRuntimeService;
use PHPUnit\Framework\TestCase;
class PicLipsyncContractTest extends TestCase
{
    public function testSixQualityAndDriveCombinationsFollowTheDocument(): void
    {
        foreach (['fast', 'standard', 'max'] as $quality) foreach (['audio', 'text'] as $mode) {
            $payload = Runtime::buildPayload(['locked_params' => ['quality' => $quality, 'model' => 'stale-model']], ['quality' => $quality, 'mode' => $mode, 'image_url' => 'https://fixture.invalid/person.jpg', 'audio_url' => 'https://fixture.invalid/voice.wav', 'content' => '口播内容', 'prompt' => '轻微手势', 'provider_params' => ['quality' => 'fast', 'model' => 'wrong']]);
            self::assertSame('super-lipsync-pro', $payload['model']); self::assertSame($quality, $payload['quality']); self::assertSame($mode, $payload['mode']); self::assertSame('轻微手势', $payload['prompt']);
            if ($mode === 'text') self::assertSame('口播内容', $payload['content']); else self::assertArrayNotHasKey('content', $payload);
            self::assertArrayNotHasKey('provider_params', $payload);
        }
    }
    public function testSelectionCannotSilentlyReplaceTheQuality(): void
    {
        $this->expectException(\Exception::class);
        Runtime::buildPayload(['locked_params' => ['quality' => 'fast']], ['quality' => 'max']);
    }
    public function testMissingReferenceAudioIsRejectedForTextDrive(): void
    {
        $this->expectException(\Exception::class);
        Runtime::buildPayload(['locked_params' => ['quality' => 'standard']], ['mode' => 'text', 'content' => '文案', 'image_url' => 'https://fixture.invalid/person.jpg']);
    }
    public function testMotionPromptCannotReplaceSpokenContent(): void
    {
        $this->expectException(\Exception::class);
        Runtime::buildPayload(['locked_params' => ['quality' => 'max']], ['mode' => 'text', 'prompt' => '轻微手势', 'image_url' => 'https://fixture.invalid/person.jpg', 'audio_url' => 'https://fixture.invalid/voice.wav']);
    }
    public function testInputUsagePreservesFractionAndNeverUsesOutputDuration(): void
    {
        self::assertSame(12.375, Runtime::inputSeconds(['data'=>['usage'=>['input_second'=>12.375]], 'duration'=>30]));
        self::assertSame(0.0, Runtime::inputSeconds(['data'=>['result'=>['duration'=>30]]]));
    }
    public function testCommonWorkerDispatchesThePictureAdapter(): void
    {
        self::assertSame(Runtime::class, MarketApplicationApiRuntimeService::adapterForSelection(['upstream_app_code'=>'pic_lipsync']));
    }
}
