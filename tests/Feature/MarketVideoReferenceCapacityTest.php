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
        $r=$this->request();$r['reference_assets'][19]['name']='LastAudio';$r['prompt']='@LastAudio';$before=$r;
        self::assertCount(20,Assets::normalizeForMarket($r));
        $inputMode=new ReflectionMethod(Videos::class,'inputMode');
        self::assertSame('video_edit',$inputMode->invoke(null,$r));
        $snapshot=$this->snapshot();$native=Videos::prepareRequest($snapshot,$r)->nativeInput;
        self::assertSame('@音频5',$native['prompt']);
        foreach(['image'=>10,'video'=>5,'audio'=>5] as $kind=>$count)self::assertCount($count,$native['reference_'.$kind.'_urls']);
        self::assertSame($native,Videos::prepareRequest(json_decode(json_encode($snapshot),true),$r)->nativeInput);
        self::assertStringNotContainsString('reference_slot_id',json_encode($native));
        self::assertSame($before,$r);
    }
    private function canvas(array $request): array
    {
        $canvas=\app\common\service\app\aigc_canvas\AigcCanvasService::class;
        $mentions=(new ReflectionMethod($canvas,'prepareMentionParams'))->invoke(null,$request);
        return (new ReflectionMethod($canvas,'normalizeVideoParams'))->invoke(null,$mentions,0,0,false);
    }
    public function testCanvasKeepsTwentyMarkedUsesWhileMergingLegacyAliases(): void
    {
        $r=$this->request();$r['reference_assets'][]=['type'=>'video','uri'=>'https://fixtures.invalid/video'];
        $canvas=$this->canvas($r);
        self::assertCount(20,$canvas['reference_assets']);
        self::assertCount(20,array_column($canvas['reference_assets'],'reference_slot_id'));
        self::assertSame(Videos::prepareRequest($this->snapshot(),$r)->nativeInput,
            Videos::prepareRequest($this->snapshot(),$canvas)->nativeInput);
    }
    public function testCanvasKeepsSameImageInBothFrameRoles(): void
    {
        $assets=[];
        foreach(['first_frame_image','last_frame_image'] as $role)$assets[]=['type'=>'image','role'=>$role,
            'uri'=>'https://fixtures.invalid/frame','url'=>'blob:http://localhost:3000/preview'];
        $r=['prompt'=>'Synthetic frames','reference_assets'=>$assets,'generation_method'=>'start_end'];
        $canvas=$this->canvas($r);
        self::assertSame(['first_frame_image','last_frame_image'],array_column($canvas['reference_assets'],'role'));
        self::assertSame(['https://fixtures.invalid/frame','https://fixtures.invalid/frame'],array_column($canvas['reference_assets'],'url'));
    }
    public function testCanvasStillRejectsOneExplicitSlotBoundToDifferentFiles(): void
    {
        $r=$this->request(0,2,0);$r['reference_assets'][1]['reference_slot_id']=$r['reference_assets'][0]['reference_slot_id'];
        $r['reference_assets'][1]['uri']='https://fixtures.invalid/other';
        $this->expectExceptionMessage('同一参考素材槽位不能绑定不同文件');
        $this->canvas($r);
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
    public function testLegacyMarketCapabilitiesStillRejectAnOverLimitRequest(): void
    {
        $market=['product'=>['resource_type'=>'model','model_type'=>'video','upstream_model_code'=>'legacy-fixture',
            'source_payload'=>['market_metadata'=>['generation_modes'=>['image_reference'],
                'supported_asset_types'=>['image'],'capabilities'=>['max_reference_images'=>1]]]],'sku'=>[]];
        $r=$this->request(16,0,0);$r['generation_method']='image_reference';
        $this->expectExceptionMessage('at most 1 reference');
        (new ReflectionMethod(Videos::class,'assertAssets'))->invoke(null,$market,$r);
    }
    /** @dataProvider overCapacity */
    public function testEachDeclaredModalityLimitStillRejects(int $images,int $videos,int $audios): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Videos::prepareRequest($this->snapshot(),$this->request($images,$videos,$audios));
    }
    public static function overCapacity(): array {return [[11,5,5],[10,6,5],[10,5,6]];}
}
