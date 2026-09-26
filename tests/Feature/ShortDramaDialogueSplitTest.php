<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaDialogueSplit as Split;
use app\common\service\app\aigc_short_drama\ShortDramaTimedScriptGeneration as Timed;
use app\common\service\app\aigc_short_drama\ShortDramaEpisodeDuration as Timing;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationShotTiming;
use app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflow;
use PHPUnit\Framework\TestCase;

class ShortDramaDialogueSplitTest extends TestCase
{
    private function request(): array { return ['_input_contract_version'=>2, 'episode_duration_policy'=>Timing::snapshot([],0,0,[])]; }
    private function shot(int $length=120): array { return ['shot_id'=>'s1','dialogue'=>$length>84 ? str_repeat('字',intdiv($length,2)).'。'.str_repeat('字',$length-intdiv($length,2)).'。' : str_repeat('字',$length),'scene_ref_id'=>'room','subject_ref_ids'=>['a'], 'voice_role'=>'甲','speech_type'=>'character','visual_description'=>'甲说完前半段，起身说完后半段','recommended_duration_seconds'=>5,'video_prompt'=>'旧完整视频提示词']; }
    private function reply(array $shot): array {
        $text=$shot['dialogue'];$half=intdiv(mb_strlen($text),2);
        return ['segments'=>array_map(static fn($text)=>['dialogue'=>$text,'visual_description'=>'甲继续说话，承接上一动作','composition'=>'中景','camera_movement'=>'固定','action'=>'说话','result'=>'继续讲述','sound_effect'=>'','recommended_duration_seconds'=>Split::requiredSeconds($text)], [mb_substr($text,0,$half),mb_substr($text,$half)])];
    }
    public function testFiveSecondSeedDoesNotTriggerSplitWithinMaximum(): void {
        $result=Split::adapt(['storyboard'=>[$this->shot(48)]],$this->request(),static function(){self::fail('No split call expected');},'test');
        self::assertCount(1,$result['storyboard']);self::assertEquals(9,$result['storyboard'][0]['recommended_duration_seconds']);
        self::assertSame(str_repeat('字',48),$result['storyboard'][0]['dialogue']);
    }
    public function testOverMaximumSplitsWithExactDialogueAndStableReferences(): void {
        $shot=$this->shot();$calls=0;
        $result=Split::adapt(['storyboard'=>[$shot]],$this->request(),function($key,$input)use($shot,&$calls){$calls++;self::assertStringContainsString('连续原文切片',$input['system_prompt']);return $this->reply($shot);},'test');
        self::assertSame(1,$calls);self::assertSame(['s1_p1','s1_p2'],array_column($result['storyboard'],'shot_id'));
        self::assertSame($shot['dialogue'],implode('',array_column($result['storyboard'],'dialogue')));
        foreach($result['storyboard'] as $part){self::assertSame('room',$part['scene_ref_id']);self::assertSame(['a'],$part['subject_ref_ids']);self::assertSame('甲',$part['voice_role']);self::assertArrayNotHasKey('video_prompt',$part);}
    }
    public function testInvalidSplitCannotDropDialogueAndHasBoundedRepair(): void {
        $calls=0;
        try { Split::adapt(['storyboard'=>[$this->shot()]],$this->request(),function()use(&$calls){$calls++;return $this->reply($this->shot(60));},'test');self::fail('Must reject data loss'); }
        catch(\RuntimeException $e){self::assertSame(409,$e->getCode());self::assertSame(2,$calls);}
    }
    public function testLockedShortTimelineRejectsBeforePaidSplit(): void {
        $request=$this->request();$request['episode_duration_policy']=Timing::snapshot([],5,0,[]);
        $this->expectExceptionCode(409);
        Split::adapt(['storyboard'=>[$this->shot()]],$request,static function(){self::fail('No paid call for impossible timing');},'test');
    }
    public function testLongLockedTimelineSplitRetainsTotal(): void {
        $shot=$this->shot();$shot['recommended_duration_seconds']=22;
        $request=$this->request();$request['episode_duration_policy']=Timing::snapshot([],22,0,[]);
        $result=Split::adapt(['storyboard'=>[$shot]],$request,fn()=>$this->reply($shot),'test');
        self::assertEquals(22,array_sum(array_column($result['storyboard'],'recommended_duration_seconds')));
    }
    public function testConfiguredMaximumAndLegacyTasksRemainDistinct(): void {
        $request=$this->request();$request['shot_duration_rule']=['max_seconds'=>30];$shot=$this->shot();
        $result=Split::adapt(['storyboard'=>[$shot]],$request,static function(){self::fail('Within tenant maximum');},'test');
        self::assertCount(1,$result['storyboard']);self::assertEquals(21,$result['storyboard'][0]['recommended_duration_seconds']);
        $request['_input_contract_version']=1;
        self::assertSame(['storyboard'=>[$shot]],Split::adapt(['storyboard'=>[$shot]],$request,static function(){self::fail('Legacy');},'test'));
    }
    public function testTimedEngineUsesDurableSplitCallbackWithoutRegeneratingOtherShots(): void {
        $shot=$this->shot();$shot['shot_id']='s1_1';$calls=[];
        $result=Timed::generate($this->request(),['system_prompt'=>'创作','content'=>'原文'],function($key)use($shot,&$calls){
            $calls[]=$key;
            if(str_contains($key,'skeleton'))return ['title'=>'测试','story_outline'=>'甲说话','script_lines'=>['原文'],'subjects'=>[['id'=>'a','name'=>'甲']],'locations'=>[['id'=>'room','name'=>'房间']], 'scene_beats'=>[['scene_ref_id'=>'room','goal'=>'说明','entry'=>'坐下','exit'=>'起身','key_events'=>['完整对白'],'duration_seconds'=>5,'shot_durations'=>[5]]]];
            if(str_contains($key,'dialogue_split'))return $this->reply($shot);
            return ['storyboard'=>[$shot]];
        },null);
        self::assertCount(3,$calls);self::assertCount(2,$result['storyboard']);self::assertSame($shot['dialogue'],implode('',array_column($result['storyboard'],'dialogue')));
    }
    public function testCanvasPlansSplitsBeforeBindingAndRejectsOversizeVideoNodes(): void {
        $workflow=['workflow_snapshot'=>['key'=>ConversationWorkflow::KEY,'version'=>ConversationWorkflow::VERSION,'shot_duration_rule'=>['max_seconds'=>12]],'stage_state'=>['key'=>'script']];
        self::assertStringContainsString('上限12秒',ConversationShotTiming::instruction($workflow));
        self::assertStringContainsString('不删、缩写、换序',ConversationShotTiming::instruction($workflow));
        ConversationShotTiming::assertProposals($workflow,[['artifact'=>'storyboard_video','duration_seconds'=>12]]);
        $old=$workflow;$old['workflow_snapshot']['version']='2026-09-26.1';
        self::assertSame('',ConversationShotTiming::instruction($old));
        ConversationShotTiming::assertProposals($old,[['artifact'=>'storyboard_video','duration_seconds'=>20]]);
        $this->expectException(\app\common\service\app\aigc_short_drama\canvas_agent\ConversationWorkflowValidationException::class);
        ConversationShotTiming::assertProposals($workflow,[['artifact'=>'storyboard_video','duration_seconds'=>13]]);
    }

