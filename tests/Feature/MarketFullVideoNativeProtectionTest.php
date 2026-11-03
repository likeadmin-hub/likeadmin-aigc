<?php
namespace Tests\Feature;

use app\common\service\power\{MarketFullVideoBilling as Full,MarketGenerationApplicationProtocol as Protocol,MarketVideoRuntimeService as Videos};
use app\common\service\open_platform\{OpenNativeMarketPricing as Native,OpenStore,OpenSchema,OpenSchemaNormalizer,OpenException,OpenGateway};
use PHPUnit\Framework\TestCase;

class MarketFullVideoNativeProtectionTest extends TestCase
{
    public static function entry(): array
    {
        $fixture=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/market-application-source-contracts-20261101.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($fixture['entries']as$entry)if($entry['product']['upstream_app_code']==='full_video')return $entry;
        throw new \LogicException('Full fixture missing');
    }
    private static function snapshot(): array
    {
        $e=self::entry();return Protocol::bindNewSnapshot(['resource_type'=>'app_api','app_code'=>'full_video','api_code'=>'submit',
            'sku_id'=>$e['skus'][0]['id'],'locked_params'=>$e['skus'][0]['locked_params'],'usage_unit'=>'input_second','usage_unit_size'=>1,'quantity'=>4,'market_metadata'=>$e['metadata']]);
    }
    public static function raw(int $images=5): array
    {
        $content=[['type'=>'text','text'=>' 一 '],['type'=>'text','text'=>' 二 ']];
        for($i=0;$i<$images;$i++)$content[]=['type'=>'image_url','role'=>'reference_image','image_url'=>['url'=>'https://fixtures.invalid/same.png']];
        return (array)json_decode(json_encode(['prompt'=>'报价诱饵，不得替换 content','model'=>'full-video','content'=>$content,'duration'=>4,'resolution'=>'480P']),false,512,JSON_THROW_ON_ERROR);
    }
    public function testFiveDuplicateSlotsAndMultipleTextItemsUseOriginalWireProjection(): void
    {
        $raw=self::raw();$before=OpenStore::encode((object)$raw);$snapshot=self::snapshot();$projection=Full::inspectNativeForAdmission($snapshot,$raw);
        self::assertSame(['request_duration'=>4,'reference_images'=>5,'excess_reference_images'=>0,'resolution'=>'480P','text_items'=>2,'text_length'=>2],$projection['context']);
        self::assertSame(hash('sha256',$before),$projection['payload_digest']);
        $snapshot['full_video_native_projection']=$projection;Native::assertPreparedTransport(['native_media'=>['market_snapshot'=>$snapshot]],$raw);
        self::assertSame($before,OpenStore::encode((object)$raw));
        $e=self::entry();$product=$e['product']+['source_payload'=>['market_metadata'=>$e['metadata']]];
        $bound=Native::bind($product,$raw,$e['skus'][0]['id'],$e['skus'][0]);self::assertSame(4,$bound['selection']['duration']);
        self::assertSame($before,OpenStore::encode((object)$raw));
        self::assertSame(4.0,Videos::openPlatformSettlementQuantity($snapshot,['duration'=>99,'usage'=>['input_seconds'=>99]]));
        self::assertSame(0.0,Native::settledUnits(['kind'=>'video','market_snapshot'=>$snapshot],[],true));
    }
    public function testPermissiveSchemaAndTopPromptCannotHideActualNineReferenceSlots(): void
    {
        $e=self::entry();$raw=self::raw(9);$before=OpenStore::encode((object)$raw);
        $schema=OpenSchemaNormalizer::normalize($e['metadata']['params_schema'])['schema'];OpenSchema::validateRequest($raw,$schema,true);
        $this->expectException(OpenException::class);$this->expectExceptionMessage('附加计费尚待核验');
        try{Native::bind($e['product']+['source_payload'=>['market_metadata'=>$e['metadata']]],$raw,$e['skus'][0]['id'],$e['skus'][0]);}
        finally{self::assertSame($before,OpenStore::encode((object)$raw));}
    }
    public function testDefaultDurationAndResolutionAreThoseTheSourceActuallyReceives(): void
    {
        $raw=self::raw(0);unset($raw['duration'],$raw['resolution']);$before=OpenStore::encode((object)$raw);
        self::assertSame(4,Full::inspectNativeForAdmission(self::snapshot(),$raw)['context']['request_duration']);
        self::assertSame($before,OpenStore::encode((object)$raw));
        $e=self::entry();$snapshot=self::snapshot();unset($snapshot['application_contract']);$snapshot['locked_params']=$e['skus'][1]['locked_params'];$snapshot=Protocol::bindNewSnapshot($snapshot);
        $this->expectExceptionMessage('原生分辨率');Full::inspectNativeForAdmission($snapshot,$raw);
    }
    public function testMutatedWireOrProjectionCannotPassThePreDispatchBoundary(): void
    {
        $raw=self::raw();$snapshot=self::snapshot();$snapshot['full_video_native_projection']=Full::inspectNativeForAdmission($snapshot,$raw);$rejected=0;
        foreach(['text','count','duration','projection']as$case){$body=$raw;$frozen=$snapshot;
            if($case==='text'){$body['prompt']='different wire';}
            elseif($case==='count'){$body=self::raw(4);}
            elseif($case==='duration'){$body['duration']=5;}
            else{$frozen['full_video_native_projection']['context']['request_duration']=5;}
            try{Native::assertPreparedTransport(['native_media'=>['market_snapshot'=>$frozen]],$body);}catch(\InvalidArgumentException$error){$rejected++;}
        }
        self::assertSame(4,$rejected);
        // This is the actual gateway consumer, before markDispatch and send.
        $source=file_get_contents((new \ReflectionClass(OpenGateway::class))->getFileName());
        self::assertLessThan(strpos($source,"self::markDispatch(\$row['request_id']);\$dispatched=true;\$wire="),strpos($source,'OpenNativeMarketPricing::assertPreparedTransport($snapshot,'));
    }
    public function testLegacyFrozenNativeRecordIsNotUpgraded(): void
    {
        $snapshot=self::snapshot();unset($snapshot['application_contract']);$before=$snapshot;
        Native::assertPreparedTransport(['native_media'=>['market_snapshot'=>$snapshot]],self::raw(9));
        self::assertSame($before,$snapshot);self::assertNull(Full::frozenNativeUnits($snapshot));
    }
}
