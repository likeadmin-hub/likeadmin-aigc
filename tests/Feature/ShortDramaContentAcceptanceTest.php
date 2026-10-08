<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaContentAcceptance as Acceptance;
use app\common\service\app\aigc_short_drama\ShortDramaShotSemantics as Semantics;
use app\common\service\app\aigc_short_drama\ShortDramaContinuityState as State;
use app\common\service\app\aigc_short_drama\AigcShortDramaService as Service;
use PHPUnit\Framework\TestCase;

class ShortDramaContentAcceptanceTest extends TestCase
{
    private function plan(): array
    {
        return ['subjects'=>[['id'=>'p','name'=>'陈伯','category'=>'character','description'=>'教师']],
            'locations'=>[['id'=>'room','name'=>'陈伯家屋内']], 'script_lines'=>['原始正文'],
            'storyboard'=>[['shot_id'=>'s1','scene_ref_id'=>'room','subject_ref_ids'=>['p'],'interior_exterior'=>'interior',
                'visual_description'=>'陈伯拿出信件。','dialogue'=>'','voice_role'=>'','speech_type'=>'none',
                'recommended_duration_seconds'=>10,'image_url'=>'keep-original-image','video_url'=>'keep-original-video']]];
    }

    public function testMissingRequiredEventIsRepairedAndRecheckedWithoutTouchingMediaOrBudget(): void
    {
        $before=$this->plan(); $checks=0;
        $result=Acceptance::generate($before,['prompt'=>'陈伯读出学生问候。'],function($key,$input,$tokens,$validate) use (&$checks) {
            $data=json_decode($input['content'],true);
            if(isset($data['source'])) $reply=['requirements'=>[['text'=>'陈伯读出学生问候','source_index'=>0,'kind'=>'event']]];
            elseif(isset($data['claims'])) $reply=['verdicts'=>array_map(fn($c)=>['id'=>$c['id'],'supported'=>true,'reason'=>'明确读出问候'],$data['claims'])];
            elseif(!isset($data['shots']) && !isset($data['targets']) && isset($data['subjects'])) $reply=['categories'=>array_map(fn($s)=>['id'=>$s['id'],'category'=>$s['category']],$data['subjects'])];
            elseif(isset($data['targets'])) $reply=['patches'=>[
                ['shot_id'=>'s1','field'=>'dialogue','before'=>'','after'=>'老师，祝您身体健康。'],
                ['shot_id'=>'s1','field'=>'voice_role','before'=>'','after'=>'陈伯'],
                ['shot_id'=>'s1','field'=>'speech_type','before'=>'none','after'=>'character']], 'subject_categories'=>[]];
            else {
                $checks++; $dialogue=$data['shots'][0]['dialogue'];
                $reply=['checks'=>[['id'=>'r1','status'=>$dialogue===''?'absent':'covered','evidence'=>$dialogue===''?[]:[['shot_id'=>'s1','field'=>'dialogue','quote'=>$dialogue]],'target_shot_ids'=>['s1']]],'issues'=>[]];
            }
            $validate($reply); return $reply;
        });
        self::assertSame(2,$checks); self::assertSame('verified',$result['content_acceptance']['status']);
        self::assertSame('老师，祝您身体健康。',$result['storyboard'][0]['dialogue']);
        foreach(['image_url','video_url','recommended_duration_seconds','shot_id'] as $field) self::assertSame($before['storyboard'][0][$field],$result['storyboard'][0][$field]);
        self::assertSame(['原始正文'],$result['content_acceptance']['source_script_lines']);
    }

    public function testCoverageAggregatesAcrossBlocksAndDoesNotRequireEveryScenePerBlock(): void
    {
        $plan=$this->plan(); $plan['storyboard']=array_map(fn($i)=>array_replace($plan['storyboard'][0],['shot_id'=>'s'.$i]),range(1,17)); $sizes=[];
        $report=Acceptance::inspect($plan,[['id'=>'r1','kind'=>'event','text'=>'取信']],function($key,$input,$tokens,$validate) use (&$sizes) {
            $data=json_decode($input['content'],true); if(isset($data['claims'])){$reply=['verdicts'=>array_map(fn($c)=>['id'=>$c['id'],'supported'=>true,'reason'=>'原文明确拿信'],$data['claims'])];$validate($reply);return $reply;} $sizes[]=count($data['shots']); $last=$data['shots'][0]['shot_id']==='s17';
            $reply=['checks'=>[['id'=>'r1','status'=>$last?'covered':'absent','evidence'=>$last?[['shot_id'=>'s17','field'=>'visual_description','quote'=>'陈伯拿出信件。']]:[]]],'issues'=>[]];
            $validate($reply); return $reply;
        });
        self::assertSame([8,8,1],$sizes); self::assertSame([],$report['issues']);
    }

