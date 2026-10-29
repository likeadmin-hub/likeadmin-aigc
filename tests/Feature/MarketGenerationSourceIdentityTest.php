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
}
