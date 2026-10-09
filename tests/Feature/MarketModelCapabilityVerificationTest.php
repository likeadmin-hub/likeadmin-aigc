<?php
namespace Tests\Feature;

use app\common\service\power\MarketModelCapabilityVerification as Verification;
use app\common\service\power\PowerMarketService as Market;
use app\common\service\power\MarketTextModelRuntimeService as Runtime;
use PHPUnit\Framework\TestCase;

class MarketModelCapabilityVerificationTest extends TestCase
{
    private function product(): array
    {
        $p=['resource_type'=>'model','source_code'=>'test_source','upstream_resource_key'=>'model:test:a',
            'upstream_model_code'=>'test','upstream_channel_code'=>'a',
            'source_payload'=>['market_metadata'=>['supports_vision'=>false,'capabilities'=>['supports_vision'=>false]]]];
        $p['source_payload'][Verification::KEY]=['identity'=>Verification::identity($p),'method'=>'image_probe',
            'supports_vision'=>true,'verified_at'=>1234567890,'app_task_id'=>10,'provider_request_id'=>'probe-test'];
        return $p;
    }

    public function testVerifiedEvidenceOverridesIncorrectMetadataForDisplayAndRuntime(): void
    {
        $p=$this->product();$cap=Market::capability($p);
        self::assertTrue($cap['supports_vision']);self::assertContains('image',$cap['input_modalities']);
        self::assertSame(0,$cap['max_reference_images']); // One successful image does not establish the limit.
        $method=new \ReflectionMethod(Runtime::class,'supportsVision');$method->setAccessible(true);
        self::assertTrue($method->invoke(null,$p['source_payload'],$p));
        unset($p['source_payload'][Verification::KEY]);
        self::assertFalse($method->invoke(null,$p['source_payload'],$p));
    }

    public function testSyncKeepsOnlyExistingEvidenceForExactIdentity(): void
    {
        $p=$this->product();$fresh=['market_metadata'=>['supports_vision'=>false]];
        $merged=Verification::carryForward($fresh,$p,$p);
        self::assertSame($p['source_payload'][Verification::KEY],$merged[Verification::KEY]);
        self::assertFalse($merged['market_metadata']['supports_vision']);
        foreach(['source_code','upstream_resource_key','upstream_model_code','upstream_channel_code'] as $key){
            $other=$p;$other[$key]='changed';
            self::assertArrayNotHasKey(Verification::KEY,Verification::carryForward($fresh,$p,$other));
            self::assertFalse(Verification::vision($other));
        }
        $empty=$p;unset($empty['source_payload'][Verification::KEY]);
        self::assertArrayNotHasKey(Verification::KEY,Verification::carryForward($p['source_payload'],$empty,$empty));
    }

    public function testIncompleteEvidenceAndDescriptionsDoNotEnableVision(): void
    {
        foreach(['identity','method','verified_at','app_task_id','provider_request_id','supports_vision'] as $key){
            $p=$this->product();unset($p['source_payload'][Verification::KEY][$key]);self::assertFalse(Verification::vision($p));
        }
        $p=$this->product();unset($p['source_payload'][Verification::KEY]);$p['description']='原生支持视觉理解';
        self::assertFalse(Market::capability($p)['supports_vision']);
    }

    public function testNestedAndLegacyCapabilitiesRemainSupportedWithoutProof(): void
    {
        $method=new \ReflectionMethod(Runtime::class,'supportsVision');$method->setAccessible(true);
        foreach([['capabilities'=>['supports_vision'=>true]],['capabilities'=>['vision']],['input_modalities'=>['image']],['supports_vision'=>true]] as $meta){
            self::assertTrue($method->invoke(null,['market_metadata'=>$meta]));
        }
        self::assertFalse($method->invoke(null,['market_metadata'=>['capabilities'=>['supports_vision'=>false]]]));
    }
}
