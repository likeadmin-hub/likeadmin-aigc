<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\{ShortDramaScriptPreview as Preview,ShortDramaTimedScriptGeneration as Timed,ShortDramaEpisodeDuration as Duration};
use PHPUnit\Framework\TestCase;

class ShortDramaScriptPreviewTest extends TestCase
{
    public function testTimedSkeletonAndEveryAcceptedPartPersistBeforeNextProviderCall():void
    {
        $history=[];$skeleton=['title'=>'来信','story_outline'=>'找到母亲的信','script_lines'=>['陈溪读完信后离开'],
            'subjects'=>[['id'=>'p','name'=>'陈溪']],'locations'=>[['id'=>'l','name'=>'老屋']],
            'scene_beats'=>[['scene_ref_id'=>'l','goal'=>'读信','entry'=>'入屋','exit'=>'离开','key_events'=>['读信'],
                'duration_seconds'=>60,'shot_durations'=>[10,10,10,10,10,10]]]];
        $out=Timed::generate(['episode_duration_policy'=>Duration::snapshot([],60,0,[])],['system_prompt'=>'','content'=>'故事'],
            static function($key)use(&$history,$skeleton){
                if(str_contains($key,'skeleton'))return $skeleton;
                preg_match('/timed_scene_1_(\d+)/',$key,$m);$start=(int)$m[1];
                self::assertSame($skeleton['script_lines'],end($history)['script_lines']);
                self::assertCount($start-1,end($history)['storyboard']??[]);
                $shots=[];for($i=$start;$i<$start+2;$i++)$shots[]=['shot_id'=>'s1_'.$i,'scene_ref_id'=>'l','subject_ref_ids'=>['p'],
                    'visual_description'=>'陈溪读信','dialogue'=>'','recommended_duration_seconds'=>10];
                return ['storyboard'=>$shots];
            },static function($event,$data)use(&$history){if($event==='script_checkpoint')$history[]=Preview::project($data);},4096);
        self::assertCount(4,$history);self::assertCount(6,end($history)['storyboard']);
        self::assertSame($out['storyboard'],end($history)['storyboard']);
        self::assertArrayNotHasKey('scene_beats',end($history));
    }
    public function testLegacyReceiptProjectionRestoresScriptAndEarlierShotsWithoutProviderCalls():void
    {
        $row=static fn($key,$value,$error='')=>['unit_key'=>$key,'status'=>'received','error'=>$error,'result_json'=>json_encode(['result'=>['content'=>json_encode($value)]])];
        $history=Preview::fromReceipts([
            $row('v3_policy2_timed_skeleton_0',['title'=>'来信','story_outline'=>'读信','script_lines'=>['正文保留'],'locations'=>[['id'=>'l','name'=>'老屋']]]),
            $row('v3_policy2_timed_scene_1_1',['storyboard'=>[['shot_id'=>'s1_1','visual_description'=>'读信']]]),
            $row('v3_policy2_timed_scene_1_2',['storyboard'=>[['shot_id'=>'s1_2','visual_description'=>'错误']]],'validation: invalid'),
            $row('v3_policy2_timed_scene_1_2_repair',['storyboard'=>[['shot_id'=>'s1_2','visual_description'=>'收好信']]]),
            $row('v3_policy2_audit',['title'=>'不得混入审校']),
        ]);
        self::assertSame(['正文保留'],$history['script_lines']);self::assertSame('来信',$history['title']);
        self::assertSame(['读信','收好信'],array_column($history['storyboard'],'visual_description'));
    }
}
