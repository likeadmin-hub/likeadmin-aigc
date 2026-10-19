<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_micro_video\MicroVideoWorker;
use app\common\service\app\aigc_micro_video\MicroVideoPolicy;
use app\common\service\app\aigc_micro_video\MicroVideoStore;
final class MicroVideoPlanningContractTest extends TestCase
{
    private function contract(array $durations): array
    {
        $method=new \ReflectionMethod(MicroVideoWorker::class,'planningContract');$method->setAccessible(true);
        // Verify the JSON presented on the model wire, rather than PHP type coercion.
        return json_decode(MicroVideoStore::encode($method->invoke(null,$durations)),true,512,JSON_THROW_ON_ERROR);
    }
    public function testNumericSchemaExampleCanPassTheActualStrictSegmentValidator(): void
    {
        $contract=$this->contract([5,15]);$example=$contract['output_schema']['segments'][0];self::assertIsInt($example['duration']);self::assertSame(5,$example['duration']);$second=$example;$second['duration']=15;
        $segments=MicroVideoPolicy::segments([$example,$second],[5,15],1,1,2);self::assertSame([5,15],array_column($segments,'duration'));
        self::assertStringContainsString('JSON整数',$contract['requirements']);self::assertStringContainsString('对应位置',$contract['requirements']);self::assertStringContainsString('禁止字符串',$contract['requirements']);self::assertStringContainsString('秒',$contract['requirements']);
    }
    public function testActualUnitSuffixedModelOutputIsStillRejectedWithoutCoercion(): void
    {
        $contract=$this->contract([5,15]);$example=$contract['output_schema']['segments'][0];$example['duration']='5秒';$this->expectException(\InvalidArgumentException::class);MicroVideoPolicy::segments([$example],[5,15],1,1,2);
    }
}
