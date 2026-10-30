<?php
namespace Tests\Feature;

use app\common\service\app\aigc_video\AigcVideoReferenceAssetService as Assets;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Shape preparation stays separate from the selected model's actual limits. */
class MarketVideoReferenceCapacityTest extends TestCase
{
    private function request(int $images=10,int $videos=5,int $audios=5): array
    {
        $assets=[];
        foreach(['image'=>$images,'video'=>$videos,'audio'=>$audios] as $kind=>$count)
            for($i=0;$i<$count;$i++)$assets[]=['type'=>$kind,'uri'=>'https://fixtures.invalid/'.$kind,
                'reference_slot_id'=>$kind.':'.$i,'role'=>'reference_'.$kind];
        return ['prompt'=>'Synthetic capacity fixture','duration'=>2,'generation_method'=>'omni_reference','reference_assets'=>$assets];
    }
    private function snapshot(): array
    {
        $properties=['prompt'=>['type'=>'string'],'duration'=>['type'=>'integer','minimum'=>1,'maximum'=>5]];$fields=[];
        foreach(['image'=>10,'video'=>5,'audio'=>5] as $kind=>$limit){
            $key='reference_'.$kind.'_urls';
            $properties[$key]=['type'=>'array','maxItems'=>$limit,'items'=>['type'=>'string','format'=>'uri']];
            $fields[]=['field'=>$key,'kind'=>$kind,'role'=>'reference','maximum'=>$limit];
        }
        return ['resource_type'=>'model','model_code'=>'capacity-fixture','channel_code'=>'offline','locked_params'=>[],
            'market_metadata'=>['params_schema'=>['type'=>'object','properties'=>$properties],
                'material_capabilities'=>['modes'=>[['code'=>'reference_to_video','method'=>'omni_reference','fields'=>$fields]]]]];
    }
    public function testMarketPreparationAndInputClassificationRetainTwentyExplicitSlots(): void
    {
        $r=$this->request();$before=$r;
        self::assertCount(20,Assets::normalizeForMarket($r));
        $inputMode=new ReflectionMethod(Videos::class,'inputMode');
        self::assertSame('video_edit',$inputMode->invoke(null,$r));
        $snapshot=$this->snapshot();$native=Videos::prepareRequest($snapshot,$r)->nativeInput;
        foreach(['image'=>10,'video'=>5,'audio'=>5] as $kind=>$count)self::assertCount($count,$native['reference_'.$kind.'_urls']);
        self::assertSame($native,Videos::prepareRequest(json_decode(json_encode($snapshot),true),$r)->nativeInput);
        self::assertStringNotContainsString('reference_slot_id',json_encode($native));
        self::assertSame($before,$r);
    }
    public function testLegacyDefaultAndExplicitCallerBoundsRemainEffective(): void
    {
        $this->expectExceptionMessage('参考素材数量超出限制');
        Assets::normalize($this->request());
    }
    public function testMarketShapeBudgetIsStillBounded(): void
    {
        $this->expectExceptionMessage('参考素材数量超出限制');
        Assets::normalizeForMarket($this->request(101,0,0));
    }
    /** @dataProvider overCapacity */
    public function testEachDeclaredModalityLimitStillRejects(int $images,int $videos,int $audios): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Videos::prepareRequest($this->snapshot(),$this->request($images,$videos,$audios));
    }
    public static function overCapacity(): array {return [[11,5,5],[10,6,5],[10,5,6]];}
}
