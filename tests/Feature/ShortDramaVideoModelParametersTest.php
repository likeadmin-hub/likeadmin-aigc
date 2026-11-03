<?php

namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{AigcShortDramaService, ShortDramaPromptCatalog, ShortDramaPromptWorkspace, ShortDramaVideoModelParameters};
use app\common\service\power\{MarketCreationParameters, MarketGenerationRequest, MarketVideoRuntimeService};
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Real prompt assembler to native payload; no supplier, billing, database or uploads. */
class ShortDramaVideoModelParametersTest extends TestCase
{
    public function testDeclaredControlsSurviveTheActualPromptAssemblerIncludingFalseAndZero(): void
    {
        $params = MarketCreationParameters::applyOptions($this->params() + ['advanced_params' => [
            'multi_shots' => false, 'seed' => 0, 'generate_audio' => false,
        ]], [$this->option()], 'video');
        $request = $this->assemble($params, false);
        self::assertSame($params['provider_params'], $request['provider_params']);
        self::assertSame($params['advanced_params'], $request['advanced_params']);
        self::assertSame(0, $request['seed']);
        self::assertFalse($request['generate_audio']);
        self::assertStringContainsString('视频必须静音', $request['prompt']);
        $payload = MarketGenerationRequest::payload($this->snapshot(), $request);
        self::assertFalse($payload['multi_shots']);
        self::assertSame(0, $payload['seed']);
        self::assertFalse($payload['generate_audio']);
    }

    public function testSingleShotChoiceSatisfiesARequiredControlWithoutInventingModelDefaults(): void
    {
        $request = $this->assemble($this->params());
        $payload = MarketGenerationRequest::payload($this->snapshot(), $request);
        self::assertFalse($payload['multi_shots']);
        self::assertSame(8, $payload['seed']);
        $snapshot = $this->snapshot();
        unset($snapshot['market_metadata']['params_schema']['multi_shots']);
        self::assertArrayNotHasKey('multi_shots', MarketGenerationRequest::payload($snapshot, $request));
    }

    public function testExplicitChoiceAndSkuLocksStillBelongToTheSharedContract(): void
    {
        $request = $this->assemble($this->params() + ['provider_params' => ['multi_shots' => true, 'seed' => 17]]);
        self::assertTrue(MarketGenerationRequest::payload($this->snapshot(), $request)['multi_shots']);
        $snapshot = $this->snapshot();
        $snapshot['locked_params'] = ['seed' => 99, 'multi_shots' => false];
        $payload = MarketGenerationRequest::payload($snapshot, $request);
        self::assertSame(99, $payload['seed']);
        self::assertFalse($payload['multi_shots']);
    }

    public function testNestedOptionsAreCarriedAndTopLevelOptionsRemainAuthoritative(): void
    {
        $nested = ['provider_params' => ['seed' => 0, 'multi_shots' => false]];
        $normalized = ShortDramaVideoModelParameters::normalize(0, ['params' => $nested]);
        self::assertSame($nested['provider_params'], ShortDramaVideoModelParameters::carry($normalized)['provider_params']);
        $normalized = ShortDramaVideoModelParameters::normalize(0, ['params' => $nested, 'provider_params' => ['seed' => 17]]);
        self::assertSame(['seed' => 17], ShortDramaVideoModelParameters::carry($normalized)['provider_params']);
    }

    public function testLegacyAdapterReceivesValidatedFlatControlMirrors(): void
    {
        $params = MarketCreationParameters::applyOptions($this->params() + ['advanced_params' => ['seed' => 0, 'generate_audio' => false]], [$this->option()], 'video');
        $request = $this->assemble($params, false);
        $payload = (new ReflectionMethod(MarketVideoRuntimeService::class, 'modelPayload'))->invoke(null, [
            'model_code' => 'offline-video', 'channel_code' => 'offline',
            'params_schema' => ['prompt' => ['type' => 'string'], 'duration' => ['type' => 'integer'],
                'seed' => ['type' => 'integer'], 'generate_audio' => ['type' => 'boolean']], 'locked_params' => [],
        ], $request, 'offline-only');
        self::assertSame(0, $payload['seed']);
        self::assertFalse($payload['generate_audio']);
        self::assertArrayNotHasKey('multi_shots', $payload);
    }

    public function testUnsupportedControlsAreRejectedByTheExistingValidator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MarketCreationParameters::applyOptions($this->params() + ['advanced_params' => ['not_declared' => true]], [$this->option()], 'video');
    }

    public function testModelOptionsCannotReplaceBusinessOwnedFields(): void
    {
        $request = $this->assemble($this->params() + ['advanced_params' => ['prompt' => 'ignored', 'duration' => 99], 'provider_params' => []]);
        self::assertSame(5, $request['duration']);
        self::assertSame('offline-video', $request['model_id']);
        self::assertSame([], $request['reference_assets']);
        self::assertStringContainsString('用户当前画面', $request['prompt']);
    }

    private function params(): array
    {
        return ['duration' => 5, 'model_id' => 'offline-video', 'resolution' => '', 'video_prompt' => '用户当前画面'];
    }

    private function option(): array
    {
        return ['id' => 'offline-video', 'value' => 'offline-video', 'resource_type' => 'model',
            'params_schema' => $this->snapshot()['market_metadata']['params_schema']];
    }

    private function snapshot(): array
    {
        return ['resource_type' => 'model', 'locked_params' => [], 'market_metadata' => [
            'params_schema' => ['prompt' => ['type' => 'string', 'required' => true],
                'duration' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30, 'required' => true],
                'multi_shots' => ['type' => 'boolean', 'required' => true],
                'generate_audio' => ['type' => 'boolean'], 'seed' => ['type' => 'integer', 'minimum' => 0, 'default' => 8]],
            'material_capabilities' => ['modes' => [['code' => '', 'method' => 'text_to_video', 'fields' => []]]],
        ]];
    }

    private function assemble(array $params, bool $audio = true): array
    {
        $snapshot = ShortDramaPromptWorkspace::resolve(701, ['mode' => 'documents',
            'document_settings' => ['shot_video' => ['mode' => 'custom', 'body' => '保持画面连贯']]], []);
        return ShortDramaPromptCatalog::run($snapshot, static fn() => (new ReflectionMethod(AigcShortDramaService::class, 'assembleVideoPromptRequest'))->invoke(null,
            701, ['shot_id' => '1', 'visual_description' => '用户当前画面'], $params, [], '16:9', [
                'generation_method' => 'text_to_video', 'reference_assets' => [], 'input_asset_ids' => [],
                'reference_plan' => [], 'first_frame_image' => false, 'last_frame_image' => false,
            ], $audio));
    }
}
