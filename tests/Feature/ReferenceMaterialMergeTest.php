<?php
namespace Tests\Feature;

use app\common\service\ai\ReferenceMaterialSlot as Slots;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasService as Canvas;
use app\common\service\power\MarketVideoRuntimeService as Videos;
use app\common\service\power\MarketGenerationMaterials as Materials;
use app\common\service\power\MarketGenerationMedia as Media;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Synthetic codec/graph fixtures only: no DB, asset service or supplier calls. */
class ReferenceMaterialMergeTest extends TestCase
{
    private function merge(array ...$groups): array
    {
        return (new ReflectionMethod(Canvas::class, 'mergeReferenceAssets'))->invoke(null, ...array_pad($groups, 2, []));
    }
    public function testDifferentUnownedUrlsAndExplicitUsesAreNotCollapsed(): void
    {
        $a=['type'=>'image','url'=>'https://fixtures.invalid/a.png','role'=>'reference_image'];
        self::assertCount(2,$this->merge([$a,array_replace($a,['url'=>'https://fixtures.invalid/b.png'])]));
        $a+=['asset_id'=>42,'reference_slot_id'=>'one'];$b=array_replace($a,['reference_slot_id'=>'two']);
        $result=$this->merge([$a,$b], [array_diff_key($a,['reference_slot_id'=>true])]);
        self::assertCount(2,$result);self::assertSame(['one','two'],array_column($result,'reference_slot_id'));
        self::assertCount(1,$this->merge([$a,$a]));
    }
    public function testOwnedAliasAddsStorageWithoutChangingRolesFieldsOrOccurrence(): void
    {
        $a=['type'=>'video','url'=>'https://fixtures.invalid/v.mp4','field'=>'video_urls','reference_slot_id'=>'one'];
        $owned=['type'=>'video','url'=>$a['url'],'uri'=>'tenant/v.mp4','asset_id'=>42,'storage_scope'=>'tenant','storage_engine'=>'local','role'=>'workflow_reference','field'=>'video_urls'];
        $result=$this->merge([$a],[$owned]);
        self::assertCount(1,$result);self::assertSame(42,$result[0]['asset_id']);self::assertSame('one',$result[0]['reference_slot_id']);self::assertSame('video_urls',$result[0]['field']);self::assertSame('tenant/v.mp4',$result[0]['uri']);
        self::assertCount(2,$this->merge([$a,array_replace($a,['field'=>'other_video'])]));
        $first=['type'=>'image','url'=>$a['url'],'role'=>'first_frame_image'];
        self::assertCount(2,$this->merge([$first,array_replace($first,['role'=>'last_frame_image'])]));
    }
    public function testOneUseCannotSilentlyBindAnotherFile(): void
    {
        $a=['type'=>'video','reference_slot_id'=>'one','url'=>'https://fixtures.invalid/a.mp4'];
        $this->expectExceptionMessage('同一参考素材槽位不能绑定不同文件');
        Slots::merge([$a,array_replace($a,['url'=>'https://fixtures.invalid/b.mp4'])]);
    }
    /** @dataProvider protocols */
    public function testApplicationPreparationPreservesAllGraphInputsAndFrozenSlots(string $app, string $model, string $method, array $counts): void
    {
        $assets=[];
        foreach($counts as $kind=>$count)for($i=0;$i<$count;$i++)$assets[]=['type'=>$kind,'role'=>'reference_'.$kind,'reference_slot_id'=>$kind.'.'.$i,'url'=>'https://fixtures.invalid/'.$kind.$i];
        $merged=$this->merge($assets,[$assets[0]]);
        $request=['prompt'=>'Synthetic fixture','generation_method'=>$method,'duration'=>5,'quality'=>'720P','ratio'=>'16:9','reference_assets'=>$merged];
        $snapshot=['resource_type'=>'app_api','app_code'=>$app,'locked_params'=>array_filter(['model'=>$model]),'market_metadata'=>[]];
        $prepared=Videos::prepareRequest($snapshot,$request);
        $frozen=Videos::prepareRequest(json_decode(json_encode($snapshot),true),$request);
        Materials::assertPreserved(['reference_assets'=>$assets],$prepared->nativeInput);
        self::assertSame($prepared->nativeInput,$frozen->nativeInput);
        foreach(Media::inputs($prepared->nativeInput) as $kind=>$values)self::assertCount($counts[$kind]??0,$values,$app.' '.$kind);
    }
    public static function protocols(): array
    {
        return [
            ['seedance','','omni_reference',['image'=>2,'video'=>1,'audio'=>1]],
            ['full_video','','omni_reference',['image'=>2,'video'=>1,'audio'=>1]],
            ['wan','wan2.7-videoedit','video_edit',['image'=>2,'video'=>1,'audio'=>1]],
            ['wan','wan2.7-r2v','omni_reference',['image'=>2,'audio'=>1]],
            ['happy_horse','','video_edit',['image'=>2,'video'=>1]],
            ['grok_video','grok-video','multi_frame',['image'=>2]],
        ];
    }
}
