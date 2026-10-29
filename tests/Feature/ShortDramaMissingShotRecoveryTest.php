<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaEpisodeDuration as Duration, ShortDramaScriptGeneration as Script, ShortDramaSceneBatch as Batch};
use PHPUnit\Framework\TestCase;

class ShortDramaMissingShotRecoveryTest extends TestCase
{
    private static function skeleton(bool $timed): array
    {
        $beat = ['scene_ref_id'=>'l1','goal'=>'寻找钥匙','entry'=>'走进房间','exit'=>'开门离开',
            'key_events'=>['找到钥匙','打开门']];
        $beat += $timed ? ['duration_seconds'=>30,'shot_durations'=>[10,10,10]] : ['shot_count'=>3];
        return ['title'=>'钥匙','story_outline'=>'甲找钥匙后离开','script_lines'=>['甲找到钥匙后离开'],
            'subjects'=>[['id'=>'p1','name'=>'甲']],'locations'=>[['id'=>'l1','name'=>'房间']], 'scene_beats'=>[$beat]];
    }

    private static function shot(int $n): array
    {
        return ['shot_id'=>'s1_'.$n,'scene_ref_id'=>'l1','scene_name'=>'房间','subject_ref_ids'=>['p1'],
            'visual_description'=>'原始镜头内容'.$n,'dialogue'=>'','recommended_duration_seconds'=>10];
    }

    public function testTimedPartialRetainsShotsAndResumesOnlyMissingReceipts(): void
    {
        $request=['_input_contract_version'=>20,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];
        $saved=[]; $submissions=[]; $inputs=[]; $interrupt=true;
        $provider=static function($key,$input)use(&$saved,&$submissions,&$inputs,&$interrupt):array{
            $signature=hash('sha256',json_encode($input));
            if(isset($saved[$key])) { self::assertSame($inputs[$key],$signature,'replayed unit input changed'); return $saved[$key]; }
            $submissions[]=$key; $inputs[$key]=$signature;
            if($key==='v3_timed_skeleton_0')$payload=self::skeleton(true);
            elseif($key==='v3_timed_scene_1_1')$payload=['storyboard'=>[self::shot(1)]];
            elseif($key==='v3_timed_scene_1_1_missing_2'){
                self::assertStringContainsString('原始镜头内容1',$input['content']);
                $payload=['storyboard'=>[self::shot(2)]];
            } elseif($key==='v3_timed_scene_1_1_missing_3'){
                self::assertStringContainsString('原始镜头内容1',$input['content']);
                self::assertStringContainsString('原始镜头内容2',$input['content']);
                if($interrupt){$interrupt=false;array_pop($submissions);unset($inputs[$key]);throw new \RuntimeException('等待继续',425);}
                $payload=['storyboard'=>[self::shot(3)]];
            } else throw new \RuntimeException('Unexpected unit '.$key);
            return $saved[$key]=['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
        };
        $run=static fn()=>Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事'],$provider);
        try{$run();self::fail('Dispatch boundary must interrupt');}catch(\RuntimeException $e){self::assertSame(425,$e->getCode());}
        $result=$run()['payload'];
        self::assertSame([self::shot(1),self::shot(2),self::shot(3)],$result['storyboard']);
        self::assertSame(['v3_timed_skeleton_0','v3_timed_scene_1_1','v3_timed_scene_1_1_missing_2','v3_timed_scene_1_1_missing_3'],$submissions);
    }

    public function testOrdinaryPathPreservesExistingShotsAndGeneratesOnlyMissingId(): void
    {
        $calls=[];
        $result=Script::generate(['_input_contract_version'=>20],['max_tokens'=>4096],['system_prompt'=>'创作','content'=>'故事'],
            static function($key,$input)use(&$calls){
                $calls[]=$key;
                if($key==='v3_skeleton')$payload=self::skeleton(false);
                elseif($key==='v3_scene_1_1_3')$payload=['storyboard'=>[self::shot(1),self::shot(3)]];
                elseif($key==='v3_scene_1_2_1_missing_from_1_3')$payload=['storyboard'=>[self::shot(2)]];
                else throw new \RuntimeException('Unexpected unit '.$key);
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
            });
        self::assertSame([self::shot(1),self::shot(2),self::shot(3)],$result['payload']['storyboard']);
        self::assertSame(['v3_skeleton','v3_scene_1_1_3','v3_scene_1_2_1_missing_from_1_3'],$calls);
    }

    /** @dataProvider ambiguousShots */
    public function testAmbiguousPartialUsesOriginalWholePartRepair(array $bad): void
    {
        $request=['_input_contract_version'=>20,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];$calls=[];
        $result=Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事'],
            static function($key,$input)use(&$calls,$bad){
                $calls[]=$key;
                if($key==='v3_timed_skeleton_0')$payload=self::skeleton(true);
                elseif($key==='v3_timed_scene_1_1')$payload=['storyboard'=>$bad];
                elseif($key==='v3_timed_scene_1_1_repair')$payload=['storyboard'=>[self::shot(1),self::shot(2),self::shot(3)]];
                else throw new \RuntimeException('Unexpected unit '.$key);
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
            });
        self::assertCount(3,$result['payload']['storyboard']);
        self::assertSame(['v3_timed_skeleton_0','v3_timed_scene_1_1','v3_timed_scene_1_1_repair'],$calls);
    }
    public static function ambiguousShots(): array
    {
        $wrong=self::shot(1);$wrong['scene_ref_id']='other';
        return [[[self::shot(1),self::shot(1)]],[[self::shot(2),self::shot(1)]],[[$wrong]],[[array_replace(self::shot(1),['shot_id'=>'unknown'])]]];
    }

