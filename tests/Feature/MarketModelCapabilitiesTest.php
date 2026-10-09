<?php
namespace Tests\Feature;
use app\common\service\power\MarketModelCapabilities as C;
use app\common\service\power\MarketModelCapabilityVerification as V;
use app\common\service\power\PowerMarketService as Market;
use app\common\service\power\MarketTextModelRuntimeService as Text;
use app\common\service\power\MarketImageModelRuntimeService as Image;
use app\common\service\power\MarketVideoRuntimeService as Video;
use PHPUnit\Framework\TestCase;

class MarketModelCapabilitiesTest extends TestCase
{
    private function product(array $meta=[],array $overrides=[]): array
    {
        $p=['resource_type'=>'model','source_code'=>'test','upstream_resource_key'=>'model:test:channel','upstream_model_code'=>'test','upstream_channel_code'=>'channel','source_payload'=>['market_metadata'=>$meta]];
        $p['source_payload'][C::KEY]=['identity'=>V::identity($p),'values'=>$overrides,'updated_at'=>123];return $p;
    }
    private function fields(array $p): array {return array_column(C::profile($p)['fields'],null,'key');}
    public function testMissingFalseAndTrueAreDistinct(): void
    {
        $p=$this->product(['supports_vision'=>false,'upstream_metadata'=>['model_code'=>'test','supports_tools'=>false,'supports_reasoning'=>true]]);
        $f=$this->fields($p);self::assertNull($f['supports_vision']['value']);self::assertSame('unknown',$f['supports_vision']['source']);
        self::assertFalse($f['supports_tools']['value']);self::assertTrue($f['supports_reasoning']['value']);
    }
    public function testManualFalseWinsAndAutomaticCanBeRestored(): void
    {
        $p=$this->product(['supports_vision'=>true],['supports_vision'=>false,'max_output_tokens'=>4096]);
        self::assertFalse(Market::capability($p)['supports_vision']);
        $m=new \ReflectionMethod(Text::class,'supportsVision');$m->setAccessible(true);self::assertFalse($m->invoke(null,$p['source_payload'],$p));
        self::assertSame('manual',$this->fields($p)['supports_vision']['source']);
        $p['source_payload'][C::KEY]['values']=C::validate(['supports_vision'=>null]);self::assertTrue(Market::capability($p)['supports_vision']);
    }
    public function testSyncPreservesLocalValuesOnlyForSameIdentity(): void
    {
        $p=$this->product([],['supports_vision'=>true]);
        self::assertSame($p['source_payload'][C::KEY],C::carryForward([],$p,$p)[C::KEY]);
        $other=$p;$other['upstream_channel_code']='other';self::assertArrayNotHasKey(C::KEY,C::carryForward([],$p,$other));self::assertSame([],C::overrides($other));
    }
    public function testInvalidFieldsCannotBecomeProviderConfiguration(): void
    {
        foreach([['api_key'=>'secret'],['supports_vision'=>'false'],['max_output_tokens'=>-1],['input_modalities'=>'image'],['input_modalities'=>[['url'=>'x']]]] as $bad){
            try{C::validate($bad);self::fail('accepted invalid correction');}catch(\InvalidArgumentException){self::assertTrue(true);}
        }
    }
    public function testSchemaAndExtensionsAreVisibleWithoutSecretsOrInventedLimits(): void
    {
        $p=$this->product(['params_schema'=>['tools'=>['type'=>'array'],'api_key'=>['type'=>'string','default'=>'secret']],
            'capabilities'=>['supports_cache'=>true,'api_key'=>'secret']]);
        $profile=C::profile($p);self::assertSame(['tools'],array_column($profile['parameters'],'name'));
        self::assertSame(['supports_cache'=>true],$profile['extra']);self::assertTrue($this->fields($p)['supports_tools']['value']);
        self::assertNull($this->fields($p)['max_reference_images']['value']);
        $nested=$this->product(['capabilities'=>['custom'=>['modes'=>['a','b'],'api_key'=>'secret']]]);
        self::assertSame(['modes'=>['a','b']],C::profile($nested)['extra']['custom']);
        self::assertTrue($this->fields($this->product(['capabilities'=>['vision']]))['supports_vision']['value']);
    }
    public function testSharedAdaptersReceiveCorrectionsWithoutChangingPricingOrSchemas(): void
    {
        $p=$this->product([],['supports_reference_images'=>false,'max_reference_images'=>3,'max_output_tokens'=>1024]);
        $raw=['protocol'=>'openai_chat','params_schema'=>['images'=>['type'=>'array']],'max_reference_images'=>9];
        $meta=C::applyMetadata($p,$raw);self::assertSame($raw['params_schema'],$meta['params_schema']);self::assertSame('openai_chat',$meta['protocol']);
        $m=new \ReflectionMethod(Image::class,'supportsReferenceImages');$m->setAccessible(true);self::assertFalse($m->invoke(null,$meta));
        $m=new \ReflectionMethod(Video::class,'referenceLimit');$m->setAccessible(true);self::assertSame(3,$m->invoke(null,$p,$meta,'image'));
        self::assertSame(1024,\app\common\service\power\TextModelCapacity::metadata($meta)['max_output_tokens']);
    }
    public function testTenantEndpointDoesNotAcceptPlatformCapabilityOverrides(): void
    {
        $source=file_get_contents(dirname(__DIR__,2).'/app/tenantapi/controller/power/MarketController.php');
        self::assertStringNotContainsString('capability_overrides',$source);
        self::assertStringContainsString('TenantPowerMarketService::savePrices',$source);
    }
}
