<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\CanvasProductionProjection as Projection;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\ShotCompiler;
use app\common\service\app\aigc_short_drama\canvas_agent\GraphService;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextCanvasProjectionTest extends TestCase
{
    private function candidate(): array
    {
        $document=ContentDocument::normalize(['title'=>'制作身份','entities'=>[
            ['ref'=>'hero','kind'=>'character','name'=>'甲','description'=>'黑衣'],
            ['ref'=>'letter','kind'=>'prop','name'=>'旧信','description'=>'封口破损'],
            ['ref'=>'room','kind'=>'location','name'=>'书房','description'=>'旧书桌']],
            'scenes'=>[['location'=>'room','units'=>[['type'=>'action','entities'=>['hero','letter'],'text'=>'甲握着旧信。'],
                ['type'=>'dialogue','speaker'=>'hero','text'=>str_repeat('完整台词。',100).'尾句😀']]]]],
            ['revision_id'=>'doc1','episode_id'=>'ep1','entity_scope'=>'canvas-fixture']);
        $plan=ShotCompiler::compile($document,['shots'=>[['visual_description'=>'甲握着破损旧信','composition'=>'中景','refs'=>[['unit'=>'u1'],['unit'=>'u2']]]]],'shots1');
        return ['document'=>$document,'shot_plan'=>$plan,'coverage_report'=>['status'=>'ready']];
    }
    public function testCanonicalCanvasKeepsEntityAndShotIdentitiesIncludingProps(): void
    {
        $candidate=$this->candidate(); $assets=Projection::proposals($candidate,12,'owned','assets',1,'水彩');
        $boards=Projection::proposals($candidate,12,'owned','storyboard',1,'水彩');
        self::assertCount(4,$assets);self::assertCount(2,$boards);
        foreach (array_merge($assets,$boards,Projection::proposals($candidate,12,'owned','art',1,'水彩')) as $proposal) {
            self::assertSame($candidate,Projection::assertBinding(1,2,$proposal['canonical_binding'],$candidate));
            self::assertSame(hash('sha256',$proposal['prompt']),$proposal['canonical_binding']['prompt_hash']);
        }
        self::assertSame($candidate['shot_plan']['shots'][0]['shot_id'],$boards[1]['canonical_binding']['target_id']);
        self::assertSame($candidate['document']['entities'][1]['entity_id'],$assets[2]['canonical_binding']['target_id']);
        self::assertStringContainsString('生成道具设定图',$assets[2]['prompt']);
        $validate=new \ReflectionMethod(GraphService::class,'validateAgentProposals');$validate->setAccessible(true);
        $validate->invoke(null,$assets,'assets',true);$validate->invoke(null,$boards,'storyboard',true);
        self::assertStringEndsWith('尾句😀',$candidate['shot_plan']['shots'][0]['dialogue_preview']);
    }
    /** @dataProvider invalidBindings */
    public function testStaleOrForgedBindingsAreRejected(string $field): void
    {
        $candidate=$this->candidate();$binding=Projection::proposals($candidate,12,'owned','assets',1,'水彩')[0]['canonical_binding'];
        $binding[$field]='changed';$body=$binding;unset($body['hash']);$binding['hash']=ContentDocument::hash($body);
        $this->expectException(\RuntimeException::class);Projection::assertBinding(1,2,$binding,$candidate);
    }
    public static function invalidBindings(): array {return [['document_revision_id'],['document_hash'],['shot_hash'],['state_hash'],['target_id'],['prompt_hash']];}
    public function testReadOnlyUnboundLegacyNodesRemainCompatible(): void
    {
        self::assertNull(Projection::assertNode(1,2,['id'=>1,'type'=>'image','metadata'=>['prompt'=>'旧任务']]));
        Projection::assertInput(1,2,['nodes_json'=>'[]','edges_json'=>'[]'],['id'=>1,'metadata'=>[]],['prompt'=>'旧任务']);
    }
    public function testDeferredAudioAndVideoCannotUseGuessedDurationsToOpenPaidWork(): void
    {
        $this->expectExceptionCode(409);Projection::proposals($this->candidate(),12,'owned','video_nodes',1,'');
    }
}
