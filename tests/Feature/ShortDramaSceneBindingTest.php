<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaSceneBinding as Binding, ShortDramaScriptGeneration as Script, ShortDramaTimedScriptGeneration as Timed, ShortDramaEpisodeDuration as Duration};
use PHPUnit\Framework\TestCase;

class ShortDramaSceneBindingTest extends TestCase
{
    private function plan():array
    {
        return ['title'=>'来信','story_outline'=>'陈溪读信','script_lines'=>['陈溪在老屋读信'],
            'subjects'=>[['id'=>'p','name'=>'陈溪']],
            'locations'=>[['id'=>'book','name'=>'旧书屋'],['id'=>'home','name'=>'母亲老屋','interior_exterior'=>'interior'],['id'=>'archive','name'=>'社区资料室']]];
    }
    public function testExactActualPlaceCorrectsValidButWrongIdWithoutChangingText():void
    {
        $plan=$this->plan();$shot=['shot_id'=>'s1','scene_ref_id'=>'book','scene_name'=>'母亲老屋','visual_description'=>'母亲老屋内，陈溪整理信件。','dialogue'=>'陈溪：去资料室找找。'];
        $plan['storyboard']=[$shot];$out=Binding::normalize($plan,['_input_contract_version'=>12]);
        self::assertSame('home',$out['storyboard'][0]['scene_ref_id']);
        self::assertSame($shot['visual_description'],$out['storyboard'][0]['visual_description']);self::assertSame($shot['dialogue'],$out['storyboard'][0]['dialogue']);
        self::assertSame($plan['locations'],$out['locations']);
    }
    public function testOldPaidInputsRemainUnchanged():void
    {
        $plan=$this->plan();$plan['storyboard']=[['scene_ref_id'=>'book','visual_description'=>'老屋书桌前，陈溪读信。']];
        self::assertSame($plan,Binding::normalize($plan,['_input_contract_version'=>11]));
    }
    public function testUnknownActualPlaceCannotSilentlyBindToBookshop():void
    {
        $plan=$this->plan();unset($plan['locations'][1]);$plan['storyboard']=[['scene_ref_id'=>'book','visual_description'=>'雨后傍晚，陈溪拖着行李箱走进母亲老屋，放下箱子。']];
        $this->expectExceptionCode(422);$this->expectExceptionMessage('未唯一登记');Binding::normalize($plan,['_input_contract_version'=>12]);
    }
    public function testReferencesAndDialogueDoNotChangeActualScene():void
    {
        foreach(['旧书屋内，陈溪谈起母亲老屋。','陈溪看向社区资料室，准备出发。','照片中的母亲老屋显得安静。','陈溪说：“去母亲老屋。”'] as$text){
            $plan=$this->plan();$plan['storyboard']=[['scene_ref_id'=>'book','scene_name'=>'旧书屋','visual_description'=>$text,'dialogue'=>'母亲老屋在巷口。']];
            $out=Binding::normalize($plan,['_input_contract_version'=>12]);self::assertSame('book',$out['storyboard'][0]['scene_ref_id']);
        }
    }
    public function testContradictorySceneNameAndVisualSettingAreRejected():void
    {
        $plan=$this->plan();$plan['storyboard']=[['scene_ref_id'=>'archive','scene_name'=>'社区资料室','visual_description'=>'母亲老屋内，陈溪读信。']];
        $this->expectExceptionMessage('场景语义不一致');Binding::normalize($plan,['_input_contract_version'=>12]);
    }
    public function testAmbiguousNamesDoNotPickFirstLocation():void
    {
        $plan=$this->plan();$plan['locations'][]=['id'=>'home2','name'=>'母亲老屋'];$plan['storyboard']=[['scene_ref_id'=>'book','scene_name'=>'母亲老屋']];
        $this->expectExceptionCode(422);Binding::normalize($plan,['_input_contract_version'=>12]);
    }
    public function testExplicitInteriorExteriorConflictIsRejected():void
    {
        $plan=$this->plan();$plan['storyboard']=[['scene_ref_id'=>'home','scene_name'=>'母亲老屋','interior_exterior'=>'exterior','visual_description'=>'母亲老屋内，陈溪整理信件。']];
        $this->expectExceptionMessage('室内外');Binding::normalize($plan,['_input_contract_version'=>12]);
    }
    public function testTimedGenerationRepairsOnlyMismatchedPartAndKeepsStableReceipts():void
    {
        $plan=$this->plan();$plan['scene_beats']=[['scene_ref_id'=>'book','scene_name'=>'母亲老屋','goal'=>'读信','entry'=>'母亲老屋内','exit'=>'读完信',
            'key_events'=>['读信'],'duration_seconds'=>10,'shot_durations'=>[10]]];$calls=[];
        $out=Script::generate(['_input_contract_version'=>12,'episode_duration_policy'=>Duration::snapshot([],10,0,[])],['max_tokens'=>8192],['system_prompt'=>'','content'=>'读信'],
            static function($key,$input)use(&$calls,$plan){
                $calls[]=$key;
                if(str_contains($key,'skeleton'))$payload=$plan;
                else $payload=['storyboard'=>[['shot_id'=>'s1_1','scene_ref_id'=>'home','scene_name'=>str_contains($key,'repair')?'母亲老屋':'社区资料室',
                    'subject_ref_ids'=>['p'],'interior_exterior'=>'interior','visual_description'=>'母亲老屋内，陈溪读信。','dialogue'=>'陈溪：找到信了。','recommended_duration_seconds'=>10]]];
                return ['result'=>['content'=>json_encode($payload)]];
            });
        self::assertSame(['v3_timed_skeleton_0','v3_timed_scene_1_1','v3_timed_scene_1_1_repair'],$calls);
        self::assertSame('home',$out['payload']['storyboard'][0]['scene_ref_id']);self::assertSame(1,$out['payload']['timing_diagnostics']['content_repairs']);
    }

