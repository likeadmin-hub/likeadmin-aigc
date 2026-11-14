<?php
namespace Tests\Feature;

use app\common\service\app\{UpstreamCatalogReadBatch, UpstreamPricingService};
use PHPUnit\Framework\TestCase;

final class UpstreamCatalogReadBatchTest extends TestCase
{
    public function testOneSyncReusesItsSourceAndReleasesItAfterwards(): void
    {
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();
        $read=new \ReflectionMethod(UpstreamPricingService::class,'sourceConfiguration');
        $previous=request()->upstreamCatalogBatch;
        UpstreamPricingService::withCatalogBatch(static function()use($read){
            $first=$read->invoke(null);
            self::assertSame($first,request()->upstreamCatalogBatch['source']);
            $state=request()->upstreamCatalogBatch;$state['source']=['base_url'=>'https://fixture.invalid'];request()->upstreamCatalogBatch=$state;
            self::assertSame(['base_url'=>'https://fixture.invalid'],$read->invoke(null));
            return [];
        });
        self::assertSame($previous,request()->upstreamCatalogBatch);
    }

    public function testBrowserScopedSyncDoesNotReplayAnAlreadyFailedBatch(): void
    {
        $attempts=0;
        $retry=new \ReflectionMethod(\app\common\service\power\PowerMarketService::class,'withUpstreamRetry');
        try {
            $retry->invoke(null,static function()use(&$attempts){$attempts++;throw new \RuntimeException('upstream timeout');},false);
            self::fail('Expected upstream failure');
        } catch (\RuntimeException $e) { self::assertSame('upstream timeout',$e->getMessage()); }
        self::assertSame(1,$attempts);
    }

    public function testEveryBatchItemRetainsItsIdentityIncludingFailures(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-read-');
        file_put_contents($path, '{"items":[{"available":true}]}');
        try {
            $result = UpstreamCatalogReadBatch::run(['first' => $path, 'second' => $path, 'bad' => $path.'-missing'],
                static function ($file) { $handle=curl_init('file://'.$file);curl_setopt($handle,CURLOPT_RETURNTRANSFER,true);return $handle; },
                static function ($body) { return json_decode($body, true, 512, JSON_THROW_ON_ERROR); }, 2, 2);
            self::assertSame(['first','second','bad'], array_keys($result));
            self::assertSame(['items'=>[['available'=>true]]], $result['first']);
            self::assertSame($result['first'], $result['second']);
            self::assertInstanceOf(\Throwable::class, $result['bad']);
        } finally { unlink($path); }
    }

    public function testDeadlineDoesNotTurnUnprocessedItemsIntoUnavailableResources(): void
    {
        $created = 0;
        $result = UpstreamCatalogReadBatch::run(['a'=>[], 'b'=>[]],
            static function () use (&$created) { $created++; return curl_init(); }, static fn() => [], 0);
        self::assertSame(0, $created);
        self::assertInstanceOf(\Throwable::class, $result['a']);
        self::assertInstanceOf(\Throwable::class, $result['b']);
    }

    public function testPrefetchedPricingUsesTheExistingNormalizerAndNeverLosesLocalKeys(): void
    {
        (new \think\App(dirname(__DIR__, 2).'/'))->initialize();
        $previous = ['outside' => true];
        request()->upstreamCatalogBatch = $previous;
        $items = [['type'=>'model','model'=>'fixture-a','local_key'=>'a'], ['type'=>'model','model'=>'fixture-b','local_key'=>'b']];
        UpstreamPricingService::withCatalogBatch(static function () use ($items) {
            $key = hash('sha256', json_encode(['POST','/api/v1/pricing/batch',['items'=>$items]], JSON_THROW_ON_ERROR));
            $state = request()->upstreamCatalogBatch;
            $state['responses'][$key] = ['items'=>[['available'=>true,'resource'=>['model_code'=>'fixture-a']],['available'=>false,'error_code'=>'unavailable']]];
            request()->upstreamCatalogBatch = $state;
            // sourceOrigin uses configuration only; no transport occurs for the prefetched batch.
            $result = UpstreamPricingService::queryBatch($items);
            self::assertSame(['a','b'], array_column($result['items'],'local_key'));
            self::assertTrue($result['items'][0]['available']);
            self::assertFalse($result['items'][1]['available']);
            return [];
        });
        self::assertSame($previous, request()->upstreamCatalogBatch);
        try {
            UpstreamPricingService::withCatalogBatch(static function () { throw new \RuntimeException('fixture'); });
        } catch (\RuntimeException $e) { self::assertSame('fixture', $e->getMessage()); }
        self::assertSame($previous, request()->upstreamCatalogBatch);
        request()->upstreamCatalogBatch = null;
    }
    public function testIncompleteBatchCannotBeInterpretedAsAnUnavailableProduct(): void
    {
        (new \think\App(dirname(__DIR__, 2).'/'))->initialize();
        $items=[['type'=>'model','model'=>'fixture-a','local_key'=>'a']];
        $this->expectExceptionMessage('上游价格批次返回不完整');
        UpstreamPricingService::withCatalogBatch(static function()use($items){
            $key=hash('sha256',json_encode(['POST','/api/v1/pricing/batch',['items'=>$items]],JSON_THROW_ON_ERROR));
            $state=request()->upstreamCatalogBatch;$state['responses'][$key]=['items'=>[]];request()->upstreamCatalogBatch=$state;
            return UpstreamPricingService::queryBatch($items);
        });
    }

}