    public function testProviderFailureDoesNotSubmitMissingShotOrRepair(): void
    {
        $request=['_input_contract_version'=>20,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];$calls=[];
        try{
            Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事'],static function($key)use(&$calls){
                $calls[]=$key;if($key!=='v3_timed_skeleton_0')throw new \RuntimeException('unknown provider outcome',503);
                return ['result'=>['content'=>json_encode(self::skeleton(true))]];
            });
            self::fail('Unknown provider outcome must propagate');
        }catch(\RuntimeException $e){self::assertSame(503,$e->getCode());}
        self::assertSame(['v3_timed_skeleton_0','v3_timed_scene_1_1'],$calls);
    }

    public function testHistoricalTaskKeepsItsExistingFullRepairReceiptPath(): void
    {
        $request=['_input_contract_version'=>19,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];$calls=[];
        $result=Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事'],static function($key)use(&$calls){
            $calls[]=$key;
            if($key==='v3_timed_skeleton_0')$payload=self::skeleton(true);
            elseif($key==='v3_timed_scene_1_1')$payload=['storyboard'=>[self::shot(1)]];
            elseif($key==='v3_timed_scene_1_1_repair')$payload=['storyboard'=>[self::shot(1),self::shot(2),self::shot(3)]];
            else throw new \RuntimeException('Unexpected unit '.$key);
            return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
        });
        self::assertCount(3,$result['payload']['storyboard']);
        self::assertSame(['v3_timed_skeleton_0','v3_timed_scene_1_1','v3_timed_scene_1_1_repair'],$calls);
    }

    public function testBatchedPartialKeepsValidShotsFromBothScenes(): void
    {
        $plan=self::skeleton(true);$plan['scene_beats'][0]['duration_seconds']=20;$plan['scene_beats'][0]['shot_durations']=[10,10];
        $plan['locations'][]=['id'=>'l2','name'=>'走廊'];
        $plan['scene_beats'][]=['scene_ref_id'=>'l2','goal'=>'走过走廊','entry'=>'走廊站立','exit'=>'走廊尽头',
            'key_events'=>['走向走廊尽头'],'duration_seconds'=>10,'shot_durations'=>[10]];
        $second=array_replace(self::shot(3),['shot_id'=>'s2_1','scene_ref_id'=>'l2','scene_name'=>'走廊','visual_description'=>'甲走过走廊']);
        $request=['_input_contract_version'=>20,'_scene_batch_policy'=>Batch::POLICY,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];
        $calls=[];$content='故事';
        $result=Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>$content,'_stage_content'=>$content],
            static function($key,$input)use(&$calls,$plan,$second){
                $calls[]=$key;
                if($key==='v3_timed_skeleton_0')$payload=$plan;
                elseif($key==='v3_timed_batch_1_2')$payload=['storyboard'=>[self::shot(1),$second]];
                elseif($key==='v3_timed_scene_1_1_missing_2')$payload=['storyboard'=>[self::shot(2)]];
                else throw new \RuntimeException('Unexpected unit '.$key);
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
            });
        self::assertSame(['s1_1','s1_2','s2_1'],array_column($result['payload']['storyboard'],'shot_id'));
        self::assertSame(['v3_timed_skeleton_0','v3_timed_batch_1_2','v3_timed_scene_1_1_missing_2'],$calls);
    }
}