    public function testNewContractFindsThePaidSceneEightLocationConflictInTheSkeleton():void
    {
        $plan=$this->plan();
        $plan['locations']=[['id'=>'loc_lingxiao','name'=>'凌霄宝殿'],['id'=>'loc_renshisi','name'=>'人事司']];
        $plan['scene_beats']=[['scene_ref_id'=>'loc_lingxiao','goal'=>'玉帝调整人事','entry'=>'凌霄宝殿内，玉帝宣布任命',
            'exit'=>'玉帝离开大殿','key_events'=>['林晓站在人事司门前感叹主题'],
            'scene_script'=>['action'=>['玉帝在凌霄宝殿宣布任命'],'dialogue'=>[['speaker'=>'玉帝','text'=>'此事交你处理。']]],
            'duration_seconds'=>10,'shot_durations'=>[10]]];
        $request=['_input_contract_version'=>15,'episode_duration_policy'=>Duration::snapshot([],10,0,[])];
        try {Timed::assertSkeleton($plan,$request);self::fail('A cross-location beat reached paid storyboard generation');}
        catch(\RuntimeException $error){self::assertSame(422,$error->getCode());self::assertStringContainsString('同一场骨架跨越',$error->getMessage());}
        $plan['scene_beats'][0]['key_events']=['玉帝在凌霄宝殿宣布任命'];
        Timed::assertSkeleton($plan,$request);
    }

    public function testNewContractRequiresPerformableSceneScriptBeforeStoryboards():void
    {
        $plan=$this->plan();$plan['scene_beats']=[['scene_ref_id'=>'home','goal'=>'找到信','entry'=>'母亲老屋内，陈溪进门','exit'=>'陈溪读完信',
            'key_events'=>['陈溪找到信'],'duration_seconds'=>10,'shot_durations'=>[10]]];
        $request=['_input_contract_version'=>15,'episode_duration_policy'=>Duration::snapshot([],10,0,[])];
        $this->expectExceptionMessage('缺少可拍动作或对白结构');
        Timed::assertSkeleton($plan,$request);
    }
}
