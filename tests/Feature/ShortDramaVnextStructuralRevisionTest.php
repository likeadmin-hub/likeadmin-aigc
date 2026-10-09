<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\ManualContentRevision;
use app\common\service\app\aigc_short_drama\vnext\ManualRevisionHistory;
use app\common\service\app\aigc_short_drama\vnext\ShotCompiler;
use app\common\service\app\aigc_short_drama\vnext\StructuralRevision;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextStructuralRevisionTest extends TestCase
{
    private function fixture(): array
    {
        $doc=ContentDocument::normalize(['title'=>'人物纠正','entities'=>[
            ['ref'=>'c1','kind'=>'character','name'=>'甲'],['ref'=>'c2','kind'=>'character','name'=>'乙'],
            ['ref'=>'l1','kind'=>'location','name'=>'书房'],['ref'=>'l2','kind'=>'location','name'=>'院子']],
            'scenes'=>[['location'=>'l1','units'=>[['type'=>'dialogue','speaker'=>'c1','text'=>"完整台词\n".str_repeat('保留正文。',100).'尾句😀']]]]],
            ['revision_id'=>'r1','episode_id'=>'e1','entity_scope'=>'fixture']);
        $targets=StructuralRevision::targets($doc);$entity=$doc['entities'][0];$scene=$doc['scenes'][0];$unit=$scene['units'][0];
        $edits=['entities'=>[['entity_id'=>$entity['entity_id'],'before_hash'=>$targets['entities'][0]['before_hash'],'canonical_name'=>'甲的新名','description'=>"新服装\n完整说明😀"]],
            'scenes'=>[['scene_id'=>$scene['scene_id'],'before_hash'=>$targets['scenes'][0]['before_hash'],'location_id'=>$doc['entities'][3]['entity_id'],'time_context'=>'夜']],
            'bindings'=>[['unit_id'=>$unit['unit_id'],'before_hash'=>$targets['bindings'][0]['before_hash'],'speaker_id'=>$doc['entities'][1]['entity_id'],'entity_ids'=>[$doc['entities'][1]['entity_id']]]]];
        $origin=['tenant_id'=>10,'user_id'=>20,'created_at'=>1791500000,'parent_task_id'=>'old','task_id'=>'new'];
        return [$doc,$edits,$origin];
    }

    public function testCorrectSpeakerLocationAndAppearancePreserveEveryWordAndInvalidateOldProjection(): void
    {
        [$doc,$edits,$origin]=$this->fixture();$input=['context'=>['sources'=>[]]];
        $oldPlan=ShotCompiler::compile($doc,['shots'=>[['visual_description'=>'完整画面','refs'=>[['unit'=>'u1']]]]],'s1');
        $next=ApplicationWorkflow::revisionInput($input,['document'=>$doc],[],'r1','new',$origin,$edits);
        $edited=$next['context']['canonical_input'];$before=$doc['scenes'][0]['units'][0];$after=$edited['scenes'][0]['units'][0];
        self::assertSame($before['text'],$after['text']);self::assertSame($before['unit_id'],$after['unit_id']);self::assertSame($before['source_links'],$after['source_links']);
        self::assertSame($doc['entities'][1]['entity_id'],$after['speaker_id']);self::assertSame($doc['entities'][3]['entity_id'],$edited['scenes'][0]['location_id']);
        self::assertSame('甲的新名',$edited['entities'][0]['canonical_name']);self::assertContains('甲',$edited['entities'][0]['aliases']);
        ManualContentRevision::assertHistory($next['context']['manual_edit_history']);
        $view=ManualRevisionHistory::page($next,10,20,1);self::assertCount(3,$view['records'][0]['changes']);
        self::assertSame('主体：甲',$view['records'][0]['changes'][0]['target_label']);
        self::assertSame("新服装\n完整说明😀",json_decode($view['records'][0]['changes'][0]['after_text'],true)['description']);
        $newPlan=ShotCompiler::compile($edited,['shots'=>[['visual_description'=>'完整画面','refs'=>[['unit'=>'u1']]]]],'s2');
        self::assertSame($after['speaker_id'],$newPlan['audio_segments'][0]['speaker_id']);self::assertStringEndsWith('尾句😀',$newPlan['shots'][0]['dialogue_preview']);
        $this->expectExceptionCode(409);ShotCompiler::assertCurrent($edited,$oldPlan);
    }

    public function testNarrationAndSuccessiveTextEditsShareOneVerifiableHistoryWithoutRewriting(): void
    {
        [$doc,$edits,$origin]=$this->fixture();$edits=['bindings'=>[array_replace($edits['bindings'][0],['speaker_id'=>null,'speech_type'=>'narration','entity_ids'=>[]])]];
        $next=ApplicationWorkflow::revisionInput(['context'=>['sources'=>[]]],['document'=>$doc],[],'r1','new',$origin,$edits);
        $edited=$next['context']['canonical_input'];$unit=$edited['scenes'][0]['units'][0];
        self::assertNull($unit['speaker_id']);self::assertSame('narration',$unit['speech_type']);
        $origin['parent_task_id']='new';$origin['task_id']='third';
        $text=ApplicationWorkflow::revisionInput($next,['document'=>$edited],[['unit_id'=>$unit['unit_id'],'before_hash'=>$unit['text_hash'],'text'=>$unit['text'].'补充。']],$edited['revision_id'],'third',$origin);
        ManualContentRevision::assertHistory($text['context']['manual_edit_history']);self::assertCount(2,$text['context']['manual_edit_history']['events']);
        self::assertSame($unit['text'].'补充。',ManualRevisionHistory::page($text,10,20,1)['records'][0]['changes'][0]['after_text']);
        self::assertNotEmpty(ManualRevisionHistory::page($text,10,20,2)['records'][0]['changes'][0]['target_label']);
    }

    public function testSceneSplitsAndMergesPreserveAllUnitIdsWordsAndOriginalAttachmentPositions(): void
    {
        $context=ShortDramaVnextAttachmentTest::context();$doc=\app\common\service\app\aigc_short_drama\vnext\AttachmentScreenplay::document($context);
        $units=$doc['scenes'][0]['units'];$scene=$doc['scenes'][0];$origin=['tenant_id'=>10,'user_id'=>20,'created_at'=>1791500000,'parent_task_id'=>'old','task_id'=>'split'];
        $group=static fn(array $ids): array=>['location_id'=>$scene['location_id'],'time_context'=>$scene['time_context'],'unit_ids'=>$ids];
        $structure=['scene_layout'=>['before_hash'=>StructuralRevision::targets($doc)['scene_layout']['before_hash'],
            'scenes'=>[$group([$units[0]['unit_id']]),$group([$units[1]['unit_id'],$units[2]['unit_id']])]]];
        $input=ApplicationWorkflow::revisionInput(['context'=>$context],['document'=>$doc],[],$doc['revision_id'],'split',$origin,$structure);
        $split=$input['context']['canonical_input'];self::assertCount(2,$split['scenes']);
        foreach(array_values(ContentDocument::units($split)) as $index=>$unit) {unset($unit['location_id'],$unit['scene_id']);self::assertSame($units[$index],$unit);}
        self::assertSame('我记得你😀。',\app\common\service\app\aigc_short_drama\vnext\AttachmentSourceView::page($input,['document'=>$split],2)['records'][0]['original_text']);
        $origin['parent_task_id']='split';$origin['task_id']='merged';$structure['scene_layout']=['before_hash'=>StructuralRevision::targets($split)['scene_layout']['before_hash'],'scenes'=>[$group(array_column($units,'unit_id'))]];
        $mergedInput=ApplicationWorkflow::revisionInput($input,['document'=>$split],[],$split['revision_id'],'merged',$origin,$structure);
        self::assertCount(1,$mergedInput['context']['canonical_input']['scenes']);self::assertSame($units,$mergedInput['context']['canonical_input']['scenes'][0]['units']);
        self::assertCount(2,$mergedInput['context']['manual_edit_history']['events']);
        self::assertSame('场次拆分与合并',ManualRevisionHistory::page($mergedInput,10,20,1)['records'][0]['changes'][0]['target_label']);
    }

    /** @dataProvider invalidLayouts */
    public function testLayoutCannotDeleteDuplicateReorderOrInventContent(string $kind): void
    {
        $context=ShortDramaVnextAttachmentTest::context();$doc=\app\common\service\app\aigc_short_drama\vnext\AttachmentScreenplay::document($context);
        $scene=$doc['scenes'][0];$ids=array_column($scene['units'],'unit_id');
        if($kind==='delete') array_pop($ids);
        if($kind==='duplicate') $ids[]=$ids[0];
        if($kind==='reorder') $ids=array_reverse($ids);
        if($kind==='foreign') $ids[0]='foreign';
        $layout=['before_hash'=>StructuralRevision::targets($doc)['scene_layout']['before_hash'], 'scenes'=>[['location_id'=>$scene['location_id'],'time_context'=>'','unit_ids'=>$ids]]];
        if($kind==='stale') $layout['before_hash']='wrong';
        $origin=['tenant_id'=>10,'user_id'=>20,'created_at'=>1791500000,'parent_task_id'=>'old','task_id'=>'new'];
        $this->expectExceptionCode(409);ApplicationWorkflow::revisionInput(['context'=>$context],['document'=>$doc],[],$doc['revision_id'],'new',$origin,['scene_layout'=>$layout]);
    }
    public static function invalidLayouts(): array {return [['delete'],['duplicate'],['reorder'],['foreign'],['stale']];}

    /** @dataProvider invalidEdits */
    public function testUnsafeOrStaleStructuralEditsAreRejected(string $case,int $code): void
    {
        [$doc,$edits,$origin]=$this->fixture();
        if ($case==='stale') $edits['bindings'][0]['before_hash']='wrong';
        if ($case==='duplicate') $edits['bindings'][]=$edits['bindings'][0];
        if ($case==='foreign') $edits['entities'][0]['entity_id']='foreign';
        if ($case==='kind') $edits['entities'][0]['kind']='prop';
        if ($case==='identity') $edits['entities'][0]['identity_facts']=['gender'=>'wrong'];
        if ($case==='name') $edits['entities'][0]['canonical_name']='乙';
        if ($case==='speaker') $edits['bindings'][0]['speaker_id']=$doc['entities'][2]['entity_id'];
        if ($case==='narrator') $edits['bindings'][0]['speech_type']='narration';
        if ($case==='location') $edits['scenes'][0]['location_id']=$doc['entities'][0]['entity_id'];
        if ($case==='text') $edits['bindings'][0]['text']='删掉原文';
        if ($case==='noop') $edits=['entities'=>[['entity_id'=>$doc['entities'][0]['entity_id'],'before_hash'=>StructuralRevision::targets($doc)['entities'][0]['before_hash'],'canonical_name'=>'甲']]];
        if ($case==='origin') $origin['user_id']=0;
        $this->expectException(\RuntimeException::class);$this->expectExceptionCode($code);
        ApplicationWorkflow::revisionInput(['context'=>[]],['document'=>$doc],[],'r1','new',$origin,$edits);
    }
    public static function invalidEdits(): array { return [['stale',409],['duplicate',409],['foreign',409],['kind',422],['identity',422],['name',422],['speaker',422],['narrator',422],['location',422],['text',422],['noop',422],['origin',409]]; }
}
