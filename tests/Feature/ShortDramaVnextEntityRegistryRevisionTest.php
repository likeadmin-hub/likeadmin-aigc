<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\EntityRegistryRevision;
use app\common\service\app\aigc_short_drama\vnext\ManualRevisionHistory;
use app\common\service\app\aigc_short_drama\vnext\NarrativeLedger;
use app\common\service\app\aigc_short_drama\vnext\SeriesVisualState;
use app\common\service\app\aigc_short_drama\vnext\ShotCompiler;
use app\common\service\app\aigc_short_drama\vnext\StructuralRevision;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextEntityRegistryRevisionTest extends TestCase
{
    private function fixture(): array
    {
        $doc = ContentDocument::normalize(['title'=>'登记纠正','entities'=>[
            ['ref'=>'c1','kind'=>'character','name'=>'甲','aliases'=>['老甲']], ['ref'=>'c2','kind'=>'character','name'=>'乙'],
            ['ref'=>'l1','kind'=>'location','name'=>'书房'], ['ref'=>'p1','kind'=>'prop','name'=>'信']],
            'scenes'=>[['location'=>'l1','units'=>[['type'=>'action','characters'=>['c1'],'props'=>['p1'],'text'=>'甲放下信。😀'],
                ['type'=>'dialogue','speaker'=>'c1','text'=>"逐字保留\n完整尾句😀"]]]]], ['revision_id'=>'r1','episode_id'=>'e1','entity_scope'=>'fixture']);
        $targets=StructuralRevision::targets($doc);$units=$doc['scenes'][0]['units'];
        $origin=['tenant_id'=>10,'user_id'=>20,'created_at'=>1791500000,'parent_task_id'=>'old','task_id'=>'new'];
        $registry=['before_hash'=>$targets['entity_registry']['before_hash'],'additions'=>[
            ['client_ref'=>'new_entity_1','kind'=>'character','canonical_name'=>'丙','description'=>'人物完整说明😀'],
            ['client_ref'=>'new_entity_2','kind'=>'location','canonical_name'=>'庭院'],
            ['client_ref'=>'new_entity_3','kind'=>'prop','canonical_name'=>'杯子']],
            'removals'=>[['entity_id'=>$doc['entities'][0]['entity_id'],'before_hash'=>$targets['entities'][0]['before_hash']],
                ['entity_id'=>$doc['entities'][2]['entity_id'],'before_hash'=>$targets['entities'][2]['before_hash']],
                ['entity_id'=>$doc['entities'][3]['entity_id'],'before_hash'=>$targets['entities'][3]['before_hash']]]];
        $edits=['entity_registry'=>$registry,'scenes'=>[['scene_id'=>$doc['scenes'][0]['scene_id'],'before_hash'=>$targets['scenes'][0]['before_hash'],'location_id'=>'new_entity_2']],
            'bindings'=>[['unit_id'=>$units[0]['unit_id'],'before_hash'=>$targets['bindings'][0]['before_hash'],'entity_ids'=>['new_entity_1','new_entity_3']],
                ['unit_id'=>$units[1]['unit_id'],'before_hash'=>$targets['bindings'][1]['before_hash'],'speaker_id'=>'new_entity_1','entity_ids'=>['new_entity_1']]]];
        return [$doc,$edits,$origin];
    }

    public function testNewCharacterLocationAndPropCanReplaceBindingsAtomicallyWithoutDeletingTextOrHistory(): void
    {
        [$doc,$edits,$origin]=$this->fixture();$original=$doc;
        $oldPlan=ShotCompiler::compile($doc,['shots'=>[['visual_description'=>'画面','refs'=>[['unit'=>'u1'],['unit'=>'u2']]]]],'s1');
        $input=ApplicationWorkflow::revisionInput(['context'=>[]],['document'=>$doc],[],'r1','new',$origin,$edits);
        $next=$input['context']['canonical_input'];$ids=array_column($next['entities'],'entity_id','canonical_name');
        self::assertSame($original,$doc);self::assertCount(4,$next['entities']);
        self::assertStringStartsWith('ent_',$ids['丙']);self::assertNotSame('new_entity_1',$ids['丙']);self::assertArrayNotHasKey('甲',$ids);
        self::assertSame($ids['庭院'],$next['scenes'][0]['location_id']);self::assertSame($ids['丙'],$next['scenes'][0]['units'][1]['speaker_id']);
        self::assertSame([$ids['丙'],$ids['杯子']],$next['scenes'][0]['units'][0]['entity_ids']);
        foreach(['unit_id','text','text_hash','source_links'] as $field) self::assertSame(array_column($doc['scenes'][0]['units'],$field),array_column($next['scenes'][0]['units'],$field));
        self::assertStringNotContainsString('new_entity_',json_encode($next));
        $history=ManualRevisionHistory::page($input,10,20,1);self::assertCount(9,$history['records'][0]['changes']);
        self::assertSame('新增人物：丙',$history['records'][0]['changes'][0]['target_label']);
        self::assertSame([],json_decode($history['records'][0]['changes'][8]['after_text'],true));
        self::assertSame('信',json_decode($history['records'][0]['changes'][8]['before_text'],true)['canonical_name']);
        $plan=ShotCompiler::compile($next,['shots'=>[['visual_description'=>'画面','refs'=>[['unit'=>'u1'],['unit'=>'u2']]]]],'s2');
        self::assertSame($ids['丙'],$plan['audio_segments'][0]['speaker_id']);
        self::assertSame($ids['丙'],SeriesVisualState::registry($next,[])[$ids['丙']]['entity_id']);
        $this->expectExceptionCode(409);ShotCompiler::assertCurrent($next,$oldPlan);
    }

    public function testNewLocationWorksWithSplitLayoutAndRegistryRetryIsStable(): void
    {
        [$doc,$edits,$origin]=$this->fixture();$registry=$edits['entity_registry'];$registry['removals']=[];
        self::assertSame(EntityRegistryRevision::prepare($doc,$registry,'r2'),EntityRegistryRevision::prepare($doc,$registry,'r2'));
        self::assertNotSame(EntityRegistryRevision::prepare($doc,$registry,'r2')['refs'],EntityRegistryRevision::prepare($doc,$registry,'r3')['refs']);
        $edits=['entity_registry'=>$registry,'scene_layout'=>['before_hash'=>StructuralRevision::targets($doc)['scene_layout']['before_hash'],
            'scenes'=>array_map(static fn($unit)=>['location_id'=>'new_entity_2','time_context'=>'夜','unit_ids'=>[$unit['unit_id']]],$doc['scenes'][0]['units'])]];
        $input=ApplicationWorkflow::revisionInput(['context'=>[]],['document'=>$doc],[],'r1','new',$origin,$edits);$next=$input['context']['canonical_input'];
        $id=array_column($next['entities'],'entity_id','canonical_name')['庭院'];
        self::assertCount(2,$next['scenes']);self::assertSame([$id,$id],array_column($next['scenes'],'location_id'));
    }

    /** @dataProvider factScopes */
    public function testUnusedEntityCannotBeRemovedWhileCurrentOrPreviousFactOrBeliefStillReferencesIt(string $scope,string $kind): void
    {
        [$doc,$edits,$origin]=$this->fixture();$id=$doc['entities'][1]['entity_id'];
        $ledger=NarrativeLedger::evaluate($doc,[['kind'=>$kind,'subject_id'=>$kind==='world'?$id:'world','predicate'=>'location','value'=>'仍在场',
            'observer_id'=>$kind==='belief'?$id:null,'certainty'=>'confirmed','evidence'=>[['unit'=>'u1','start'=>0,'end'=>5,'quote'=>'甲放下信。']]]]);
        self::assertContains($id,StructuralRevision::targets($doc,[$ledger])['entity_registry']['protected_entity_ids']);
        $patch=['before_hash'=>$edits['entity_registry']['before_hash'],'removals'=>[['entity_id'=>$id,'before_hash'=>ContentDocument::hash($doc['entities'][1])]]];
        $candidate=['document'=>$doc];$input=['context'=>[]];
        if($scope==='current')$candidate['coverage_report']['narrative_ledger']=$ledger;else $input['context']['previous_narrative']=$ledger;
        $this->expectExceptionCode(409);ApplicationWorkflow::revisionInput($input,$candidate,[],'r1','new',$origin,['entity_registry'=>$patch]);
    }
    public static function factScopes(): array { return [['current','world'],['current','belief'],['previous','world'],['previous','belief']]; }

    /** @dataProvider invalidRegistry */
    public function testRegistryRejectsForgedIdentityAmbiguityStaleInputAndDanglingReferences(string $case,int $code): void
    {
        [$doc,$edits,$origin]=$this->fixture();
        if($case==='stale')$edits['entity_registry']['before_hash']='stale';
        if($case==='duplicate')$edits['entity_registry']['additions'][]=$edits['entity_registry']['additions'][0];
        if($case==='name')$edits['entity_registry']['additions'][0]['canonical_name']='甲';
        if($case==='alias')$edits['entity_registry']['additions'][0]['canonical_name']='老甲';
        if($case==='new_name')$edits['entity_registry']['additions'][]=['client_ref'=>'new_entity_4','kind'=>'character','canonical_name'=>'丙'];
        if($case==='foreign_id')$edits['entity_registry']['additions'][0]['entity_id']='foreign';
        if($case==='identity')$edits['entity_registry']['additions'][0]['identity_facts']=['gender'=>'男'];
        if($case==='kind')$edits['entity_registry']['additions'][0]['kind']='video';
        if($case==='speaker')$edits['bindings']=[$edits['bindings'][0]];
        if($case==='participant')$edits['bindings']=[$edits['bindings'][1]];
        if($case==='location')unset($edits['scenes']);
        if($case==='duplicate_remove')$edits['entity_registry']['removals'][]=$edits['entity_registry']['removals'][0];
        if($case==='foreign_remove')$edits['entity_registry']['removals'][0]['entity_id']='foreign';
        if($case==='remove_stale')$edits['entity_registry']['removals'][0]['before_hash']='stale';
        if($case==='foreign_ref')$edits['bindings'][1]['speaker_id']='new_entity_missing';
        if($case==='excess')$edits['entity_registry']['additions']=array_fill(0,65,$edits['entity_registry']['additions'][0]);
        if($case==='long_description')$edits['entity_registry']['additions'][0]['description']=str_repeat('长',16001);
        if($case==='modify_removed')$edits['entities']=[['entity_id'=>$doc['entities'][0]['entity_id'],'before_hash'=>ContentDocument::hash($doc['entities'][0]),'description'=>'同时修改']];
        $this->expectExceptionCode($code);ApplicationWorkflow::revisionInput(['context'=>[]],['document'=>$doc],[],'r1','new',$origin,$edits);
    }
    public static function invalidRegistry(): array { return [['stale',409],['duplicate',422],['name',422],['alias',422],['new_name',422],['foreign_id',422],['identity',422],['kind',422],
        ['speaker',409],['participant',409],['location',409],['duplicate_remove',409],['foreign_remove',409],['remove_stale',409],['foreign_ref',422],['excess',413],['long_description',422],['modify_removed',422]]; }
}
