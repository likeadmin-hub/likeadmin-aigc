<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{AigcShortDramaService as Service, ShortDramaContentAcceptance as Acceptance, ShortDramaEpisodeDuration as Duration, ShortDramaEpisodeReview as Review, ShortDramaInputContract as Contract, ShortDramaSameSceneCuts as Cuts, ShortDramaSceneBinding as Binding, ShortDramaScriptGeneration as Script, ShortDramaShotSemantics as Semantics, ShortDramaTimedScriptGeneration as Timed};
use PHPUnit\Framework\TestCase;

class ShortDramaStructuredValidationTest extends TestCase
{
    private function request(): array
    {
        return ['_input_contract_version'=>18, 'episode_duration_policy'=>Duration::snapshot([],10,0,[]),
            'same_scene_cut_policy'=>Cuts::snapshot(['capabilities'=>['supports_same_scene_cuts'=>false]])];
    }

    private function plan(string $text = '陈溪等候通知。'): array
    {
        return ['title'=>'等候', 'story_outline'=>$text, 'script_lines'=>[$text],
            'subjects'=>[['id'=>'p','name'=>'陈溪','category'=>'character'], ['id'=>'other','name'=>'甲','category'=>'character']],
            'locations'=>[['id'=>'hall','name'=>'走廊','interior_exterior'=>'interior'], ['id'=>'office','name'=>'办公室','interior_exterior'=>'interior']],
            'scene_beats'=>[['scene_ref_id'=>'hall','scene_name'=>'走廊','goal'=>$text,'entry'=>$text,'exit'=>$text,
                'key_events'=>[$text],'duration_seconds'=>10,'shot_durations'=>[10]]],
            'storyboard'=>[['shot_id'=>'s1_1','scene_ref_id'=>'hall','scene_name'=>'走廊','subject_ref_ids'=>['p'],
                'interior_exterior'=>'interior','visual_description'=>$text,
                'camera_movement'=>'0–3秒缓推至指甲；3–7秒沿栏杆平移；7–10秒停稳，全程连续镜头。',
                'dialogue'=>'','voice_role'=>'','speech_type'=>'none','recommended_duration_seconds'=>10]]];
    }

    /** Changing only prose must not change references, trigger repair or fail
     * either a standalone script or an episode using the same generator. */
    public function testGenerationIsInvariantToProseWithStableStructuredBindings(): void
    {
        $texts = ['深夜办公室，陈溪独自加班', '办公室里面的动静传到走廊，陈溪等候。',
            '陈溪不愿走进办公室，仍留在走廊。', '陈溪仿佛走进办公室，实际上还在走廊出神。',
            '陈溪在走廊读信：我已经走进办公室。', 'Office sounds drift into the corridor.',
            '资料室一般安静的走廊，陈溪停步。', '未登记的比喻地点只是修辞。'];
        foreach ([[], ['series_context'=>['current_episode'=>['episode_number'=>2]]]] as $context) {
            foreach ($texts as $text) {
                $request = $context + $this->request(); $plan = $this->plan($text); $calls = [];
                $out = Script::generate($request, ['max_tokens'=>8192], ['system_prompt'=>'保留原文', 'content'=>$text],
                    static function($key) use ($plan, &$calls) {
                        $calls[] = $key;
                        $payload = str_contains($key,'skeleton') ? array_diff_key($plan,['storyboard'=>true]) : ['storyboard'=>$plan['storyboard']];
                        return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
                    });
                self::assertSame(['v3_timed_skeleton_0','v3_timed_scene_1_1'], $calls);
                self::assertSame('hall', $out['payload']['scene_beats'][0]['scene_ref_id']);
                self::assertSame($plan['storyboard'][0], array_intersect_key($out['payload']['storyboard'][0],$plan['storyboard'][0]));
            }
        }
    }

    /** @dataProvider brokenStructures */
    public function testStructuralFailuresStillRejectWithoutRelyingOnProse(string $field, mixed $value): void
    {
        $plan = $this->plan(); $plan['storyboard'][0][$field] = $value;
        $this->expectExceptionCode(422);
        $plan = Binding::normalize($plan, $this->request());
        Timed::assertPart(['storyboard'=>$plan['storyboard']],$plan,$plan['scene_beats'][0],['s1_1'],[10],false);
    }

    public static function brokenStructures(): array
    {
        return [['scene_ref_id','missing'], ['scene_name','办公室'], ['interior_exterior','exterior'],
            ['shot_id','wrong'], ['subject_ref_ids',['missing']], ['recommended_duration_seconds',9]];
    }

    public function testSameNameLocationsRemainDisambiguatedByTheirIds(): void
    {
        $plan = $this->plan(); $plan['locations'][] = ['id'=>'hall2','name'=>'走廊'];
        $out = Binding::normalize($plan,$this->request());
        Binding::assertBeat($out['scene_beats'][0],$out,$this->request());
        self::assertSame($plan['storyboard'],$out['storyboard']);
    }

