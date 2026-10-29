<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationCompatibilityAudit as Audit;
use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use app\common\service\power\MarketGenerationNativeContract as Native;
use PHPUnit\Framework\TestCase;

class MarketGenerationStructuredFixtureTest extends TestCase
{
    public static function structures(): array
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/market-structured-public-contracts-20261029.json'),true,512,JSON_THROW_ON_ERROR);
        $rows = [];
        foreach ($fixture['entries'] as $entry) {
            $option = $entry['options'] + ['material_modes'=>Materials::modes($entry['metadata'])];
            foreach (Audit::cases($entry['generation_type'],$option,$entry['metadata']) as $case) {
                if (isset($case['expected'])) $rows[$entry['model_code'].':'.$case['case']] = [$entry,$case];
            }
        }
        return $rows;
    }

    /** @dataProvider structures */
    public function testReviewedStructuredInputsAndInvalidVariants(array $entry, array $case): void
    {
        self::assertNotEmpty(Native::modes($entry['metadata']), 'Fixture must bind the actual saved native contract');
        $snapshot = ['model_code'=>$entry['model_code'],'channel_code'=>$entry['channel_code'],'market_metadata'=>$entry['metadata'],'locked_params'=>[]];
        $prepare = $entry['generation_type'] === 'image'
            ? static fn($request)=>Images::prepareRequest($snapshot,$request)->nativeInput
            : static fn($request)=>Videos::prepareRequest($snapshot,$request)->nativeInput;
        $result = Audit::probe($case,$prepare);
        self::assertSame($case['expected'],$result['status'],json_encode([$case['case'],$result]));
    }

    public function testUnknownNestedFieldMustNotBeSilentlyNormalizedAway(): void
    {
        $schema = ['type'=>'object','properties'=>['items'=>['type'=>'array','items'=>[
            'type'=>'object','additionalProperties'=>false,'properties'=>['name'=>['type'=>'string']]]]]];
        $this->expectException(\InvalidArgumentException::class);
        \app\common\service\power\MarketGenerationSchema::normalize($schema,['items'=>[['name'=>'fixture','unknown'=>false]]]);
    }

    public function testOpaqueSubjectCannotAcquireARecipeFromAnExample(): void
    {
        $option = ['material_modes'=>[['key'=>'opaque','native_mode'=>'','fields'=>[['field'=>'elements','kind'=>'subject']],
            'parameter_schema'=>['properties'=>['elements'=>['type'=>'array','example'=>[['name'=>'do not use']]]]]]]];
        $cases=Audit::cases('video',$option,[]);
        self::assertSame('needs_structured_fixture',Audit::probe($cases[0],static fn()=>[])['status']);
        self::assertStringNotContainsString('do not use',json_encode($cases));
    }

    public function testProbeChecksStructuredValuesAsWellAsUrls(): void
    {
        $case=['case'=>'fixture','mode_case'=>'fixture','expected'=>'constructed','fixture_scope'=>'offline','skip'=>false,
            'request'=>['provider_params'=>['clips'=>[['url'=>'https://fixtures.invalid/a.mp4','start'=>0,'ends'=>1]]], 'reference_assets'=>[]]];
        $native=['clips'=>[['url'=>'https://fixtures.invalid/a.mp4','start'=>0,'ends'=>2]]];
        self::assertSame('structured_parameter_not_forwarded',Audit::probe($case,static fn()=>$native)['status']);
        self::assertSame('constructed',Audit::probe($case,static fn()=>$case['request']['provider_params'])['status']);
    }
}
