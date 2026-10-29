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
}
