<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationCompatibilityAudit as Audit;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use PHPUnit\Framework\TestCase;

class MarketGenerationCompatibilityAuditTest extends TestCase
{
    public function testIdenticalFieldNamesDoNotConcealChangedNestedConstraints(): void
    {
        $captured = ['properties'=>['input'=>['type'=>'object','properties'=>['images'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>2]]]]];
        $current = $captured; $current['properties']['input']['properties']['images']['maxItems'] = 1;
        $review = Audit::review($current, $captured);
        self::assertTrue($review['top_level_fields_match']);
        self::assertFalse($review['declaration_matches']);
        self::assertSame(['input'], $review['changed_fields']);
        self::assertSame('unverified', $review['current_upstream_contract']);
        self::assertSame([], $review['opaque_structures']);
    }

    public function testExamplesCannotCompleteAnOpaqueContract(): void
    {
        $schema = ['input'=>['type'=>'object','example'=>['secret'=>'never printed']], 'urls'=>['type'=>'array']];
        $review = Audit::review($schema, $schema);
        self::assertSame(['input','urls'], $review['opaque_structures']);
        self::assertStringNotContainsString('never printed', json_encode($review));
        self::assertNull(Audit::review($schema, null)['declaration_matches']);
    }

    public function testMissingModesOrDefaultFalseFlagsRemainUnknown(): void
    {
        $rows = array_column(Audit::scenarios('video', ['supports_first_last_frame'=>false]), null, 'scenario');
        self::assertNull($rows['start_end']['declared_support']);
        $rows = array_column(Audit::scenarios('video', [], ['upstream_metadata'=>['supports_first_last_frame'=>false]]), null, 'scenario');
        self::assertFalse($rows['start_end']['declared_support']);
        self::assertNull($rows['text_to_video']['declared_support']);
        $rows = array_column(Audit::scenarios('video', ['material_modes'=>[['key'=>'first','method'=>'image_to_video','fields'=>[]]]]), null, 'scenario');
        self::assertTrue($rows['image_to_video']['declared_support']);
        self::assertSame('unverified', $rows['image_to_video']['sku_combinations']);
        self::assertSame('unverified', $rows['image_to_video']['live_generation']);
    }

    public function testRecognizedMeterDoesNotClaimPricingOrScenarioVerification(): void
    {
        $sku = ['id'=>42, 'usage_unit'=>'business_unit','locked_params'=>['resolution'=>'4K','api_key'=>'secret'],
            'meter_contract'=>['version'=>1,'basis'=>'image','rounding'=>'ceil']];
        $row = Audit::sku('image','model',$sku);
        self::assertSame('recognized', $row['meter_status']);
        self::assertSame('unverified', $row['scenario_pricing']);
        self::assertStringNotContainsString('secret', json_encode($row));
        self::assertSame('unsupported_or_invalid_meter', Audit::sku('image','app_api',$sku)['meter_status']);
        $sku['meter_contract']['basis'] = 'megapixel';
        self::assertSame('requires_verified_pixels', Audit::sku('image','model',$sku)['meter_status']);
        self::assertSame('requires_submission_token_budget', Audit::sku('video','app_api',['usage_unit'=>'token'])['meter_status']);
    }

    public function testProbeUsesTheActualPureCompilerAndDetectsDroppedMaterials(): void
    {
        $option = ['params_schema'=>['prompt'=>['type'=>'string'],'image_urls'=>['type'=>'array','items'=>['type'=>'string'],'maxItems'=>1]],
            'material_modes'=>[]];
        $cases = Audit::cases('image',$option);
        $snapshot = ['model_code'=>'new-declared-image','channel_code'=>'fixture', 'market_metadata'=>[
            'supports_reference_images'=>true,'max_reference_images'=>1,'params_schema'=>$option['params_schema']]];
        $prepare = static fn($request) => Images::prepareRequest($snapshot,$request)->nativeInput;
        self::assertSame('constructed', Audit::probe($cases[0], $prepare)['status']);
        self::assertSame('constructed', Audit::probe($cases[1], $prepare)['status']);
        self::assertSame('material_not_forwarded', Audit::probe($cases[1], static fn($r)=>['prompt'=>$r['prompt']])['status']);
        self::assertSame('prompt_not_forwarded', Audit::probe($cases[0], static fn()=>[])['status']);
        $case = $cases[1]; $case['request']['reference_images'][] = 'https://fixtures.invalid/extra.png';
        self::assertSame('rejected', Audit::probe($case, $prepare)['status']);
    }

    public function testComplexMaterialNeedsAnExplicitFixtureAndErrorsAreRedacted(): void
    {
        $cases = Audit::cases('video',['material_modes'=>[['key'=>'subject','method'=>'omni_reference','fields'=>[['kind'=>'subject']]]]]);
        self::assertSame('needs_structured_fixture', Audit::probe($cases[0], static fn()=>[])['status']);
        $case = Audit::cases('image',[])[0];
        $result = Audit::probe($case, static function () { throw new \InvalidArgumentException('private input must not print'); });
        self::assertSame('rejected', $result['status']);
        self::assertStringNotContainsString('private input', json_encode($result));
    }

    public function testNativeAnimationContractWithoutPromptDoesNotCreateFalsePromptLoss(): void
    {
        $cases = Audit::cases('video',['material_modes'=>[['key'=>'animation','method'=>'video_edit','fields'=>[],
            'parameter_schema'=>['properties'=>['video_url'=>['type'=>'string']]]]]]);
        self::assertArrayNotHasKey('prompt',$cases[0]['request']);
        self::assertSame('constructed', Audit::probe($cases[0], static fn()=>[])['status']);
    }

    public function testReadOnlyCurrentReviewBindsDeclarationsWithoutGrantingCapabilities(): void
    {
        $fixtures = dirname(__DIR__).'/fixtures/';
        $review = json_decode(file_get_contents($fixtures.'market-legacy-declaration-review-20261029.json'),true,512,JSON_THROW_ON_ERROR);
        $captured = json_decode(file_get_contents($fixtures.'canvas-generation-market-contracts.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertCount(24,$review['entries']);
        self::assertSame(31,array_sum(array_column($review['entries'],'catalogue_options')));
        foreach ($review['entries'] as $entry) {
            $matches = array_values(array_filter($captured['contracts'],static fn($c)=>$c['resource_type'] === $entry['resource_type'] && (
                $entry['resource_type'] === 'model' ? $c['model_code'] === $entry['model_code'] && $c['channel_code'] === $entry['channel_code']
                    : $c['app_code'] === $entry['app_code']
            )));
            self::assertCount(1,$matches);
            self::assertSame(Audit::fingerprint($matches[0]['params_schema']),$entry['captured_parameter_fingerprint']);
            self::assertSame($entry['captured_parameter_fingerprint'],$entry['current_parameter_fingerprint']);
            self::assertArrayNotHasKey('supported',$entry);
        }
    }
}
