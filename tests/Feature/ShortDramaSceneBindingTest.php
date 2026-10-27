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

    public function testLatestContractUsesLockedEventsWhenSceneScriptIsMissingWithoutRetry():void
    {
        $plan=$this->plan();$plan['scene_beats']=[['scene_ref_id'=>'home','scene_name'=>'母亲老屋','goal'=>'找到信',
            'entry'=>'母亲老屋内，陈溪进门','exit'=>'陈溪拆开信','key_events'=>['陈溪找到信','陈溪拆开信'],
            'duration_seconds'=>10,'shot_durations'=>[10]]];
        $request=['_input_contract_version'=>16,'episode_duration_policy'=>Duration::snapshot([],10,0,[])];
        $calls=[];
        $result=Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'陈溪找到信'],
            static function($key,$input)use($plan,&$calls){
                $calls[]=$key;
                if(str_contains($key,'skeleton'))return ['result'=>['content'=>json_encode($plan,JSON_UNESCAPED_UNICODE)]];
                return ['result'=>['content'=>json_encode(['storyboard'=>[['shot_id'=>'s1_1','scene_ref_id'=>'home',
                    'scene_name'=>'母亲老屋','subject_ref_ids'=>['p'],'interior_exterior'=>'interior',
                    'visual_description'=>'母亲老屋内，陈溪找到并拆开信。','dialogue'=>'',
                    'voice_role'=>'','speech_type'=>'none','recommended_duration_seconds'=>10]]],JSON_UNESCAPED_UNICODE)]];
            });
        self::assertSame(1,count(array_filter($calls,static fn($key)=>str_contains($key,'skeleton'))));
        self::assertSame(['陈溪找到信','陈溪拆开信'],$result['payload']['scene_screenplays'][0]['action']);
        self::assertSame([],$result['payload']['scene_screenplays'][0]['dialogue']);
        self::assertSame('beat_fallback',$result['payload']['scene_screenplays'][0]['source']);
    }

    public function testLatestContractNormalizesDialogueAliasesAndRetainsFallbackSource():void
    {
        $plan=$this->plan();$plan['scene_beats']=[['key_events'=>['陈溪找到信'],
            'scene_script'=>['actions'=>'陈溪拆开信','dialogue_lines'=>[['character'=>'陈溪','line'=>'原来如此。'],'母亲：我等你很久了。']]]];
        $request=['_input_contract_version'=>16];
        $normalized=Timed::normalizeSceneScripts($plan,$request);
        self::assertSame(['陈溪拆开信'],$normalized['scene_beats'][0]['scene_script']['action']);
        self::assertSame([['speaker'=>'陈溪','text'=>'原来如此。'],['speaker'=>'母亲','text'=>'我等你很久了。']],$normalized['scene_beats'][0]['scene_script']['dialogue']);
        self::assertSame('model',$normalized['scene_beats'][0]['scene_script_source']);
        unset($plan['scene_beats'][0]['scene_script']['actions']);
        $fallback=Timed::normalizeSceneScripts($plan,$request);
        self::assertSame('beat_fallback',Timed::normalizeSceneScripts($fallback,$request)['scene_beats'][0]['scene_script_source']);
    }

    public function testLatestContractCorrectsBeatLabelButStillRejectsCrossLocationAction():void
    {
        $plan=$this->plan();$plan['scene_beats']=[['scene_ref_id'=>'book','scene_name'=>'旧书屋','goal'=>'找到信',
            'entry'=>'母亲老屋内，陈溪进门','exit'=>'陈溪拆开信','key_events'=>['陈溪找到信'],
            'scene_script'=>['action'=>['陈溪在母亲老屋拆开信'],'dialogue'=>[]],
            'duration_seconds'=>10,'shot_durations'=>[10]]];
        $request=['_input_contract_version'=>16,'episode_duration_policy'=>Duration::snapshot([],10,0,[])];
        $fixed=Binding::normalize($plan,$request);
        self::assertSame('home',$fixed['scene_beats'][0]['scene_ref_id']);
        self::assertSame('母亲老屋',$fixed['scene_beats'][0]['scene_name']);
        Timed::assertSkeleton($fixed,$request);
        $fixed['scene_beats'][0]['key_events']=['陈溪进入社区资料室'];
        $this->expectExceptionMessage('同一场骨架跨越');
        Timed::assertSkeleton($fixed,$request);
    }

    public function testNewContractKeepsTheSceneScriptAsASeparateResultBeforeShots():void
    {
        $plan=$this->plan();$script=['action'=>['陈溪进门找到信','陈溪拆开信'],
            'dialogue'=>[['speaker'=>'陈溪','text'=>'原来她一直在等我。']]];
        $plan['scene_beats']=[['scene_ref_id'=>'home','goal'=>'找到信','entry'=>'母亲老屋内，陈溪进门','exit'=>'陈溪拆开信',
            'key_events'=>['陈溪拆开信'],'scene_script'=>$script,'duration_seconds'=>10,'shot_durations'=>[10]]];
        $request=['_input_contract_version'=>15,'episode_duration_policy'=>Duration::snapshot([],10,0,[])];
        $checkpoints=[];$shotContext=[];
        $result=Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'陈溪找到信'],
            static function($key,$input)use($plan,&$shotContext){
                if(str_contains($key,'skeleton'))return ['result'=>['content'=>json_encode($plan,JSON_UNESCAPED_UNICODE)]];
                $shotContext=\app\common\service\app\aigc_short_drama\ShortDramaStructuredResponse::decode(['content'=>$input['content']]);
                return ['result'=>['content'=>json_encode(['storyboard'=>[['shot_id'=>'s1_1','scene_ref_id'=>'home',
                    'scene_name'=>'母亲老屋','subject_ref_ids'=>['p'],'interior_exterior'=>'interior',
                    'visual_description'=>'母亲老屋内，陈溪拆开信。','dialogue'=>'陈溪：原来她一直在等我。',
                    'voice_role'=>'陈溪','speech_type'=>'character','recommended_duration_seconds'=>10]]],JSON_UNESCAPED_UNICODE)]];
            },static function($event,$payload)use(&$checkpoints){if($event==='script_checkpoint')$checkpoints[]=$payload;});
        self::assertSame($script,$shotContext['scene_beat']['scene_script']);
        self::assertArrayNotHasKey('scene_script',$shotContext['scene_continuity'][0]);
        self::assertSame($script['action'],$checkpoints[0]['scene_screenplays'][0]['action']);
        self::assertSame($checkpoints[0]['scene_screenplays'],$result['payload']['scene_screenplays']);
    }
}
