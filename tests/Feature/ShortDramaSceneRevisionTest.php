<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\{ShortDramaRevisionScope as Scope,AigcShortDramaService as Service};
use PHPUnit\Framework\TestCase;
final class ShortDramaSceneRevisionTest extends TestCase
{
    public function testFirstSceneLightingEditPreservesEveryOtherSectionAndDialogue():void
    {
        $base=['title'=>'原剧本','subjects'=>[['id'=>'p','name'=>'主角']],'storyboard'=>[['shot_id'=>'s1','dialogue'=>'原对白','recommended_duration_seconds'=>6]],'scene_screenplays'=>[
            ['scene_number'=>1,'scene_ref_id'=>'l1','entry'=>'黄昏','action'=>['原动作'],'dialogue'=>['球旧了，朋友不能丢'],'exit'=>'离开'],
            ['scene_number'=>2,'scene_ref_id'=>'l1','entry'=>'第二场原文','action'=>['原动作2'],'dialogue'=>[],'exit'=>'结束']]];
        $target=(new \ReflectionMethod(Service::class,'inferRevisionTargetFromMessage'))->invoke(null,'仅修改第一场的环境光为暖橙色夕阳，其余场次对白、人物、地点保持不变',$base);
        self::assertSame('scene_screenplay_fields',$target['type']);
        $patch=['title'=>'不允许改名','storyboard'=>[],'scene_screenplays'=>[['scene_number'=>1,'scene_ref_id'=>'错误位置','entry'=>'暖橙色夕阳','action'=>['暖光照亮原动作'],'dialogue'=>['不允许改对白'],'exit'=>'离开']]];
        $result=Scope::mergeScreenplay($base,$patch,$target);
        self::assertSame('暖橙色夕阳',$result['scene_screenplays'][0]['entry']);
        self::assertSame($base['scene_screenplays'][0]['dialogue'],$result['scene_screenplays'][0]['dialogue']);
        self::assertSame($base['scene_screenplays'][1],$result['scene_screenplays'][1]);
        self::assertSame($base['storyboard'],$result['storyboard']);self::assertSame('原剧本',$result['title']);
    }
    public function testOmittedSceneTextInARevisionDoesNotEraseTheCompletedScreenplay():void
    {
        $base=['scene_screenplays'=>[['scene_number'=>1,'entry'=>'完整原文']]];
        $r=(new \ReflectionMethod(Service::class,'mergeRevisionBasePlanPayload'))->invoke(null,['title'=>'标题'],['revision_base_result'=>$base]);
        self::assertSame($base['scene_screenplays'],$r['scene_screenplays']);
    }
}
