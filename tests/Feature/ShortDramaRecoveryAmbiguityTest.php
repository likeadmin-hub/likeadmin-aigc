<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\ShortDramaScriptGeneration as Script;
use PHPUnit\Framework\TestCase;
final class ShortDramaRecoveryAmbiguityTest extends TestCase
{
    public function testGenerationCannotAcceptDuplicateShotFieldsThroughPrefixRecovery():void
    {
        $skeleton=['title'=>'故事','story_outline'=>'原文','subjects'=>[['id'=>'p','name'=>'主角']],'locations'=>[['id'=>'l','name'=>'屋内']],
            'scene_beats'=>[['scene_ref_id'=>'l','goal'=>'重逢','entry'=>'进入','exit'=>'离开','shot_count'=>1]]];
        $this->expectException(\RuntimeException::class);
        Script::generate(['_input_contract_version'=>27],['max_tokens'=>4096,'context_window'=>100000],['system_prompt'=>'','content'=>'写完整剧本'],static function($key,$input)use($skeleton){
            if(str_contains($key,'skeleton'))return ['result'=>['content'=>json_encode($skeleton)]];
            preg_match('/分段任务=(.+)$/us',$input['content'],$m);$task=json_decode($m[1]??'{}',true);$id=$task['required_shot_ids'][0]??'s1_1';
            return ['result'=>['content'=>'{"storyboard":[{"shot_id":"'.$id.'","scene_ref_id":"l","subject_ref_ids":["p"],"visual_description":"原动作","visual_description":"覆盖后的动作","dialogue":"","voice_role":"","speech_type":"none","recommended_duration_seconds":5}]}']];
        });
    }
}