    public function testSplittingInMiddleOfOriginalSentenceIsRejected(): void {
        $shot=$this->shot();$shot['dialogue']=str_repeat('字',120);
        $this->expectExceptionCode(409);
        Split::validate($shot,$this->reply($shot),['min_seconds'=>4,'max_seconds'=>15],false);
    }

    public function testTruncatedSplitDoesNotRestartWholeScene(): void {
        $calls=0;
        try {Split::adapt(['storyboard'=>[$this->shot()]],$this->request(),static function()use(&$calls){$calls++;throw new \RuntimeException('截断',413);},'test');self::fail('Must reject incomplete output');}
        catch(\RuntimeException $error){self::assertSame(409,$error->getCode());self::assertSame(1,$calls);}
    }

    public function testOuterEngineRetainsBothPaidReceiptsAndOnlySplitsTheFailedShot(): void {
        $shot=$this->shot();$keys=[];
        $request=['_input_contract_version'=>2];
        $result=\app\common\service\app\aigc_short_drama\ShortDramaScriptGeneration::generate($request,
            ['context_window'=>32768,'max_output_tokens'=>12000],['system_prompt'=>'保留质量','content'=>'原文'],
            function($key,$input,$budget,$model)use(&$keys,$shot){
                $keys[]=$key;
                $payload=str_contains($key,'dialogue_split')?$this->reply($shot):[
                    'title'=>'测试','story_outline'=>'甲说话','subjects'=>[['id'=>'a','name'=>'甲']],
                    'locations'=>[['id'=>'room','name'=>'房间']],'storyboard'=>[$shot]];
                return ['model'=>$model,'result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
            });
        self::assertCount(2,$keys);self::assertSame('v3_script',$keys[0]);self::assertStringContainsString('dialogue_split',$keys[1]);
        self::assertCount(2,$result['receipts']);self::assertCount(2,$result['payload']['storyboard']);
        self::assertSame($shot['dialogue'],implode('',array_column($result['payload']['storyboard'],'dialogue')));
    }

    public function testTimelineSplitCanFollowSentenceLengthsWithoutMovingBoundaries(): void {
        $first=str_repeat('甲',54).'。';$last=str_repeat('乙',66).'。';
        $shot=$this->shot();$shot['dialogue']=$first.$last;$shot['recommended_duration_seconds']=22;
        $shot['shot_id']=str_repeat('s',40);$shot['time_range']='旧范围';$shot['action']='原镜头全部动作';
        $request=$this->request();$request['episode_duration_policy']=Timing::snapshot([],0,0,[['start_seconds'=>5,'end_seconds'=>27,'duration_seconds'=>22]]);
        $result=Timed::generate($request,['system_prompt'=>'创作','content'=>'原文'],function($key)use($shot,$first,$last){
            if(str_contains($key,'skeleton'))return ['title'=>'测试','story_outline'=>'讲述','script_lines'=>['原文'],'subjects'=>[['id'=>'a','name'=>'甲']],'locations'=>[['id'=>'room','name'=>'房间']],'storyboard'=>[$shot]];
            $parts=$this->reply($shot)['segments'];
            $parts[0]['dialogue']=$first;$parts[0]['recommended_duration_seconds']=10;
            $parts[1]['dialogue']=$last;$parts[1]['recommended_duration_seconds']=12;
            return ['segments'=>$parts];
        },null);
        self::assertSame(['00:05-00:15','00:15-00:27'],array_column($result['storyboard'],'time_range'));
        foreach($result['storyboard'] as $part){self::assertLessThanOrEqual(40,mb_strlen($part['shot_id']));self::assertSame('说话',$part['action']);}
    }
}
