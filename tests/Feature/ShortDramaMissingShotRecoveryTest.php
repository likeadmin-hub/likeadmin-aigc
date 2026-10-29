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

    /** @dataProvider fieldRepairModes */
    public function testFieldRepairPreservesOtherFieldsAndNeighborShots(bool $timed): void
    {
        $request=['_input_contract_version'=>21];
        if ($timed) $request['episode_duration_policy']=Duration::snapshot([],0,0,[]);
        $calls=[];$original=[self::shot(1),self::shot(2),self::shot(3)];$original[1]['visual_description']='';
        $result=Script::generate($request,['max_tokens'=>$timed?8192:4096],['system_prompt'=>'创作','content'=>'故事'],
            static function($key,$input)use(&$calls,$timed,$original){
                $calls[]=$key;
                if(str_contains($key,'skeleton'))$payload=self::skeleton($timed);
                elseif(str_ends_with($key,'_fields_v1')) {
                    self::assertStringContainsString('原始镜头内容1',$input['content']);
                    $payload=['patches'=>[['shot_id'=>'s1_2','fields'=>['visual_description'=>'补全第二镜头']]]];
                } else $payload=['storyboard'=>$original];
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
            });
        $expected=$original;$expected[1]['visual_description']='补全第二镜头';
        self::assertSame($expected,$result['payload']['storyboard']);
        self::assertCount(3,$calls);self::assertStringEndsWith('_fields_v1',$calls[2]);
    }
    public static function fieldRepairModes(): array { return [[false],[true]]; }

    /** @dataProvider fieldRepairModes */
    public function testMixedMissingAndInvalidFieldsPreserveValidShotsAndResume(bool $timed): void
    {
        $request=['_input_contract_version'=>22];
        if ($timed) $request['episode_duration_policy']=Duration::snapshot([],0,0,[]);
        $saved=[];$signatures=[];$submissions=[];$interrupted=true;
        $bad=self::shot(3);$bad['visual_description']='';
        $provider=static function($key,$input)use(&$saved,&$signatures,&$submissions,&$interrupted,$timed,$bad){
            $signature=hash('sha256',json_encode($input));
            if(isset($saved[$key])) { self::assertSame($signatures[$key],$signature); return $saved[$key]; }
            $submissions[]=$key;$signatures[$key]=$signature;
            if(str_contains($key,'skeleton')) $payload=self::skeleton($timed);
            elseif(str_ends_with($key,'_partial_fields_v1')) {
                self::assertStringContainsString('原始镜头内容1',$input['content']);
                $payload=['patches'=>[['shot_id'=>'s1_3','fields'=>['visual_description'=>'已修复第三镜头']]]];
            } elseif(str_contains($key,'missing')) {
                if($interrupted){$interrupted=false;array_pop($submissions);unset($signatures[$key]);throw new \RuntimeException('等待继续',425);}
                $payload=['storyboard'=>[self::shot(2)]];
            } else $payload=['storyboard'=>[self::shot(1),$bad]];
            return $saved[$key]=['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
        };
        $run=static fn()=>Script::generate($request,['max_tokens'=>$timed?8192:4096],['system_prompt'=>'创作','content'=>'故事'],$provider);
        try{$run();self::fail('Must resume after interruption');}catch(\RuntimeException $error){self::assertSame(425,$error->getCode());}
        $shots=$run()['payload']['storyboard'];
        self::assertSame(self::shot(1),$shots[0]);self::assertSame(self::shot(2),$shots[1]);
        $bad['visual_description']='已修复第三镜头';self::assertSame($bad,$shots[2]);
        self::assertCount(4,$submissions);self::assertStringEndsWith('_partial_fields_v1',$submissions[2]);
        self::assertStringContainsString('missing',$submissions[3]);
    }

    public function testMixedPartialRejectsDuplicateOrUnknownIds(): void
    {
        $ids=['s1_1','s1_2','s1_3'];$bad=self::shot(1);$bad['visual_description']='';
        $plan=self::skeleton(true);$beat=$plan['scene_beats'][0];
        foreach ([[$bad,$bad],[$bad,array_replace(self::shot(2),['shot_id'=>'unknown'])]] as $shots) {
            self::assertSame([],\app\common\service\app\aigc_short_drama\ShortDramaPartialStoryboard::fieldTargets(
                ['storyboard'=>$shots],$ids,$plan,$beat,['min_seconds'=>1,'max_seconds'=>20],[10,10,10],static fn()=>null));
        }
    }

    public function testMixedPartialSavesValidatedPrefixBeforeMissingShotIsSubmitted(): void
    {
        $request=['_input_contract_version'=>22,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];
        $bad=self::shot(3);$bad['visual_description']='';$checkpoints=[];
        try {
            Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事'],
                static function($key)use($bad){
                    if(str_contains($key,'skeleton'))$payload=self::skeleton(true);
                    elseif(str_ends_with($key,'_partial_fields_v1'))$payload=['patches'=>[['shot_id'=>'s1_3','fields'=>['visual_description'=>'补全']]]];
                    elseif(str_contains($key,'missing'))throw new \RuntimeException('等待继续',425);
                    else $payload=['storyboard'=>[self::shot(1),$bad]];
                    return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]];
                }, static function($event,$payload)use(&$checkpoints){if($event==='script_checkpoint')$checkpoints[]=$payload;});
            self::fail('Missing shot should pause');
        } catch(\RuntimeException $error){self::assertSame(425,$error->getCode());}
        self::assertNotEmpty($checkpoints);
        self::assertSame(['s1_1'],array_column(end($checkpoints)['storyboard'],'shot_id'));
    }

    public function testVersion21DoesNotUseMixedRepairUnits(): void
    {
        $request=['_input_contract_version'=>21,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];$calls=[];
        $bad=self::shot(3);$bad['visual_description']='';
        Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事'],
            static function($key)use(&$calls,$bad){
                $calls[]=$key;
                if(str_contains($key,'skeleton'))$payload=self::skeleton(true);
                elseif(str_ends_with($key,'_repair'))$payload=['storyboard'=>[self::shot(1),self::shot(2),self::shot(3)]];
                else $payload=['storyboard'=>[self::shot(1),$bad]];
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]];
            });
        self::assertSame(['v3_timed_skeleton_0','v3_timed_scene_1_1','v3_timed_scene_1_1_repair'],$calls);
    }

    /** @dataProvider failedFieldRepairModes */
    public function testFieldRepairFailureKeepsValidatedPrefix(bool $timed, bool $missing): void
    {
        $request=['_input_contract_version'=>22];
        if ($timed) $request['episode_duration_policy']=Duration::snapshot([],0,0,[]);
        $bad=self::shot($missing ? 3 : 2);$bad['visual_description']='';$checkpoints=[];$calls=[];
        try {
            Script::generate($request,['max_tokens'=>$timed?8192:4096],['system_prompt'=>'创作','content'=>'故事'],
                static function($key)use(&$calls,$timed,$missing,$bad){
                    $calls[]=$key;
                    if(str_contains($key,'skeleton'))$payload=self::skeleton($timed);
                    elseif(str_contains($key,'fields_v1'))$payload=['patches'=>[]];
                    elseif(str_contains($key,'missing'))self::fail('Do not submit missing shots after a failed patch');
                    else $payload=['storyboard'=>$missing ? [self::shot(1),$bad] : [self::shot(1),$bad,self::shot(3)]];
                    return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]];
                },static function($event,$payload)use(&$checkpoints){if($event==='script_checkpoint')$checkpoints[]=$payload;});
            self::fail('Invalid patch must not be accepted');
        } catch(\RuntimeException $error){self::assertSame(422,$error->getCode());}
        self::assertNotEmpty($checkpoints);
        self::assertSame(['s1_1'],array_column(end($checkpoints)['storyboard'],'shot_id'));
        self::assertCount(4,$calls);
    }
    public static function failedFieldRepairModes(): array { return [[false,false],[false,true],[true,false],[true,true]]; }

    public function testMixedPartialInBatchedSceneRetainsNeighborScene(): void
    {
        $plan=self::skeleton(true);$plan['scene_beats'][0]['duration_seconds']=20;$plan['scene_beats'][0]['shot_durations']=[10,10];
        $plan['locations'][]=['id'=>'l2','name'=>'走廊'];
        $plan['scene_beats'][]=['scene_ref_id'=>'l2','goal'=>'走过走廊','entry'=>'走廊站立','exit'=>'走廊尽头',
            'key_events'=>['走向走廊尽头'],'duration_seconds'=>10,'shot_durations'=>[10]];
        $second=array_replace(self::shot(3),['shot_id'=>'s2_1','scene_ref_id'=>'l2','scene_name'=>'走廊','visual_description'=>'甲走过走廊']);
        $bad=self::shot(1);$bad['visual_description']='';$calls=[];
        $request=['_input_contract_version'=>22,'_scene_batch_policy'=>Batch::POLICY,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];
        $result=Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事','_stage_content'=>'故事'],
            static function($key)use(&$calls,$plan,$second,$bad){
                $calls[]=$key;
                if($key==='v3_timed_skeleton_0')$payload=$plan;
                elseif($key==='v3_timed_batch_1_2')$payload=['storyboard'=>[$bad,$second]];
                elseif($key==='v3_timed_scene_1_1_partial_fields_v1')$payload=['patches'=>[['shot_id'=>'s1_1','fields'=>['visual_description'=>'已修复第一镜头']]]];
                elseif($key==='v3_timed_scene_1_1_missing_2')$payload=['storyboard'=>[self::shot(2)]];
                else throw new \RuntimeException('Unexpected unit '.$key);
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]];
            });
        self::assertSame(['s1_1','s1_2','s2_1'],array_column($result['payload']['storyboard'],'shot_id'));
        self::assertSame('已修复第一镜头',$result['payload']['storyboard'][0]['visual_description']);
        self::assertSame($second,$result['payload']['storyboard'][2]);
        self::assertSame(['v3_timed_skeleton_0','v3_timed_batch_1_2','v3_timed_scene_1_1_partial_fields_v1','v3_timed_scene_1_1_missing_2'],$calls);
    }

    public function testFieldRepairCannotModifyValidDialogueOrGenerateUnknownTargets(): void
    {
        $request=['_input_contract_version'=>21,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];$calls=[];
        try {
            Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事'],
                static function($key)use(&$calls){
                    $calls[]=$key;
                    if(str_contains($key,'skeleton'))$payload=self::skeleton(true);
                    elseif(str_contains($key,'_fields_v1'))$payload=['patches'=>[['shot_id'=>'s1_2','fields'=>['visual_description'=>'修复','dialogue'=>'篡改']]]];
                    else {$shots=[self::shot(1),self::shot(2),self::shot(3)];$shots[1]['visual_description']='';$payload=['storyboard'=>$shots];}
                    return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]];
                });
            self::fail('Out-of-scope field must be rejected');
        } catch(\RuntimeException $error){self::assertSame('分镜字段修复范围无效',$error->getMessage());}
        self::assertCount(4,$calls);self::assertStringEndsWith('_fields_v1_repair',$calls[3]);
    }

    public function testMalformedFieldPatchRetriesOnlyThePatchAndPreservesOriginalShots(): void
    {
        $request=['_input_contract_version'=>21,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];$calls=[];
        $result=Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事'],static function($key)use(&$calls){
            $calls[]=$key;
            if(str_contains($key,'skeleton'))$payload=self::skeleton(true);
            elseif(str_ends_with($key,'_fields_v1'))$payload=['patches'=>[]];
            elseif(str_ends_with($key,'_fields_v1_repair'))$payload=['patches'=>[['shot_id'=>'s1_2','fields'=>['visual_description'=>'已补全']]]];
            else {$shots=[self::shot(1),self::shot(2),self::shot(3)];$shots[1]['visual_description']='';$payload=['storyboard'=>$shots];}
            return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]];
        });
        self::assertSame('已补全',$result['payload']['storyboard'][1]['visual_description']);
        self::assertSame(self::shot(1),$result['payload']['storyboard'][0]);self::assertSame(self::shot(3),$result['payload']['storyboard'][2]);
        self::assertSame(['v3_timed_skeleton_0','v3_timed_scene_1_1','v3_timed_scene_1_1_fields_v1','v3_timed_scene_1_1_fields_v1_repair'],$calls);
    }

    public function testTimedMissingShotFieldRepairKeepsItsValidContent(): void
    {
        $request=['_input_contract_version'=>22,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];$calls=[];
        $bad=self::shot(2);$bad['visual_description']='';$bad['dialogue']='甲：已有台词。';
        $result=Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事'],
            static function($key)use(&$calls,$bad){
                $calls[]=$key;
                if(str_contains($key,'skeleton'))$payload=self::skeleton(true);
                elseif(str_ends_with($key,'_missing_2_fields_v1'))$payload=['patches'=>[['shot_id'=>'s1_2','fields'=>['visual_description'=>'补全第二镜头']]]];
                elseif(str_ends_with($key,'_missing_2'))$payload=['storyboard'=>[$bad]];
                else $payload=['storyboard'=>[self::shot(1),self::shot(3)]];
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]];
            });
        $bad['visual_description']='补全第二镜头';
        self::assertSame([self::shot(1),$bad,self::shot(3)],$result['payload']['storyboard']);
        self::assertSame(['v3_timed_skeleton_0','v3_timed_scene_1_1','v3_timed_scene_1_1_missing_2','v3_timed_scene_1_1_missing_2_fields_v1'],$calls);
    }

    public function testTruncatedJsonKeepsCompleteShotsAndOnlyRequestsTheMissingOnes(): void
    {
        $request=['_input_contract_version'=>23,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];$calls=[];
        $result=Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事'],
            static function($key)use(&$calls){
                $calls[]=$key;
                if(str_contains($key,'skeleton'))$payload=self::skeleton(true);
                elseif(str_ends_with($key,'_missing_2'))$payload=['storyboard'=>[self::shot(2)]];
                elseif(str_ends_with($key,'_missing_3'))$payload=['storyboard'=>[self::shot(3)]];
                elseif($key==='v3_timed_scene_1_1') return ['result'=>['content'=>'{"storyboard":['.json_encode(self::shot(1),JSON_UNESCAPED_UNICODE).',{"shot_id":"s1_2","visual_description":"未完成', 'finish_reason'=>'length']];
                else throw new \RuntimeException('Unexpected unit '.$key);
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]];
            });
        self::assertSame([self::shot(1),self::shot(2),self::shot(3)],$result['payload']['storyboard']);
        self::assertSame(['v3_timed_skeleton_0','v3_timed_scene_1_1','v3_timed_scene_1_1_missing_2','v3_timed_scene_1_1_missing_3'],$calls);
    }

    public function testSceneLabelAndInteriorConflictRepairsOnlyThoseFields(): void
    {
        $plan=self::skeleton(true);$plan['locations'][0]['interior_exterior']='interior';
        $request=['_input_contract_version'=>23,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];$calls=[];
        $bad=self::shot(2);$bad['scene_name']='走廊';$bad['interior_exterior']='exterior';$bad['dialogue']='甲：原台词。';
        $result=Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事'],
            static function($key)use(&$calls,$plan,$bad){
                $calls[]=$key;
                if(str_contains($key,'skeleton'))$payload=$plan;
                elseif(str_ends_with($key,'_fields_v1'))$payload=['patches'=>[['shot_id'=>'s1_2','fields'=>['scene_name'=>'房间','interior_exterior'=>'interior']]]];
                else $payload=['storyboard'=>[self::shot(1),$bad,self::shot(3)]];
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]];
            });
        $bad['scene_name']='房间';$bad['interior_exterior']='interior';
        self::assertSame([self::shot(1),$bad,self::shot(3)],$result['payload']['storyboard']);
        self::assertCount(3,$calls);self::assertStringEndsWith('_fields_v1',$calls[2]);
    }

    public function testTruncatedBatchedScenesPreserveEveryCompleteOrderedShot(): void
    {
        $plan=self::skeleton(true);$plan['scene_beats'][0]['duration_seconds']=20;$plan['scene_beats'][0]['shot_durations']=[10,10];
        $plan['locations'][]=['id'=>'l2','name'=>'走廊'];
        $plan['scene_beats'][]=['scene_ref_id'=>'l2','goal'=>'走过走廊','entry'=>'走廊站立','exit'=>'走廊尽头',
            'key_events'=>['走向走廊尽头'],'duration_seconds'=>10,'shot_durations'=>[10]];
        $second=array_replace(self::shot(3),['shot_id'=>'s2_1','scene_ref_id'=>'l2','scene_name'=>'走廊','visual_description'=>'甲走过走廊']);
        $request=['_input_contract_version'=>23,'_scene_batch_policy'=>Batch::POLICY,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];$calls=[];
        $result=Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事','_stage_content'=>'故事'],
            static function($key)use(&$calls,$plan,$second){
                $calls[]=$key;
                if($key==='v3_timed_skeleton_0')$payload=$plan;
                elseif($key==='v3_timed_batch_1_2')return ['result'=>['content'=>'{"storyboard":['.json_encode(self::shot(1),JSON_UNESCAPED_UNICODE).','.json_encode($second,JSON_UNESCAPED_UNICODE), 'finish_reason'=>'length']];
                elseif($key==='v3_timed_scene_1_1_missing_2')$payload=['storyboard'=>[self::shot(2)]];
                else throw new \RuntimeException('Unexpected unit '.$key);
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]];
            });
        self::assertSame([self::shot(1),self::shot(2),$second],$result['payload']['storyboard']);
        self::assertSame(['v3_timed_skeleton_0','v3_timed_batch_1_2','v3_timed_scene_1_1_missing_2'],$calls);
    }

    /** @dataProvider fieldRepairModes */
    public function testSkeletonFieldRepairKeepsExistingScenesAndNarrative(bool $timed): void
    {
        $plan=self::skeleton($timed);$plan['scene_beats'][0]['goal']='';$plan['scene_beats'][0]['scene_name']='错误地点';
        $request=['_input_contract_version'=>23];if($timed)$request['episode_duration_policy']=Duration::snapshot([],0,0,[]);
        $calls=[];
        $result=Script::generate($request,['max_tokens'=>$timed?8192:4096],['system_prompt'=>'创作','content'=>'故事'],
            static function($key)use(&$calls,$plan){
                $calls[]=$key;
                if(str_ends_with($key,'skeleton_fields_v1'))$payload=['patches'=>[['scene_index'=>0,'fields'=>['goal'=>'寻找钥匙','scene_name'=>'房间']]]];
                elseif(str_contains($key,'skeleton'))$payload=$plan;
                else $payload=['storyboard'=>[self::shot(1),self::shot(2),self::shot(3)]];
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]];
            });
        self::assertSame('寻找钥匙',$result['payload']['scene_beats'][0]['goal']);
        self::assertSame('房间',$result['payload']['scene_beats'][0]['scene_name']);
        self::assertSame($plan['scene_beats'][0]['entry'],$result['payload']['scene_beats'][0]['entry']);
        self::assertSame($plan['story_outline'],$result['payload']['story_outline']);
        self::assertCount(3,$calls);self::assertStringEndsWith('skeleton_fields_v1',$calls[1]);
    }

    public function testSkeletonPatchCannotRewriteGoodFields(): void
    {
        $plan=self::skeleton(true);$plan['scene_beats'][0]['goal']='';$targets=\app\common\service\app\aigc_short_drama\ShortDramaSkeletonFieldRepair::targets($plan,true);
        $this->expectExceptionCode(422);
        \app\common\service\app\aigc_short_drama\ShortDramaSkeletonFieldRepair::apply($plan,$targets,
            ['patches'=>[['scene_index'=>0,'fields'=>['goal'=>'补全','entry'=>'篡改']]]]);
    }

    public function testSkeletonRecoveryDoesNotGuessAPlaceOrHideAnExplicitConflict(): void
    {
        $plan=self::skeleton(true);$plan['locations'][]=['id'=>'l2','name'=>'走廊'];
        $plan['scene_beats'][0]['scene_ref_id']='unknown';
        self::assertSame([],\app\common\service\app\aigc_short_drama\ShortDramaSkeletonFieldRepair::targets($plan,true));
        $plan['scene_beats'][0]['scene_ref_id']='l1';$plan['scene_beats'][0]['scene_name']='走廊';
        $plan['scene_beats'][0]['entry']='走廊内，甲等候';
        self::assertSame([],\app\common\service\app\aigc_short_drama\ShortDramaSkeletonFieldRepair::targets($plan,true));
    }

    public function testSceneFieldIsCapturedBeforeExtraLocationNormalizationFails(): void
    {
        $plan=self::skeleton(true);$request=['_input_contract_version'=>23,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];$calls=[];
        $bad=self::shot(2);$bad['scene_name']='走廊';$bad['dialogue']='甲：原台词。';
        $result=Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事'],
            static function($key)use(&$calls,$plan,$bad){
                $calls[]=$key;
                if(str_contains($key,'skeleton'))$payload=$plan;
                elseif(str_ends_with($key,'_fields_v1'))$payload=['patches'=>[['shot_id'=>'s1_2','fields'=>['scene_name'=>'房间']]]];
                else $payload=['locations'=>$plan['locations'],'storyboard'=>[self::shot(1),$bad,self::shot(3)]];
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]];
            });
        $bad['scene_name']='房间';
        self::assertSame([self::shot(1),$bad,self::shot(3)],$result['payload']['storyboard']);
        self::assertStringEndsWith('_fields_v1',end($calls));
    }

    public function testVersion20KeepsOriginalWholePartRepair(): void
    {
        $request=['_input_contract_version'=>20,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];$calls=[];
        $result=Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>'故事'],static function($key)use(&$calls){
            $calls[]=$key;
            if(str_contains($key,'skeleton'))$payload=self::skeleton(true);
            else {$shots=[self::shot(1),self::shot(2),self::shot(3)];if(!str_ends_with($key,'_repair'))$shots[1]['visual_description']='';$payload=['storyboard'=>$shots];}
            return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE)]];
        });
        self::assertCount(3,$result['payload']['storyboard']);
        self::assertSame(['v3_timed_skeleton_0','v3_timed_scene_1_1','v3_timed_scene_1_1_repair'],$calls);
    }

    public function testBatchedSceneRepairsOnlyInvalidFieldAndKeepsOtherScene(): void
    {
        $plan=self::skeleton(true);$plan['scene_beats'][0]['duration_seconds']=20;$plan['scene_beats'][0]['shot_durations']=[10,10];
        $plan['locations'][]=['id'=>'l2','name'=>'走廊'];
        $plan['scene_beats'][]=['scene_ref_id'=>'l2','goal'=>'走过走廊','entry'=>'走廊站立','exit'=>'走廊尽头',
            'key_events'=>['走向走廊尽头'],'duration_seconds'=>10,'shot_durations'=>[10]];
        $second=array_replace(self::shot(3),['shot_id'=>'s2_1','scene_ref_id'=>'l2','scene_name'=>'走廊','visual_description'=>'甲走过走廊']);
        $request=['_input_contract_version'=>21,'_scene_batch_policy'=>Batch::POLICY,'episode_duration_policy'=>Duration::snapshot([],0,0,[])];
        $calls=[];$content='故事';
        $result=Script::generate($request,['max_tokens'=>8192],['system_prompt'=>'创作','content'=>$content,'_stage_content'=>$content],
            static function($key,$input)use(&$calls,$plan,$second){
                $calls[]=$key;
                if($key==='v3_timed_skeleton_0')$payload=$plan;
                elseif($key==='v3_timed_batch_1_2'){$bad=self::shot(2);$bad['visual_description']='';$payload=['storyboard'=>[self::shot(1),$bad,$second]];}
                elseif($key==='v3_timed_scene_1_1_fields_v1')$payload=['patches'=>[['shot_id'=>'s1_2','fields'=>['visual_description'=>'补全第二镜头']]]];
                else throw new \RuntimeException('Unexpected unit '.$key);
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
            });
        self::assertSame(['s1_1','s1_2','s2_1'],array_column($result['payload']['storyboard'],'shot_id'));
        self::assertSame('补全第二镜头',$result['payload']['storyboard'][1]['visual_description']);
        self::assertSame('甲走过走廊',$result['payload']['storyboard'][2]['visual_description']);
        self::assertSame(['v3_timed_skeleton_0','v3_timed_batch_1_2','v3_timed_scene_1_1_fields_v1'],$calls);
    }
}
