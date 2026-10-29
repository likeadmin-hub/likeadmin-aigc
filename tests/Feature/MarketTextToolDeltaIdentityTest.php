<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\power\MarketTextModelRuntimeService;
final class MarketTextToolDeltaIdentityTest extends TestCase
{
    private function merge(array $a,array $b):array
    { $method=new \ReflectionMethod(MarketTextModelRuntimeService::class,'mergeToolCalls');$method->setAccessible(true);return $method->invoke(null,$a,$b); }
    public function testEmptyDeltaIdentityCannotEraseTheOriginalCall():void
    {
        $a=$this->merge([],[['index'=>0,'id'=>'call-original','type'=>'function','function'=>['name'=>'create_document','arguments'=>'{"title":']]]);
        $b=$this->merge($a,[['index'=>0,'id'=>'','type'=>'function','function'=>['name'=>'','arguments'=>'"旧球新约"}']]]);
        self::assertSame('call-original',$b[0]['id']);
        self::assertSame('create_document',$b[0]['function']['name']);
        self::assertSame(['title'=>'旧球新约'],json_decode($b[0]['function']['arguments'],true,512,JSON_THROW_ON_ERROR));
    }
    public function testDifferentNonemptyIdsAtTheSameIndexAreRejected():void
    {
        $this->expectException(\RuntimeException::class);
        $this->merge([['index'=>2,'id'=>'first','function'=>['arguments'=>'{}']]],[['index'=>2,'id'=>'second','function'=>['arguments'=>'{}']]]);
    }
    public function testStructuredCompleteArgumentsAreEncodedWithoutArrayConversion():void
    {
        $out=$this->merge([],[['index'=>0,'id'=>'object','function'=>['name'=>'edit_canvas_page','arguments'=>['operation'=>'read_page']]]]);
        self::assertSame(['operation'=>'read_page'],json_decode($out[0]['function']['arguments'],true,512,JSON_THROW_ON_ERROR));
    }
}
