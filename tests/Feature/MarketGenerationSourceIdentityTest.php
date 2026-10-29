<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationSourceIdentity as Identity;
use app\common\service\power\MarketImageModelRuntimeService as Images;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use PHPUnit\Framework\TestCase;

class MarketGenerationSourceIdentityTest extends TestCase
{
    private function product(): array
    {
        return ['resource_type'=>'model','upstream_model_code'=>'fixture-model','upstream_channel_code'=>'fixture-channel',
            'source_payload'=>['resource'=>['model_id'=>94,'model_code'=>'fixture-model','channel_code'=>'fixture-channel'],
                'market_metadata'=>['params_schema'=>['prompt'=>['type'=>'string']],
                    'upstream_metadata'=>['model_code'=>'fixture-model','channel_code'=>'fixture-channel']]]];
    }

    public function testBothMediaRuntimesRecoverTheExistingVerifiedResourceIdentity(): void
    {
        foreach ([Images::class,Videos::class] as $runtime) {
            $p=$this->product();$m=(new \ReflectionMethod($runtime,'metadata'))->invoke(null,$p);
            self::assertSame(94,$m['upstream_metadata']['model_id']);
            self::assertSame($p['source_payload']['market_metadata']['params_schema'],$m['params_schema']);
            self::assertArrayNotHasKey('generation_contract',$m);
        }
    }

    public function testMatchingIdDoesNotOverridePublicAliasesOrDeclareCapabilities(): void
    {
        $m=['model_code'=>'public-alias','model_id'=>94,'capabilities'=>['supports_reference_images'=>false]];
        $r=['model_id'=>94,'model_code'=>'internal-alias','channel_code'=>'fixture'];
        self::assertSame($m,Identity::bind($m,$r));
        self::assertFalse(Identity::matchesDetail($m,['model_pricing'=>['model_id'=>94,'model_code'=>'different-public-code']]));
        self::assertTrue(Identity::matchesDetail($m,['model_pricing'=>['model_id'=>94,'model_code'=>'public-alias']]));
    }

    /** @dataProvider ambiguous */
    public function testIncompleteOrMismatchedResourceCannotInventAPlatformIdentity(array $model, array $resource): void
    {
        self::assertSame($model,Identity::bind($model,$resource));
    }

    public static function ambiguous(): array
    {
        $model=['model_code'=>'fixture','channel_code'=>'a'];$resource=['model_id'=>94,'model_code'=>'fixture','channel_code'=>'a'];
        return [[$model,array_replace($resource,['model_code'=>'another'])],[$model,array_replace($resource,['channel_code'=>'b'])],
            [['model_code'=>'fixture'],$resource],[$model,['model_code'=>'fixture','channel_code'=>'a']],
            [['id'=>94,'model_code'=>'fixture'],$resource]];
    }

    public function testConflictingNumericIdentitiesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Identity::bind(['model_id'=>93],['model_id'=>94]);
    }

    public function testApplicationsCannotBorrowAModelIdentity(): void
    {
        $p=$this->product();$p['resource_type']='app_api';
        $m=$p['source_payload']['market_metadata'];
        self::assertSame($m,Identity::metadata($p,$m));
    }

    public function testAnEmptyEarlierIdDoesNotHideALaterAuthoritativeId(): void
    {
        self::assertSame(94,Identity::modelId(['ai_model_id'=>0,'model_id'=>94]));
        self::assertSame(94,Identity::modelId(['pricing_v2'=>['resource_id'=>94]]));
        self::assertSame(0,Identity::modelId(['id'=>94]));
    }

    public function testMalformedNumericValuesCannotBecomeAPlatformIdentityByCasting(): void
    {
        foreach ([94.5,'94suffix',true,[],'-94','999999999999999999999999'] as $bad) {
            self::assertSame(0,Identity::modelId(['model_id'=>$bad]));
            self::assertFalse(Identity::matchesDetail(['model_id'=>94,'model_code'=>'fixture'],['model_pricing'=>['model_id'=>$bad,'model_code'=>'fixture']]));
        }
        self::assertSame(94,Identity::modelId(['model_id'=>'94']));
    }

    public function testReadOnlyPlatformAuditSeparatesSuccessfulBindingFromCompleteContracts(): void
    {
        $r=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/market-platform-contract-flow-review-20261029.json'),true,512,JSON_THROW_ON_ERROR);
        self::assertCount(18,$r['models']);
        $read=array_filter($r['models'],static fn($m)=>$m['read_state']==='read');
        self::assertCount(17,$read);
        foreach ($read as $m) {
            self::assertTrue($m['platform_identity_verified']);
            self::assertSame('unverified',$m['runtime_readiness']);
        }
        $pending=array_values(array_filter($r['models'],static fn($m)=>$m['read_state']!=='read'));
        self::assertSame('gpt-image-2',$pending[0]['model_code']);
        self::assertSame('OpenAIYI',$pending[0]['channel_code']);
        self::assertFalse($pending[0]['platform_identity_verified']);
    }

    public function testApplicationPricingIdentityIsIndependentOfModelsAndOtherApis(): void
    {
        $identity=['app_code'=>'wan','api_code'=>'create','api_id'=>37];
        $detail=['api_pricing'=>['app_code'=>'wan','code'=>'create','id'=>37,'params_schema'=>['private'=>'not returned']]];
        self::assertTrue(Identity::matchesApplicationDetail($identity,$detail));
        foreach([['app_code'=>'other'],['code'=>'query'],['id'=>38],['id'=>'37suffix'],['id'=>true]]as$change)
            self::assertFalse(Identity::matchesApplicationDetail($identity,['api_pricing'=>array_replace($detail['api_pricing'],$change)]));
        self::assertFalse(Identity::matchesApplicationDetail($identity,['model_pricing'=>$detail['api_pricing']]));
        self::assertFalse(Identity::matchesApplicationDetail(['app_code'=>'wan'],$detail));
        self::assertFalse(Identity::matchesApplicationDetail(array_replace($identity,['api_id'=>'37suffix']),$detail));
        unset($identity['api_id']);self::assertTrue(Identity::matchesApplicationDetail($identity,$detail));
    }
}