    public function testNormalizationAndSavedReviewPreserveExplicitFlagsAndWords(): void
    {
        $plan = $this->plan('办公室门外，陈溪等候。');
        $plan['locations'][0] = ['id'=>'hall','name'=>'办公室门外','interior_exterior'=>'exterior'];
        $shot = &$plan['storyboard'][0];
        $shot['scene_name'] = '办公室门外'; $shot['interior_exterior'] = 'exterior';
        $shot['dialogue'] = '陈溪：（笑声是暗号）记住这句话。'; $shot['speech_type']='character'; $shot['voice_role']='陈溪';
        unset($shot);
        self::assertSame($plan,Semantics::normalize($plan,[],$this->request()));
        $method = new \ReflectionMethod(Service::class,'normalizeGeneratedPlanResult'); $method->setAccessible(true);
        $saved = $method->invoke(null,$plan,'等候',$this->request(),'等候');
        self::assertSame(18,$saved['generation_settings']['input_contract_version']);
        self::assertTrue(Contract::structuredValidation($saved));
        foreach (['interior_exterior','visual_description','dialogue','scene_ref_id'] as $key) {
            self::assertSame($plan['storyboard'][0][$key],$saved['storyboard'][0][$key]);
        }
        self::assertSame($saved,Semantics::normalize($saved));
        self::assertTrue(Contract::structuredValidation(Contract::begin([])));
        self::assertFalse(Contract::structuredValidation(['_input_contract_version'=>17]));
    }

    public function testThirdPersonWordsInsideSpokenDialogueAreNotLocalSemanticVerdicts(): void
    {
        $plan = $this->plan();
        $plan['storyboard'][0] = array_replace($plan['storyboard'][0], ['dialogue'=>'陈溪：甲开始朗读了吗？','speech_type'=>'character','voice_role'=>'陈溪']);
        $call = static function($key,$input,$tokens,$validate) {
            $reply=['checks'=>[],'issues'=>[]]; $validate($reply); return $reply;
        };
        self::assertNotEmpty(Acceptance::inspect($plan,[],$call,['_input_contract_version'=>17])['issues']);
        self::assertSame([],Acceptance::inspect($plan,[],$call,$this->request())['issues']);
    }

    public function testRepetitionIsConfirmedSemanticallyBeforeBecomingAnIssue(): void
    {
        foreach ([true,false] as $intentional) {
            $plan=$this->plan(); $plan['storyboard'][0]=array_replace($plan['storyboard'][0],
                ['dialogue'=>'陈溪：明天我们还在这里见面。','speech_type'=>'character','voice_role'=>'陈溪']);
            $plan['storyboard'][]=array_replace($plan['storyboard'][0],['shot_id'=>'s1_2']); $checked=false;
            $report=Acceptance::inspect($plan,[],static function($key,$input,$tokens,$validate)use($intentional,&$checked){
                $data=json_decode($input['content'],true);
                if(isset($data['candidates'])){$checked=true;$reply=['verdicts'=>[['id'=>'0','intentional'=>$intentional,'reason'=>'核对了两次台词的叙事作用']]];}
                else $reply=['checks'=>[],'issues'=>[]];
                $validate($reply);return $reply;
            },$this->request());
            self::assertTrue($checked);
            self::assertCount($intentional?0:1,$report['issues']);
        }
    }

    public function testReviewCannotRejectUnchangedSpeechByCharacterCountOrChangeTime(): void
    {
        $plan=$this->plan(); $plan['storyboard'][0]=array_replace($plan['storyboard'][0],
            ['dialogue'=>'陈溪：We have been waiting for you here all morning and would like to hear what happened yesterday.',
                'voice_role'=>'陈溪','speech_type'=>'character']);
        self::assertSame($plan,Acceptance::apply($plan,['patches'=>[]],[],$this->request()));
        self::assertSame($plan,Review::apply($plan,['patches'=>[]],$this->request()));
        $this->expectExceptionCode(422);
        Review::apply($plan,['patches'=>[['shot_id'=>'s1_1','field'=>'recommended_duration_seconds','before'=>10,'after'=>20,'reason'=>'延长']]],$this->request());
    }

    public function testOldFrozenSceneAndCameraContractsStillUseTheirOriginalPaths(): void
    {
        $plan=$this->plan('陈溪走进办公室，坐下。');
        try { Binding::normalize($plan,['_input_contract_version'=>17]); self::fail('Legacy scene path changed'); }
        catch(\RuntimeException $e){self::assertSame(422,$e->getCode());}
        $this->expectExceptionMessage('自动切镜');
        Cuts::assertShot($plan['storyboard'][0],$plan,array_replace($this->request(),['_input_contract_version'=>17]));
    }
}
