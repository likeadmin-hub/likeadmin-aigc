<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_short_drama\{ShortDramaResultProjection as Projection,ShortDramaDirectGeneration as Direct,AigcShortDramaService as Service};
use app\common\service\app\aigc_short_drama\canvas_agent\{ConversationSeries as Series,ConversationSeriesVisuals as Visuals};
class ShortDramaResultProjectionTest extends TestCase
{
    private function source(): array {
        return ['project_title'=>'代码降魔','logline'=>'程序员大战孙悟空',
            'character_profiles'=>[['id'=>'hero','name'=>'林码','visual_features'=>'黑框眼镜']],
            'scene_script'=>['scene_id'=>'office','location'=>'办公室','atmosphere'=>'水墨与蓝光','dialogue'=>[['character'=>'林码','line'=>'保留对白！','timestamp'=>'0:05']]],
            'storyboard_script'=>[['shot_id'=>'s1','scene_ref_id'=>'office','subject_ref_ids'=>['hero'],'visual_description'=>'抬头',
                'dialogue'=>'保留对白！','recommended_duration_seconds'=>2,'image_prompt'=>'原始图片提示词','video_prompt'=>'原始视频提示词']],
            'subjects'=>[],'locations'=>[],'storyboard'=>[],'script_lines'=>[],'unknown'=>['keep'=>true]];
    }
    public function testDirectAndHistoricalPlansHaveIdenticalLosslessProjection(): void {
        $source=$this->source();$p=Projection::normalize($source);
        $direct=Direct::result(['content'=>json_encode($source,JSON_UNESCAPED_UNICODE)],[],'标题');
        foreach(['subjects','locations','storyboard','script_lines'] as $key) self::assertSame($p[$key],$direct[$key]);
        self::assertSame($source['storyboard_script'],$p['storyboard']);
        self::assertSame($source['unknown'],$p['unknown']);self::assertSame($source['scene_script'],$p['scene_script']);
        self::assertSame('office',$p['locations'][0]['id']);self::assertSame('hero',$p['subjects'][0]['id']);
        self::assertSame('0:05 林码：保留对白！',$p['script_lines'][0]);
        self::assertSame($p,Projection::normalize($p));
        self::assertStringContainsString('保留对白！',Series::text($source,'episode',1));
        self::assertCount(2,Visuals::proposals($source,'assets',1,static fn($kind,$item)=>$item['name']));
        self::assertCount(1,Visuals::proposals($source,'art',1,static fn()=>''));
    }
    public function testCanonicalEditsWinAndDeletedItemsAreNotResurrected(): void {
        $source=$this->source();$source['subjects']=[['id'=>'edited','name'=>'用户编辑']];
        $p=Projection::normalize($source);self::assertSame('edited',$p['subjects'][0]['id']);
        $p['subjects']=[];$p['storyboard']=[];self::assertSame($p,Projection::normalize($p));
    }
    public function testConventionalReaderUsesSameAliases(): void {
        $method=new \ReflectionMethod(Service::class,'enhancePlanResult');$method->setAccessible(true);
        $p=$method->invoke(null,$this->source(),false);
        self::assertCount(1,$p['subjects']);self::assertCount(1,$p['locations']);self::assertCount(1,$p['storyboard']);
        self::assertSame('保留对白！',$p['storyboard'][0]['dialogue']);
        self::assertSame('s1',$p['storyboard'][0]['shot_id']);
    }
}
