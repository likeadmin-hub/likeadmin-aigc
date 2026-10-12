<?php
namespace Tests\Feature;
use app\common\service\decorate\agent\DecorationAgentMediaService;
use app\common\service\power\MarketImageModelRuntimeService;
use app\common\service\power\MarketImageProviderRequestException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
class DecorationAgentMediaTest extends TestCase
{
    private function invoke(string $class,string $method,array $args) { $m=new ReflectionMethod($class,$method);$m->setAccessible(true);return $m->invokeArgs(null,$args); }
    public function testOrdinaryMarketRetailPriceIsUnchanged(): void
    {
        $market=['tenant_price'=>9,'sku'=>['sale_points'=>3]];
        self::assertSame($market,$this->invoke(MarketImageModelRuntimeService::class,'tenantAdminMarket',[$market,1,2,false]));
    }
    public function testTenantAdminSnapshotHasNoRetailButKeepsPlatformCost(): void
    {
        $market=$this->invoke(MarketImageModelRuntimeService::class,'tenantAdminMarket',[['tenant_price'=>9,'sku'=>['sale_points'=>3]],1,0,true]);
        self::assertSame(0,$market['tenant_price']); self::assertSame(3,$market['sku']['sale_points']);
        foreach ([false,true] as $usage) {
            [$tenant,$retail]=$this->invoke(MarketImageModelRuntimeService::class,'settlementAmounts',[['platform_price'=>3,'tenant_price'=>0,'usage_unit_size'=>1],2,2,$usage]);
            self::assertEquals(6,$tenant);self::assertEquals(0,$retail);
        }
    }
    public function testTenantAdminCannotChargeAUser(): void
    { $this->expectException(\Exception::class);$this->invoke(MarketImageModelRuntimeService::class,'tenantAdminMarket',[[],1,2,true]); }
    public function testAttachmentRejectsCrossTenantAndUserSource(): void
    {
        foreach ([['tenant_id'=>2,'source'=>0],['tenant_id'=>1,'source'=>1]] as $row) {
            try {$this->invoke(DecorationAgentMediaService::class,'assertAttachmentRow',[1,$row+['id'=>3,'type'=>10,'uri'=>'uploads/a.png']]);self::fail('Unauthorized attachment accepted');}catch(\Exception $e){self::assertStringContainsString('附件',$e->getMessage());}
        }
    }
    public function testAttachmentUsesRealRasterMimeAndDimensions(): void
    {
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/a9sAAAAASUVORK5CYII=');
        self::assertSame([1,1],$this->invoke(DecorationAgentMediaService::class,'inspectImage',[$png]));
        $this->expectException(\Exception::class);$this->invoke(DecorationAgentMediaService::class,'inspectImage',['<svg/>']);
    }
    public function testAttachmentRejectsOversizeAndOverFiveBeforeDatabaseAccess(): void
    {
        try {$this->invoke(DecorationAgentMediaService::class,'inspectImage',[str_repeat('a',10485761)]);self::fail('oversize accepted');}catch(\Exception $e){self::assertStringContainsString('10MB',$e->getMessage());}
        $this->expectException(\Exception::class);DecorationAgentMediaService::validateAttachments(1,array_fill(0,6,['file_id'=>1]));
    }
    public function testAgentNeverRetriesEvenAnExplicitTemporaryRejection(): void
    {
        $error=new MarketImageProviderRequestException('busy',true,429);
        self::assertTrue($this->invoke(MarketImageModelRuntimeService::class,'shouldRetrySubmit',[$error,1,false]));
        self::assertFalse($this->invoke(MarketImageModelRuntimeService::class,'shouldRetrySubmit',[$error,1,true]));
    }
    public function testArbitraryClientReferenceUrlsAreRejected(): void
    {
        $this->expectException(\Exception::class);
        $this->invoke(DecorationAgentMediaService::class,'referenceRequest',[1,['reference_images'=>['https://evil.example/x.png']]]);
    }
    public function testFullAttachmentAdapterUsesApprovedPurposesAndFreshOwnedReferences(): void
    {
        $output=[];$exit=0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/Support/DecorationAgentMediaRuntime.php'),$output,$exit);
        self::assertSame(0,$exit);
        $result=json_decode(implode("\n",$output),true,512,JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('attachment_error',$result);
        self::assertSame(['asset','layout','style','generation'],$result['attachments'][0]['uses']);
        self::assertSame('https://owned.test/uploads/owned.png',$result['attachments'][0]['url']);
        self::assertSame(1,$result['attachments'][0]['width']);self::assertSame(1,$result['attachments'][0]['height']);
        self::assertTrue($result['rejected'][2]);self::assertTrue($result['rejected'][3]);
        self::assertSame([],$result['requests']['none']['reference_images']);
        self::assertSame(['https://owned.test/uploads/owned.png'],$result['requests']['one']['reference_images']);
        self::assertArrayNotHasKey('provider_params',$result['requests']['one']);
        self::assertArrayHasKey('bad',$result['request_errors']);
        self::assertSame([],$result['search_reference_only']);
        self::assertSame(1,$result['search_asset'][0]['file_id']);
    }
}
