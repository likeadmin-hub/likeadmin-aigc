<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\EntityIdentityRevision;
use app\common\service\app\aigc_short_drama\vnext\EntityMergeRevision;
use app\common\service\app\aigc_short_drama\vnext\GenerationPipeline;
use app\common\service\app\aigc_short_drama\vnext\ManualRevisionHistory;
use app\common\service\app\aigc_short_drama\vnext\NarrativeLedger;
use app\common\service\app\aigc_short_drama\vnext\SeriesVisualState;
use app\common\service\app\aigc_short_drama\vnext\ShotCompiler;
use app\common\service\app\aigc_short_drama\vnext\StructuralRevision;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextEntityMergeRevisionTest extends TestCase
{
    private function fixture(): array
    {
        $context=['revision_id'=>'r1','episode_id'=>'e1','entity_scope'=>'merge_fixture','entities'=>[
            ['ref'=>'c1','entity_id'=>'ent_source','kind'=>'character','name'=>'老陈','identity_facts'=>['age'=>28],'description'=>'穿风衣'],
            ['ref'=>'c2','entity_id'=>'ent_kept','kind'=>'character','name'=>'陈默','identity_facts'=>['age'=>28],'description'=>'调查员'],
            ['ref'=>'c3','entity_id'=>'ent_third','kind'=>'character','name'=>'陈先生','identity_facts'=>['age'=>28],'description'=>'黑衣'],
            ['ref'=>'l1','entity_id'=>'ent_room','kind'=>'location','name'=>'书房'],
            ['ref'=>'l2','entity_id'=>'ent_room_duplicate','kind'=>'location','name'=>'办公室'],
        ]];
        foreach($context['entities'] as &$entity) { $entity['identity_facts']=$entity['identity_facts']??[];$entity['aliases']=[];$entity['description']=$entity['description']??''; } unset($entity);
        $doc=ContentDocument::normalize(['title'=>'登记确认','scenes'=>[['location'=>'l2','units'=>[
            ['type'=>'action','characters'=>['c1','c2'],'text'=>'老陈放下信。😀'],
            ['type'=>'dialogue','speaker'=>'c1','text'=>"老陈：原文不改\n完整尾句😀"]]]]],$context);
        $origin=['tenant_id'=>10,'user_id'=>20,'created_at'=>1791500000,'parent_task_id'=>'old','task_id'=>'new'];
        return [$doc,$context,$origin];
    }
    private function patch(array $doc,string $source='ent_source',string $target='ent_kept'): array
    {
        $entities=array_column($doc['entities'],null,'entity_id');
        return ['entity_merges'=>[['source_entity_id'=>$source,'target_entity_id'=>$target,
            'source_before_hash'=>ContentDocument::hash($entities[$source]),'target_before_hash'=>ContentDocument::hash($entities[$target])]]];
    }
    private function revise(array $doc,array $context,array $origin,array $patch): array
    {
        $task='new_'.$doc['revision_id'];$origin['task_id']=$task;
        return ApplicationWorkflow::revisionInput(['context'=>$context],['document'=>$doc],[],$doc['revision_id'],$task,$origin,$patch);
    }
    public function testMergePreservesEveryWordAndSourceWhileRebindingCurrentProduction(): void
    {
        [$doc,$context,$origin]=$this->fixture();$original=$doc;
        $old=ShotCompiler::compile($doc,['shots'=>[['visual_description'=>'画面','refs'=>[['unit'=>'u1'],['unit'=>'u2']]]]],'s1');
        $input=$this->revise($doc,$context,$origin,$this->patch($doc));$next=$input['context']['canonical_input'];
        self::assertSame($original,$doc);self::assertCount(4,$next['entities']);
        foreach(['unit_id','text','text_hash','source_links'] as $field) self::assertSame(array_column($doc['scenes'][0]['units'],$field),array_column($next['scenes'][0]['units'],$field));
        self::assertSame(['ent_kept'],$next['scenes'][0]['units'][0]['entity_ids']);self::assertSame('ent_kept',$next['scenes'][0]['units'][1]['speaker_id']);
        self::assertSame($doc['entities'][0],$next['entity_merge_receipts']['ent_source']['source_entity']);
        self::assertSame($next['entity_merge_receipts'],$input['context']['entity_merge_receipts']);
        $record=ManualRevisionHistory::page($input,10,20,1)['records'][0]['changes'][0];
        self::assertSame('确认同一人物：老陈 → 陈默',$record['target_label']);self::assertSame('老陈',json_decode($record['before_text'],true)['source']['canonical_name']);
        self::assertSame('陈默',json_decode($record['after_text'],true)['target']['canonical_name']);
        $plan=ShotCompiler::compile($next,['shots'=>[['visual_description'=>'画面','refs'=>[['unit'=>'u1'],['unit'=>'u2']]]]],'s2');
        self::assertSame('ent_kept',$plan['audio_segments'][0]['speaker_id']);
        $this->expectExceptionCode(409);ShotCompiler::assertCurrent($next,$old);
    }
    public function testLocationMergeRebindsScenesAndRetiredReferencesNormalizeWithoutResurrection(): void
    {
        [$doc,$context,$origin]=$this->fixture();$patch=$this->patch($doc);
        $patch['entity_merges'][]=$this->patch($doc,'ent_room_duplicate','ent_room')['entity_merges'][0];
        $input=$this->revise($doc,$context,$origin,$patch);$next=$input['context']['canonical_input'];
        self::assertSame('ent_room',$next['scenes'][0]['location_id']);
        $inherited=SeriesVisualState::inherit($context['entities'],$next['entities'],[],['tenant_id'=>10,'user_id'=>20],$next['entity_merge_receipts']);
        self::assertSame(['ent_kept','ent_third','ent_room'],array_column($inherited,'entity_id'));
        $context['entities']=$inherited;$context['revision_id']='r3';$context['entity_merge_receipts']=$next['entity_merge_receipts'];
        $reply=['title'=>'后续集','entities'=>[['ref'=>'c1','name'=>'老陈','kind'=>'character']],
            'scenes'=>[['location'=>'l2','units'=>[['type'=>'dialogue','speaker'=>'c1','text'=>'老陈：原文与登记分开保留😀。']]]]];
        $normalized=ContentDocument::normalize($reply,$context);
        self::assertCount(3,$normalized['entities']);self::assertSame('ent_kept',$normalized['scenes'][0]['units'][0]['speaker_id']);
        self::assertSame('ent_room',$normalized['scenes'][0]['location_id']);self::assertSame($reply['scenes'][0]['units'][0]['text'],$normalized['scenes'][0]['units'][0]['text']);
        $model=GenerationPipeline::creativeContext($context);self::assertArrayNotHasKey('entity_merge_receipts',$model);
        self::assertSame('ent_kept',$model['entity_resolutions'][0]['entity_id']);
        self::assertStringNotContainsString('parent_task_id',json_encode($model['entity_resolutions']));
        $review=GenerationPipeline::reviewData($context,$normalized,[]);self::assertSame($model['entity_resolutions'],$review['entity_resolutions']);
    }
    public function testCumulativeMergeAndSourceIdentityCorrectionRetainOriginalBaseline(): void
    {
        [$doc,$context,$origin]=$this->fixture();
        $identity=EntityIdentityRevision::capture($doc['entities'][0],['age'=>30],'identity_r2',$origin);
        $doc['entities'][0]['identity_facts']=['age'=>30];$doc['entities'][1]['identity_facts']=['age'=>30];$doc['entities'][2]['identity_facts']=['age'=>30];
        $doc['identity_revision_receipts']=['ent_source'=>$identity];$doc=ContentDocument::rebind($doc,[],'identity_r2');
        $input=$this->revise($doc,[],$origin,$this->patch($doc));$next=$input['context']['canonical_input'];
        $input=$this->revise($next,$input['context'],$origin,$this->patch($next,'ent_kept','ent_third'));$last=$input['context']['canonical_input'];
        self::assertSame('ent_third',EntityMergeRevision::resolve('ent_source',$last['entity_merge_receipts']));
        self::assertSame($identity,$last['entity_merge_receipts']['ent_source']['source_identity_receipt']);
        self::assertSame(ContentDocument::hash(['age'=>28]),$last['entity_merge_receipts']['ent_source']['source_base_facts_hash']);
        self::assertCount(2,EntityMergeRevision::modelChoices($last['entity_merge_receipts'],$last['entities']));
        self::assertContains('ent_third',StructuralRevision::targets($last)['entity_registry']['protected_entity_ids']);
    }
    /** @dataProvider rejectedMerges */
    public function testUnsafeMergeIsRejected(string $case,int $code): void
    {
        [$doc,$context,$origin]=$this->fixture();
        if($case==='facts')$doc['entities'][0]['identity_facts']['age']=29;
        if($case==='library')$doc['entities'][0]['identity_facts']['library_subject_id']=42;
        if($case==='alias')$doc['entities'][2]['aliases'][]='老陈';
        $doc=ContentDocument::rebind($doc,[],'unsafe_baseline');$patch=$this->patch($doc);
        if($case==='stale')$patch['entity_merges'][0]['source_before_hash']='stale';
        if($case==='same')$patch['entity_merges'][0]['target_entity_id']='ent_source';
        if($case==='foreign')$patch['entity_merges'][0]['target_entity_id']='missing';
        if($case==='type')$patch=$this->patch($doc,'ent_source','ent_room');
        if($case==='duplicate')$patch['entity_merges'][]=$patch['entity_merges'][0];
        if($case==='extra')$patch['entity_merges'][0]['source_text']='delete';
        if($case==='empty')$patch['entity_merges']=[];
        if($case==='excess')$patch['entity_merges']=array_fill(0,65,$patch['entity_merges'][0]);
        if($case==='modify')$patch['entities']=[['entity_id'=>'ent_kept','before_hash'=>ContentDocument::hash($doc['entities'][1]),'description'=>'改说明']];
        if($case==='world')$context['previous_narrative']=['state'=>[['kind'=>'world','subject_id'=>'ent_source','observer_id'=>null]]];
        if($case==='belief')$context['previous_narrative']=['state'=>[['kind'=>'belief','subject_id'=>'ent_kept','observer_id'=>'ent_source']]];
        if(isset($context['previous_narrative'])) $context['previous_narrative']['content_hash']=NarrativeLedger::digest($context['previous_narrative']);
        $this->expectExceptionCode($code);$this->revise($doc,$context,$origin,$patch);
    }
    public static function rejectedMerges(): array {return [['facts',409],['library',409],['alias',409],['stale',409],['same',409],['foreign',409],['type',422],['duplicate',409],['extra',409],['empty',413],['excess',413],['modify',422],['world',409],['belief',409]];}
    /** @dataProvider invalidReceipts */
    public function testMergeReceiptCannotCrossScopeOrChangeIdentityBaseline(string $case): void
    {
        [$doc,$context,$origin]=$this->fixture();$input=$this->revise($doc,$context,$origin,$this->patch($doc));$next=$input['context']['canonical_input'];$receipts=$next['entity_merge_receipts'];
        if($case==='scope')$receipts['ent_source']['origin']['user_id']=21;
        if($case==='cycle')$receipts['ent_source']['target_entity_id']='ent_source';
        if($case==='hash')$receipts['ent_source']['source_hash']=str_repeat('0',64);
        if($case==='baseline')$context['entities'][0]['identity_facts']['age']=40;
        if($case==='alias')$next['entities'][0]['aliases']=['c2','陈默'];
        if($case!=='hash') {$proof=$receipts['ent_source'];unset($proof['receipt_hash']);$receipts['ent_source']['receipt_hash']=ContentDocument::hash($proof);}
        $this->expectExceptionCode(409);EntityMergeRevision::inherit($context['entities'],$next['entities'],$receipts,['tenant_id'=>10,'user_id'=>20]);
    }
    public static function invalidReceipts(): array {return [['scope'],['cycle'],['hash'],['baseline'],['alias']];}
    public function testModelCannotSupplyMergeEvidence(): void
    {
        [$doc,$context,$origin]=$this->fixture();$reply=['title'=>'原登记','entity_merge_receipts'=>['forged'=>'model'],
            'scenes'=>[['location'=>'l1','units'=>[['type'=>'dialogue','speaker'=>'c1','text'=>'保留原身份']]]]];
        $result=ContentDocument::normalize($reply,$context);self::assertArrayNotHasKey('entity_merge_receipts',$result);
        self::assertCount(5,$result['entities']);self::assertSame('ent_source',$result['scenes'][0]['units'][0]['speaker_id']);
    }
}
