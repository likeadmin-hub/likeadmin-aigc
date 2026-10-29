<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MarketGenerationMaterialPreservationTest extends TestCase
{
    /** @dataProvider imageOnlyLegacyModels */
    public function testUnsupportedVideoOrAudioCannotDisappearInLegacyPreparation(string $code, string $kind): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/canvas-generation-market-contracts.json'),true,512,JSON_THROW_ON_ERROR);
        $metadata = null;
        foreach ($fixture['contracts'] as $item) if (($item['resource_type'] ?? '') === 'model' && ($item['model_code'] ?? '') === $code) { $metadata = $item; break; }
        self::assertNotNull($metadata);
        $snapshot = ['resource_type'=>'model','model_code'=>$code,'channel_code'=>$metadata['channel_code'],
            'locked_params'=>[],'market_metadata'=>$metadata,'params_schema'=>$metadata['params_schema']];
        $this->expectExceptionMessage('不支持部分参考素材');
        $choices=\app\common\service\power\MarketGenerationSchema::durationChoices($metadata['params_schema']['duration'] ?? []);
        Videos::prepareRequest($snapshot, ['prompt'=>'Offline fixture','duration'=>$choices[0] ?? 8,'ratio'=>'9:16',
            'generation_method'=>$kind === 'video' ? 'video_edit' : 'audio_reference',
            'reference_assets'=>[['type'=>$kind,'role'=>'reference_'.$kind,'url'=>'https://fixtures.invalid/reference.'.$kind]]]);
    }

    public static function imageOnlyLegacyModels(): array
    {
        $rows = [];
        foreach (['grok-video','veo3.1-fast','veo3.1-pro'] as $code) foreach (['video','audio'] as $kind) $rows[] = [$code,$kind];
        return $rows;
    }

    public function testSlotCountAndPromptTextCannotHideDroppedMaterial(): void
    {
        $url = 'https://fixtures.invalid/shared.png';
        Materials::assertPreserved(['reference_images'=>[$url,$url]], ['image_urls'=>[$url,$url]]);
        $this->expectException(InvalidArgumentException::class);
        Materials::assertPreserved(['reference_images'=>[$url,$url]], ['image_urls'=>[$url],'prompt'=>$url]);
    }

    public function testSeedanceStillPreservesAllMaterialKindsBeforeUpload(): void
    {
        $assets = [];
        foreach (['image','video','audio'] as $kind) $assets[] = ['type'=>$kind,'role'=>'reference_'.$kind,'url'=>'https://fixtures.invalid/reference.'.$kind];
        $prepared = Videos::prepareRequest(['resource_type'=>'app_api','app_code'=>'seedance','locked_params'=>[]], [
            'prompt'=>'Offline fixture','duration'=>4,'reference_assets'=>$assets,
        ]);
        self::assertCount(4,$prepared->nativeInput['content']);
        Materials::assertPreserved(['reference_assets'=>$assets], $prepared->nativeInput);
    }
}
