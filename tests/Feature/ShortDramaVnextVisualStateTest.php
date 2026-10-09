<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\NarrativeLedger;
use app\common\service\app\aigc_short_drama\vnext\SeriesVisualState;
use app\common\service\app\aigc_short_drama\vnext\ImageHandoff;
use app\common\service\app\aigc_short_drama\vnext\ShotCompiler;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextVisualStateTest extends TestCase
{
    private function document(): array
    {
        return ContentDocument::normalize(['title'=>'雨后','entities'=>[['ref'=>'hero','kind'=>'character','name'=>'甲','description'=>'黑衣'],['ref'=>'room','kind'=>'location','name'=>'书房','description'=>'书架']],
            'scenes'=>[['location'=>'room','units'=>[['type'=>'action','characters'=>['hero'],'text'=>'甲换上白色外套。'],['type'=>'dialogue','speaker'=>'hero','text'=>'我以为外套还是黑色。']]]]],
            ['revision_id'=>'r1','episode_id'=>'ep1','entity_scope'=>'state']);
    }
    private function fact(array $doc,string $predicate,string $kind='world'): array
    {
        return ['kind'=>$kind,'subject_id'=>$doc['entities'][0]['entity_id'],'predicate'=>$predicate,'value'=>'白色外套','certainty'=>'confirmed',
            'observer_id'=>$kind==='belief'?$doc['entities'][0]['entity_id']:null,'evidence'=>[['unit'=>$kind==='belief'?'u2':'u1','start'=>0,'end'=>$kind==='belief'?10:8,'quote'=>$kind==='belief'?'我以为外套还是黑色。':'甲换上白色外套。']]];
    }
    public function testOnlyConfirmedWorldVisualFactsAffectMatchingEntityStateAndPrompt(): void
    {
        $doc=$this->document();$plain=NarrativeLedger::evaluate($doc,[],[]);
        $visual=NarrativeLedger::evaluate($doc,[$this->fact($doc,'clothing')],[]);
        $belief=NarrativeLedger::evaluate($doc,[$this->fact($doc,'clothing','belief')],[]);
        $unrelated=NarrativeLedger::evaluate($doc,[$this->fact($doc,'owns_letter')],[]);
        $shots=ShotCompiler::compile($doc,['shots'=>[['visual_description'=>'雨后','refs'=>[['unit'=>'u1'],['unit'=>'u2']]]]],'shots');
        $candidate=static fn($ledger)=>['document'=>$doc,'shot_plan'=>$shots,'coverage_report'=>['status'=>'ready','narrative_ledger'=>$ledger]];
        $before=ImageHandoff::targets($candidate($plain),'写实');$after=ImageHandoff::targets($candidate($visual),'写实');
        self::assertNotSame($before[0]['state_hash'],$after[0]['state_hash']);self::assertSame($before[1]['state_hash'],$after[1]['state_hash']);
        self::assertSame($before,ImageHandoff::targets($candidate($belief),'写实'));self::assertSame($before,ImageHandoff::targets($candidate($unrelated),'写实'));
        self::assertStringContainsString('白色外套',ImageHandoff::compile($candidate($visual),'写实','subject_image',$doc['entities'][0]['entity_id'],'r1')['prompt']);
        self::assertSame(['clothing'],array_keys(SeriesVisualState::registry($doc,$visual)[$doc['entities'][0]['entity_id']]['visual_state']));
    }
    public function testPublishedCorrectionsCarryForwardWithoutChangingIdentityOrSystemAlias(): void
    {
        $doc=$this->document();$entity=$doc['entities'][0];$confirmed=[['ref'=>'c1','entity_id'=>$entity['entity_id'],'kind'=>'character','name'=>'甲','description'=>'黑衣','identity_facts'=>[]]];
        $entity['canonical_name']='陈默';$entity['description']='新的白衣设定';$entity['aliases']=['甲','陈默','c99'];
        $next=SeriesVisualState::inherit($confirmed,[$entity]);self::assertSame('陈默',$next[0]['name']);self::assertSame('新的白衣设定',$next[0]['description']);
        self::assertSame('c1',$next[0]['ref']);self::assertContains('甲',$next[0]['aliases']);self::assertNotContains('c99',$next[0]['aliases']);
        $entity['identity_facts']=['gender'=>'changed'];$this->expectExceptionCode(409);SeriesVisualState::inherit($confirmed,[$entity]);
    }
}
