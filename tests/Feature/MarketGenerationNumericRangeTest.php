<?php
namespace Tests\Feature;

use app\common\service\power\MarketGenerationSchema as Schema;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use PHPUnit\Framework\TestCase;

class MarketGenerationNumericRangeTest extends TestCase
{
    /** @dataProvider ranges */
    public function testDeclaredIntegerRangeIsNotOneLiteralEnumAndKeepsItsBounds(string $range): void
    {
        $schema=['duration'=>['type'=>'number','options'=>$range]];
        self::assertSame([],Schema::choices($schema['duration']));
        Schema::validate($schema,['duration'=>3]);Schema::validate($schema,['duration'=>15]);
        foreach([2,16,3.5] as $value){
            try{Schema::validate($schema,['duration'=>$value]);self::fail('Invalid duration admitted');}
            catch(\InvalidArgumentException $e){self::assertStringContainsString('参数超出范围',$e->getMessage());}
        }
    }

    public static function ranges(): array
    {
        return array_map(static fn($v)=>[$v],['3～15 的整数','3~15整数','3–15 的整数','3至15 的整数','3 到 15 的整数']);
    }

    public function testDecimalRangeAndExplicitNumericChoicesKeepDifferentMeanings(): void
    {
        Schema::validate(['strength'=>['type'=>'number','options'=>'0.5～1.5']],['strength'=>1.25]);
        self::assertSame([3,15],Schema::choices(['type'=>'integer','enum'=>[3,15]]));
        $this->expectException(\InvalidArgumentException::class);
        Schema::validate(['duration'=>['type'=>'integer','enum'=>[3,15]]],['duration'=>4]);
    }

    /** @dataProvider happyHorseDurations */
    public function testHappyHorseValidatesOriginalDurationBeforeIntegerEncoding(float $duration, bool $valid): void
    {
        $fixture=json_decode(file_get_contents(dirname(__DIR__).'/fixtures/canvas-generation-market-contracts.json'),true,512,JSON_THROW_ON_ERROR);
        foreach($fixture['contracts'] as $row)if(($row['app_code']??'')==='happy_horse'){$metadata=$row;break;}
        $snapshot=['resource_type'=>'app_api','app_code'=>'happy_horse','locked_params'=>[], 'market_metadata'=>$metadata];
        if(!$valid)$this->expectException(\InvalidArgumentException::class);
        $native=Videos::prepareRequest($snapshot,['prompt'=>'Offline fixture','duration'=>$duration,'ratio'=>'16:9','quality'=>'720P'])->nativeInput;
        if($valid)self::assertSame($duration,(float)$native['duration']);
    }

    public static function happyHorseDurations(): array { return [[3.0,true],[15.0,true],[2.0,false],[16.0,false],[3.5,false]]; }
}
