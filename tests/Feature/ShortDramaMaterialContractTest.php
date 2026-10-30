<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use app\common\service\app\aigc_short_drama\ShortDramaMaterialContract as Contract;

final class ShortDramaMaterialContractTest extends TestCase
{
    public function testMissingMaterialsUseOnlyConfirmedAuthorityAndKeepSceneContent(): void
    {
        $plan=['title'=>'当前','story_outline'=>'当前正文','scene_beats'=>[['goal'=>'必须保留台词']]];
        $authority=['subjects'=>[['id'=>'p','name'=>'角色']], 'locations'=>[['id'=>'l','name'=>'屋内']]];
        $out=Contract::prepare($plan,['series_context'=>['outline'=>$authority]]);
        self::assertSame($authority['subjects'],$out['subjects']); self::assertSame($authority['locations'],$out['locations']);
        self::assertSame($plan['scene_beats'],$out['scene_beats']); self::assertSame('当前正文',$out['story_outline']);
    }
    public function testDeclaredAliasesAreLosslessAndCanonicalMaterialWins(): void
    {
        $actors=[['id'=>'p','name'=>'本人']];
        self::assertSame($actors,Contract::prepare(['characters'=>$actors],[])['subjects']);
        self::assertSame($actors,Contract::prepare(['subjects'=>$actors],['confirmed_story_snapshot'=>['subjects'=>[['id'=>'old','name'=>'旧人']]]])['subjects']);
    }
    public function testUnknownMissingMaterialIsNotInvented(): void
    {
        self::assertArrayNotHasKey('subjects',Contract::prepare(['title'=>'无来源'],[]));
    }
    /** @dataProvider invalid */
    public function testInvalidTypesProducePreciseRecoverableFailure(array $plan): void
    {
        $this->expectException(\RuntimeException::class);$this->expectExceptionCode(422);
        Contract::prepare($plan,[]);
    }
    public static function invalid():array
    {
        return [[['subjects'=>'角色']], [['subjects'=>[['id'=>['bad'],'name'=>'角色']]]], [['locations'=>[['id'=>'l','description'=>['bad']]]]], [['story_outline'=>['bad']]], [['characters'=>[['id'=>'p']],'roles'=>[['id'=>'q']]]]];
    }
    public function testFieldPatchCannotChangeAcceptedContentOrAddUnrequestedFields(): void
    {
        $base=['title'=>'锁定','story_outline'=>'已通过'];
        $out=Contract::patch($base,['subjects'],['fields'=>['subjects'=>[['id'=>'p','name'=>'角色']]]]);
        self::assertSame('锁定',$out['title']);self::assertSame('已通过',$out['story_outline']);
        $this->expectExceptionCode(422);
        Contract::patch($base,['subjects'],['fields'=>['subjects'=>[['id'=>'p']],'title'=>'越权']]);
    }
    public function testMissingFieldRepairUsesAPatchInsteadOfRewritingScenes(): void
    {
        $base=['title'=>'已锁定','story_outline'=>'明确台词','locations'=>[['id'=>'l']],'scene_beats'=>[['goal'=>'保留事件']]];
        $calls=0;
        $out=Contract::repair(static function($key,$input,$desired,$validate) use(&$calls):array {
            $calls++;self::assertStringContainsString('subjects',$input['content']);
            $reply=['fields'=>['subjects'=>[['id'=>'p','name'=>'角色']]]];$validate($reply);return $reply;
        },'repair_subjects',['system_prompt'=>'原方法','content'=>'原输入'],$base);
        self::assertSame(1,$calls);self::assertSame($base['scene_beats'],$out['scene_beats']);self::assertSame('明确台词',$out['story_outline']);
    }
    public function testSharedEngineDoesNotDecorateAValidTopFieldPatch(): void
    {
        $request=['_input_contract_version'=>23,'episode_duration_policy'=>\app\common\service\app\aigc_short_drama\ShortDramaEpisodeDuration::snapshot([],5,0,[]),
            'series_context'=>['outline'=>['subjects'=>[['id'=>'p','name'=>'甲']], 'locations'=>[['id'=>'l','name'=>'门外']]]]];
        $calls=[];
        $result=\app\common\service\app\aigc_short_drama\ShortDramaScriptGeneration::generate($request,
            ['context_window'=>32768,'max_output_tokens'=>12000],['system_prompt'=>'原规则','content'=>'甲在门外停下。'],
            static function($key,$input,$budget,$model)use(&$calls):array {
                $calls[]=$key;
                if($key==='v3_timed_skeleton_0') $payload=['scene_beats'=>[['scene_ref_id'=>'l','goal'=>'甲停下','entry'=>'甲走来','exit'=>'甲停下',
                    'key_events'=>['甲停下'],'duration_seconds'=>5,'shot_durations'=>[5]]]];
                elseif($key==='v3_timed_skeleton_top_fields_v1') $payload=['fields'=>['title'=>'门外','story_outline'=>'甲在门外停下。']];
                elseif(str_starts_with($key,'v3_timed_scene_')) $payload=['storyboard'=>[['shot_id'=>'s1_1','scene_ref_id'=>'l','subject_ref_ids'=>['p'],
                    'visual_description'=>'甲在门外停下','dialogue'=>'','recommended_duration_seconds'=>5]]];
                else throw new \RuntimeException('Unexpected generation unit '.$key);
                return ['model'=>$model,'result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
            });
        self::assertSame('门外',$result['payload']['title']);
        self::assertSame('甲在门外停下。',$result['payload']['story_outline']);
        self::assertCount(3,$calls);
        self::assertSame('甲停下',$result['payload']['scene_beats'][0]['goal']);
    }
}