    public function testFabricatedCoverageCannotPass(): void
    {
        $this->expectException(\RuntimeException::class);
        Acceptance::inspect($this->plan(),[['id'=>'r1','kind'=>'event','text'=>'读信']],static function($key,$input,$tokens,$validate) {
            $reply=['checks'=>[['id'=>'r1','status'=>'covered','evidence'=>[['shot_id'=>'s1','field'=>'dialogue','quote'=>'不存在的读信台词']]]],'issues'=>[]];
            $validate($reply); return $reply;
        });
    }

    public function testRepairCannotChangeUnrelatedShotOrMedia(): void
    {
        $this->expectException(\RuntimeException::class);
        Acceptance::apply($this->plan(),['patches'=>[['shot_id'=>'s1','field'=>'video_url','before'=>'keep-original-video','after'=>'new']]],['s1']);
    }

    public function testExistingQuoteDoesNotProveMissingEventAndRepeatedIndividualTurnIsDetected(): void
    {
        $plan=$this->plan();$plan['storyboard'][0]['dialogue']='有件事跟你说。';
        $report=Acceptance::inspect($plan,[['id'=>'r1','kind'=>'event','text'=>'提出举办读书会']],static function($key,$input,$tokens,$validate){
            $data=json_decode($input['content'],true);
            $reply=isset($data['claims'])?['verdicts'=>[['id'=>'r1:0','supported'=>false,'reason'=>'证据没有说是什么事']]]:
                ['checks'=>[['id'=>'r1','status'=>'covered','evidence'=>[['shot_id'=>'s1','field'=>'dialogue']],'target_shot_ids'=>['s1']]],'issues'=>[]];
            $validate($reply);return $reply;
        });
        self::assertNotEmpty($report['issues']);
        self::assertStringContainsString('证据没有说是什么事',$report['issues'][0]['message']);
        $plan['storyboard'][0]['dialogue']="林舟：陈老师，您的信。\n林舟：明天我再来看您。";
        $plan['storyboard'][]=array_replace($plan['storyboard'][0],['shot_id'=>'s2','dialogue'=>'林舟：明天我再来看您。']);
        self::assertSame(['s2'],Acceptance::duplicates($plan)[0]['shot_ids']);
    }

    public function testRepairCannotReclassifyLibrarySubject(): void
    {
        $plan=$this->plan(); $plan['subjects'][0]['library_subject_id']=12;
        $this->expectException(\RuntimeException::class);
        Acceptance::apply($plan,['patches'=>[],'subject_categories'=>[['id'=>'p','before'=>'character','after'=>'prop']]],['s1']);
    }

    public function testRoomSilenceAndTwoSpeakersHaveConsistentSemantics(): void
    {
        self::assertSame('interior',Semantics::space(['scene_ref_id'=>'room'],$this->plan()['locations']));
        $plan=$this->plan(); $plan['storyboard'][0]['dialogue']='（无声）'; $plan['storyboard'][0]['speech_type']='narration';
        self::assertSame('none',Semantics::normalize($plan)['storyboard'][0]['speech_type']);
        foreach(["陈伯：下雨了。\n林舟：先搬书。",'陈伯：下雨了。林舟：先搬书。','陈伯说：下雨了。林舟笑着答：先搬书。'] as $dialogue) {
            $turns=Semantics::turns(['dialogue'=>$dialogue,'voice_role'=>'陈伯']);
            self::assertSame(['陈伯','林舟'],array_column($turns,'speaker'));
            self::assertSame(['下雨了。','先搬书。'],array_column($turns,'text'));
        }
        $method=new \ReflectionMethod(Service::class,'normalizeReadableShotVideoPrompt'); $method->setAccessible(true);
        $shot=$plan['storyboard'][0]; $shot['dialogue']="陈伯：下雨了。\n林舟：先搬书。"; $shot['speech_type']='character'; $shot['voice_role']='陈伯';
        $prompt=$method->invoke(null,"声音：陈伯说：下雨了。",$shot);
        self::assertStringContainsString('陈伯说：“下雨了。”',$prompt);
        self::assertStringContainsString('林舟说：“先搬书。”',$prompt);
        $shot['dialogue']='（无声）'; $prompt=$method->invoke(null,'',$shot);
        self::assertStringNotContainsString('开口',$prompt); self::assertStringContainsString('不说话',$prompt);
    }

    public function testVerifiedStateUpdateReplacesAliasesOnlyForItsEntityAndProperty(): void
    {
        $before=['books:state/current_location'=>'room','books:state/location'=>'yard','books:color'=>'red','person:location'=>'room'];
        self::assertSame(['books:color'=>'red','person:location'=>'room','books:location'=>'yard'],State::replaceAliases($before,'books','current_location','yard'));
        $review=State::prepare(['changes'=>[['entity_id'=>'books','field'=>'location','after'=>'yard']]],['_canonical_state'=>true,'continuity'=>['state'=>['books:state/current_location'=>'room']]]);
        self::assertSame('state/current_location',$review['changes'][0]['field']); self::assertSame('room',$review['changes'][0]['before']);
    }
}
