<?php
namespace Tests\Feature;

use app\common\service\power\MarketVideoRuntimeService as Videos;
use app\common\service\power\MarketGenerationMedia as Media;
use app\common\service\power\MarketCreationParameters as Controls;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MarketApplicationMaterialBoundaryTest extends TestCase
{
    private static function metadata(): array
    {
        // Public full_video content roles and limits, not a fabricated native contract.
        return ['params_schema'=>['content'=>['type'=>'array','required'=>true]],
            'supported_asset_types'=>['image','video','audio'],
            'generation_modes'=>['text_to_video','image_to_video','start_end','omni_reference','image_reference','video_edit','multi_frame'],
            'content_schema'=>['items'=>[
                'text'=>['asset_type'=>'text','roles'=>[],'required_field'=>'text'],
                'image_url'=>['asset_type'=>'image','roles'=>['first_frame','last_frame','reference_image'],'required_field'=>'image_url.url'],
                'video_url'=>['asset_type'=>'video','roles'=>['reference_video'],'required_field'=>'video_url.url'],
                'audio_url'=>['asset_type'=>'audio','roles'=>['reference_audio'],'required_field'=>'audio_url.url']],
                'limits'=>['first_frame'=>1,'last_frame'=>1,'reference_image'=>9,'reference_video'=>3,'reference_audio'=>3],
                'rules'=>['requires_text'=>true,'frame_and_reference_mutually_exclusive'=>true,'reference_audio_requires_visual'=>true]]];
    }
    private static function snapshot(): array
    {
        return ['resource_type'=>'app_api','app_code'=>'full_video','api_code'=>'submit','model_code'=>'full-video',
            'market_metadata'=>self::metadata(),'params_schema'=>self::metadata()['params_schema'],'locked_params'=>['resolution'=>'4k']];
    }
    private static function request(array $counts): array
    {
        $assets=[];
        foreach($counts as $type=>$count)for($i=0;$i<$count;$i++)$assets[]=[
            'type'=>$type,'role'=>'reference_'.$type,'url'=>'https://fixtures.invalid/'.$type.$i,'reference_slot_id'=>$type.$i];
        return ['prompt'=>'Synthetic composition','duration'=>5,'ratio'=>'1:1','quality'=>'4K','generation_method'=>'omni_reference','reference_assets'=>$assets];
    }
    private static function admit(array $request): void
    {
        $market=['product'=>['resource_type'=>'app_api','upstream_app_code'=>'full_video','source_payload'=>['market_metadata'=>self::metadata()]],'sku'=>['locked_params'=>[]]];
        (new ReflectionMethod(Videos::class,'assertAssets'))->invoke(null,$market,$request);
    }
    public function testMaximumMixedContentPreservesAllTypedNestedSlotsAndFrozenSnapshot(): void
    {
        $request=self::request(['image'=>9,'video'=>3,'audio'=>3]);self::admit($request);
        $p=Videos::prepareRequest(self::snapshot(),$request);
        $f=Videos::prepareRequest(json_decode(json_encode(self::snapshot()),true),$request);
        self::assertSame($p->nativeInput,$f->nativeInput);self::assertSame($p->meterContext,$f->meterContext);
        $media=Media::inputs($p->nativeInput);
        foreach(['image'=>9,'video'=>3,'audio'=>3] as $type=>$count)self::assertCount($count,$media[$type]);
        self::assertCount(16,$p->nativeInput['content']);
    }
    /** @dataProvider boundaryCases */
    public function testQuoteAndFrozenPreparationBothRejectInvalidMaterialCombinations(string $case,bool $prepare): void
    {
        $r=self::request(['image'=>1]);
        if($case==='images')$r=self::request(['image'=>10]);
        if($case==='videos')$r=self::request(['video'=>4]);
        if($case==='audios')$r=self::request(['image'=>1,'audio'=>4]);
        if($case==='audio_only')$r=self::request(['audio'=>1]);
        if($case==='subject_field')$r['reference_assets'][0]['field']='elements';
        if($case==='frame_and_reference')$r['reference_assets'][]=['type'=>'image','role'=>'first_frame_image','url'=>'https://fixtures.invalid/frame'];
        if($case==='missing_tail'){$r['generation_method']='start_end';$r['reference_assets'][0]['role']='first_frame_image';}
        if($case==='text_with_media')$r['generation_method']='text_to_video';
        $this->expectException(\Exception::class);
        if($prepare)Videos::prepareRequest(self::snapshot(),$r);else self::admit($r);
    }
    public static function boundaryCases(): array
    {
        $rows=[];foreach(['images','videos','audios','audio_only','subject_field','frame_and_reference','missing_tail','text_with_media'] as $case)
            foreach([false,true] as $prepare)$rows[]=[$case,$prepare];return $rows;
    }
    public function testSameFileInFirstAndLastFrameRemainsTwoRoles(): void
    {
        $r=self::request(['image'=>2]);$r['generation_method']='start_end';
        foreach(['first','last'] as $i=>$role){$r['reference_assets'][$i]['role']=$role.'_frame_image';$r['reference_assets'][$i]['url']='https://fixtures.invalid/frame';}
        self::admit($r);$p=Videos::prepareRequest(self::snapshot(),$r);
        self::assertCount(2,Media::inputs($p->nativeInput)['image']);
        self::assertSame(['first_frame','last_frame'],array_column(array_slice($p->nativeInput['content'],1),'role'));
    }
    public function testApplicationCannotBorrowNativeSubjectParameters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Controls::applyOptions(['channel'=>'application','advanced_params'=>['elements'=>[['name'=>'Synthetic']]]],
            [['value'=>'application','resource_type'=>'app_api','params_schema'=>self::metadata()['params_schema']]],'video');
    }
}
