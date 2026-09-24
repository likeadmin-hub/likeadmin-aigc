<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\power\MarketVideoAppRuntimeService;
use app\common\service\power\MarketVideoModelRuntimeService;
use app\common\service\power\MarketVideoRuntimeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ShortDramaVideoAudioContractTest extends TestCase
{
    public function testDisablingAudioRemovesStoryboardSoundDirectionsAndAddsMuteRule(): void
    {
        $prompt = "景别：远景\n声音：环境音，轻柔爵士乐，人声低语\n固定要求：无字幕";

        $result = $this->invoke('applyShortDramaVideoAudioPreference', $prompt, false);

        self::assertStringNotContainsString('轻柔爵士乐', $result);
        self::assertStringContainsString('视频必须静音', $result);
        self::assertStringContainsString('不要生成背景音乐、环境音、音效、人声、对白或旁白', $result);
    }

    public function testEnablingAudioPreservesStoryboardSoundDirections(): void
    {
        $prompt = "景别：远景\n声音：环境音，轻柔爵士乐";

        self::assertSame($prompt, $this->invoke('applyShortDramaVideoAudioPreference', $prompt, true));
    }

    public function testStoryboardAudioSwitchDefaultsToEnabled(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/web/pc/components/short-drama/StoryboardCreationWorkbench.vue');

        self::assertStringContainsString('const videoGenerateAudio = ref(true)', $source);
        self::assertStringContainsString('const batchVideoGenerateAudio = ref(true)', $source);
    }

    public function testAudioGenerationDefaultsToEnabledButAnExplicitDisableWins(): void
    {
        self::assertTrue($this->invoke('shouldGenerateShortDramaVideoAudio', []));
        self::assertFalse($this->invoke('shouldGenerateShortDramaVideoAudio', ['generate_audio' => false]));
        self::assertFalse($this->invoke('shouldGenerateShortDramaVideoAudio', ['params' => ['generate_audio' => false]]));
    }

    public function testOnlyContractsWithAnAudioParameterCanDisableAudio(): void
    {
        self::assertFalse($this->invokeRuntime('supportsAudioGeneration', ['upstream_app_code' => 'full_video'], ['params_schema' => []]));
        self::assertTrue($this->invokeRuntime('supportsAudioGeneration', ['upstream_app_code' => 'seedance'], ['params_schema' => []]));
        self::assertTrue($this->invokeRuntime('supportsAudioGeneration', [], ['params_schema' => ['generate_audio' => ['type' => 'boolean']]]));
    }

    public function testSeedanceReceivesItsAudioChoiceWithoutLeakingItToFullVideo(): void
    {
        $request = ['prompt' => 'Synthetic video', 'duration' => 4, 'generate_audio' => false];
        $seedance = $this->invokeRuntime('appPayload', ['app_code' => 'seedance', 'locked_params' => []], $request, 'audio-test');
        $fullVideo = $this->invokeRuntime('appPayload', [
            'app_code' => 'full_video', 'model_code' => 'full-video', 'locked_params' => ['resolution' => '480P'],
        ], $request, 'audio-test');

        self::assertFalse($seedance['generate_audio']);
        self::assertArrayNotHasKey('generate_audio', $fullVideo);
        self::assertArrayNotHasKey('audio', $fullVideo);
    }

    public function testEveryVideoRuntimeFacadeExposesTheAudioCapabilityContract(): void
    {
        foreach ([MarketVideoModelRuntimeService::class, MarketVideoAppRuntimeService::class] as $runtime) {
            self::assertTrue(is_callable([$runtime, 'supportsGenerateAudio']));
        }
    }

    public function testMissingAudioCapabilityFallsBackWithoutBlockingVideoGeneration(): void
    {
        self::assertTrue($this->invoke('marketVideoSupportsGenerateAudio', AudioCapableVideoRuntimeFixture::class, 1, []));
        self::assertFalse($this->invoke('marketVideoSupportsGenerateAudio', NoAudioCapabilityVideoRuntimeFixture::class, 1, []));
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(AigcShortDramaService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $arguments);
    }

    private function invokeRuntime(string $method, mixed ...$arguments): mixed
    {
        $reflection = new ReflectionMethod(MarketVideoRuntimeService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $arguments);
    }
}

final class AudioCapableVideoRuntimeFixture
{
    public static function supportsGenerateAudio(int $tenantId, array $selection): bool
    {
        return true;
    }
}

final class NoAudioCapabilityVideoRuntimeFixture
{
}
