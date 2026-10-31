<?php
namespace Tests\Feature;

use app\common\service\power\{MarketGenerationRuntime,MarketPicLipsyncAppRuntimeService,MarketImageModelRuntimeService,MarketVideoModelRuntimeService};
use PHPUnit\Framework\TestCase;

/** Transport-free argument spy: verifies the shared facade does not round fractional input seconds or change business context. */
class GenerationDurationSpy extends MarketPicLipsyncAppRuntimeService
{
    public static array $calls=[];
    public static function quote(int $tenantId,array $selection,float $quantity=0): array
    {
        self::$calls[]=func_get_args();return ['quantity'=>$quantity,'selection'=>$selection];
    }
    public static function reserve(int $tenantId,int $userId,string $action,string $businessTaskId,array $selection,array $request,
        float $quantity=0,string $appCode=self::APP_CODE,string $businessTable='aigc_pic_lipsync_task'): array
    {
        self::$calls[]=func_get_args();return ['quantity'=>$quantity,'app_code'=>$appCode,'business_table'=>$businessTable,'business_id'=>$businessTaskId,'request'=>$request];
    }
}

class GenerationRuntimeDispatchTest extends TestCase
{
    private function runtime(string $adapter): MarketGenerationRuntime
    {
        $reflection=new \ReflectionClass(MarketGenerationRuntime::class);$runtime=$reflection->newInstanceWithoutConstructor();
        foreach(['selection'=>['fixture'=>'frozen'],'adapter'=>$adapter,'taskId'=>0]as$key=>$value){$property=$reflection->getProperty($key);$property->setAccessible(true);$property->setValue($runtime,$value);}
        return $runtime;
    }
    /** @dataProvider durations */
    public function testFractionalInputSecondsAndBusinessContextReachTheExistingAdapter(float $seconds): void
    {
        $runtime=$this->runtime(GenerationDurationSpy::class);GenerationDurationSpy::$calls=[];
        self::assertSame($seconds,$runtime->quote(56,$seconds)['quantity']);
        $r=$runtime->reserve(56,57,'aigc_digital_human','generate','aigc_digital_human_task','58',['audio_url'=>'https://fixture.invalid/synthetic.wav'],$seconds);
        self::assertSame($seconds,$r['quantity']);self::assertSame('aigc_digital_human',$r['app_code']);self::assertSame('58',$r['business_id']);
        self::assertSame('aigc_digital_human_task',$r['business_table']);self::assertSame(['fixture'=>'frozen'],GenerationDurationSpy::$calls[0][1]);
        self::assertCount(2,GenerationDurationSpy::$calls);
    }
    public static function durations(): array{return [[0.125],[1.25],[3.999],[120.001]];}

    /** @dataProvider invalidCounts */
    public function testInvalidCountsCannotReachAnAdapter(string $adapter,float $quantity): void
    {
        $this->expectException(\InvalidArgumentException::class);$this->runtime($adapter)->quote(56,$quantity);
    }
    public static function invalidCounts(): array{return [[MarketImageModelRuntimeService::class,1.5],[MarketVideoModelRuntimeService::class,2.0],
        [GenerationDurationSpy::class,0.0],[GenerationDurationSpy::class,-1.0],[GenerationDurationSpy::class,INF],[GenerationDurationSpy::class,NAN]];}

    public function testExplicitFrozenRoutingDoesNotRequireAnExistingCatalogueRecord(): void
    {
        self::assertSame(MarketVideoModelRuntimeService::class,MarketGenerationRuntime::selection('video',[
            'resource_type'=>'model','model_type'=>'video','market_product_id'=>2147483000])->adapter);
    }

    public function testUnsupportedRetailOverrideIsNotSilentlyDiscarded(): void
    {
        GenerationDurationSpy::$calls=[];
        try{$this->runtime(GenerationDurationSpy::class)->reserve(56,57,'app','generate','table','58',[],1.25,['user_charge_points'=>0]);self::fail('Dropped retail override');}
        catch(\InvalidArgumentException){self::assertSame([],GenerationDurationSpy::$calls);}
    }
}
