<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\EntityIdentityRevision;
use app\common\service\app\aigc_short_drama\vnext\GenerationPipeline;
use app\common\service\app\aigc_short_drama\vnext\ImageHandoff;
use app\common\service\app\aigc_short_drama\vnext\ManualRevisionHistory;
use app\common\service\app\aigc_short_drama\vnext\NarrativeLedger;
use app\common\service\app\aigc_short_drama\vnext\SeriesVisualState;
use app\common\service\app\aigc_short_drama\vnext\ShotCompiler;
use app\common\service\app\aigc_short_drama\vnext\StructuralRevision;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextIdentityRevisionTest extends TestCase
{
    private function fixture(): array
    {
        $entities=[['ref'=>'c1','entity_id'=>'hero','kind'=>'character','name'=>'陈默','identity_facts'=>['gender'=>'男','age'=>40,'library_subject_id'=>77]],
            ['ref'=>'l1','entity_id'=>'room','kind'=>'location','name'=>'书房','identity_facts'=>[]]];
        $reply=['title'=>'纠正设定','scenes'=>[['location'=>'l1','units'=>[['type'=>'dialogue','speaker'=>'c1','text'=>"我保留所有台词。\n完整尾句😀"]]]]];
        $context=['revision_id'=>'r1','episode_id'=>'e1','entity_scope'=>'scope','entities'=>$entities,'confirmed_facts'=>['identities'=>$entities]];
        $document=ContentDocument::normalize($reply,$context);
        $origin=['tenant_id'=>10,'user_id'=>20,'created_at'=>1791500000,'parent_task_id'=>'old','task_id'=>'new'];
        $facts=['gender'=>'女','age'=>28,'library_subject_id'=>77];
        $patch=['entities'=>[['entity_id'=>'hero','before_hash'=>StructuralRevision::targets($document)['entities'][0]['before_hash'],'identity_facts'=>$facts]]];
        return [$document,$context,$origin,$patch,$reply];
    }

    public function testHumanCorrectionChangesAuthoritativeReviewFactsAndImageStateAndIsInheritedAcrossEpisodes(): void
    {
        [$document,$context,$origin,$patch,$reply]=$this->fixture();
        $input=ApplicationWorkflow::revisionInput(['context'=>$context],['document'=>$document],[],'r1','new',$origin,$patch);$edited=$input['context']['canonical_input'];
        $facts=$patch['entities'][0]['identity_facts'];self::assertSame($facts,$edited['entities'][0]['identity_facts']);
        self::assertSame($facts,$input['context']['entities'][0]['identity_facts']);self::assertSame($input['context']['entities'],$input['context']['confirmed_facts']['identities']);
        self::assertSame($document['scenes'],$edited['scenes']);self::assertNotSame($document['content_hash'],$edited['content_hash']);
        $receipt=$edited['identity_revision_receipts']['hero'];EntityIdentityRevision::assertReceipt($receipt,$edited['entities'][0],['tenant_id'=>10,'user_id'=>20]);
        self::assertSame(ContentDocument::hash($document['entities'][0]['identity_facts']),$receipt['base_facts_hash']);
        self::assertSame(['age','gender'],$receipt['changed_keys']);
        $nextEntities=SeriesVisualState::inherit($context['entities'],$edited['entities'],$edited['identity_revision_receipts'],['tenant_id'=>10,'user_id'=>20]);
        self::assertSame($facts,$nextEntities[0]['identity_facts']);self::assertSame('hero',$nextEntities[0]['entity_id']);self::assertSame('c1',$nextEntities[0]['ref']);
        $next=ContentDocument::normalize($reply,array_replace($context,['revision_id'=>'r2','episode_id'=>'e2','entities'=>$nextEntities,'identity_revision_receipts'=>$edited['identity_revision_receipts']]));
        self::assertSame($receipt,$next['identity_revision_receipts']['hero']);
        self::assertSame($facts,SeriesVisualState::inherit($context['entities'],$next['entities'],$next['identity_revision_receipts'])[0]['identity_facts']);
        $history=ManualRevisionHistory::page($input,10,20,1)['records'][0]['changes'][0];
        self::assertSame('男',json_decode($history['before_text'],true)['identity_facts']['gender']);self::assertSame('女',json_decode($history['after_text'],true)['identity_facts']['gender']);
        $candidate=static function($doc): array {return ['document'=>$doc,'shot_plan'=>ShotCompiler::compile($doc,['shots'=>[['visual_description'=>'画面','refs'=>[['unit'=>'u1']]]]],'shots'),'coverage_report'=>['narrative_ledger'=>NarrativeLedger::evaluate($doc,[])]];};
        self::assertNotSame(ImageHandoff::targets($candidate($document),'写实')[0]['state_hash'],ImageHandoff::targets($candidate($edited),'写实')[0]['state_hash']);
        self::assertSame(ImageHandoff::targets($candidate($document),'写实')[1]['state_hash'],ImageHandoff::targets($candidate($edited),'写实')[1]['state_hash']);
    }

    public function testSuccessiveCorrectionsKeepOriginalSeriesBaselineAndModelsCannotOverrideOrInventReceipts(): void
    {
        [$doc,$context,$origin,$patch,$reply]=$this->fixture();
        $input=ApplicationWorkflow::revisionInput(['context'=>$context],['document'=>$doc],[],'r1','new',$origin,$patch);$edited=$input['context']['canonical_input'];
        $origin['parent_task_id']='new';$origin['task_id']='third';$patch['entities'][0]['before_hash']=StructuralRevision::targets($edited)['entities'][0]['before_hash'];$patch['entities'][0]['identity_facts']['age']=30;
        $third=ApplicationWorkflow::revisionInput($input,['document'=>$edited],[],$edited['revision_id'],'third',$origin,$patch)['context']['canonical_input'];
        self::assertSame($edited['identity_revision_receipts']['hero']['receipt_hash'],$third['identity_revision_receipts']['hero']['previous_receipt_hash']);
        self::assertSame(30,SeriesVisualState::inherit($context['entities'],$third['entities'],$third['identity_revision_receipts'])[0]['identity_facts']['age']);
        $forged=$reply;$forged['identity_revision_receipts']=$third['identity_revision_receipts'];
        self::assertArrayNotHasKey('identity_revision_receipts',ContentDocument::normalize($forged,$context));
        $nextContext=$context;$nextContext['entities']=SeriesVisualState::inherit($context['entities'],$third['entities'],$third['identity_revision_receipts']);$nextContext['identity_revision_receipts']=$third['identity_revision_receipts'];
        $reply['entities']=[['ref'=>'c1','kind'=>'character','name'=>'陈默','identity_facts'=>['age'=>99]]];
        $this->expectExceptionCode(409);ContentDocument::normalize($reply,$nextContext);
    }

    /** @dataProvider invalidFacts */
    public function testIdentityEditsCannotChangeLibraryOwnershipOrSubmitUnboundedOrMalformedFacts(string $case,int $code): void
    {
        [$doc,$context,$origin,$patch]=$this->fixture();$facts=&$patch['entities'][0]['identity_facts'];
        if($case==='library')$facts['library_subject_id']=88;
        if($case==='remove_library')unset($facts['library_subject_id']);
        if($case==='nested')$facts['gender']=['男','女'];
        if($case==='list')$facts=['女'];
        if($case==='float')$facts['age']=28.5;
        if($case==='key')$facts['bad key']='x';
        if($case==='long')$facts['职业']=str_repeat('长',2001);
        if($case==='excess')for($index=0;$index<32;$index++)$facts['额外'.$index]='值';
        $this->expectExceptionCode($code);ApplicationWorkflow::revisionInput(['context'=>$context],['document'=>$doc],[],'r1','new',$origin,$patch);
    }
    public static function invalidFacts(): array {return [['library',422],['remove_library',422],['nested',422],['list',422],['float',422],['key',422],['long',422],['excess',422]];}

    /** @dataProvider invalidReceipts */
    public function testInheritedCorrectionMustHaveMatchingUntamperedOwnerScopedReceipt(string $case): void
    {
        [$doc,$context,$origin,$patch]=$this->fixture();$next=ApplicationWorkflow::revisionInput(['context'=>$context],['document'=>$doc],[],'r1','new',$origin,$patch)['context']['canonical_input'];
        $receipts=$next['identity_revision_receipts'];$scope=['tenant_id'=>10,'user_id'=>20];
        if($case==='absent')$receipts=[];
        if($case==='tamper')$receipts['hero']['base_facts_hash']='x';
        if($case==='facts')$next['entities'][0]['identity_facts']['age']=31;
        if($case==='tenant')$scope['tenant_id']=11;
        if($case==='user')$scope['user_id']=21;
        if($case==='baseline')$context['entities'][0]['identity_facts']['age']=70;
        if($case==='kind')$next['entities'][0]['kind']='prop';
        $this->expectExceptionCode(409);SeriesVisualState::inherit($context['entities'],$next['entities'],$receipts,$scope);
    }
    public static function invalidReceipts(): array {return [['absent'],['tamper'],['facts'],['tenant'],['user'],['baseline'],['kind']];}

    public function testModelProjectionKeepsChangedFactsButNotActorReceiptsAtEitherProtocolVersion(): void
    {
        [$doc,$context,$origin,$patch]=$this->fixture();$next=ApplicationWorkflow::revisionInput(['context'=>$context],['document'=>$doc],[],'r1','new',$origin,$patch)['context'];
        foreach([0,1] as $projection) {
            $messages=GenerationPipeline::messages(['document'=>$next['canonical_input'],'context'=>['entities'=>$next['entities'],'identity_revision_receipts'=>$next['identity_revision_receipts']],
                'identity_revision_receipts'=>$next['identity_revision_receipts']],'核对',$projection);
            self::assertStringNotContainsString('identity_revision_receipts',$messages['content']);self::assertStringNotContainsString('created_at',$messages['content']);
            self::assertSame('女',json_decode($messages['content'],true)['document']['entities'][0]['identity_facts']['gender']);
        }
    }

    public function testExistingComplexOrLargeIdentityRecordCanBeCorrectedWithoutLosingLegacyFields(): void
    {
        [$doc,,$origin]=$this->fixture();$entity=$doc['entities'][0];
        for($index=0;$index<40;$index++)$entity['identity_facts']['历史'.$index]=['完整'=>['保留😀']];
        $entity['identity_facts']['legacy key']='保留旧属性';$facts=$entity['identity_facts'];$facts['gender']='女';
        $receipt=EntityIdentityRevision::capture($entity,$facts,'r2',$origin);
        $entity['identity_facts']=$facts;EntityIdentityRevision::assertReceipt($receipt,$entity);
        self::assertSame(['完整'=>['保留😀']],$facts['历史39']);
        $facts['新的额外属性']='不能继续扩张';$this->expectExceptionCode(422);EntityIdentityRevision::capture($entity,$facts,'r3',$origin,$receipt);
    }

    public function testChangedAndClearedIdentityAttributesHaveExplicitPrecedenceWithoutDiscardingOtherDemands(): void
    {
        [$doc,$context,$origin,$patch]=$this->fixture();$context['prompt']='陈默是男性，年龄40岁，必须保留发现书信的剧情。';
        $context['requirements']=[['id'=>'letter','text'=>'必须保留发现书信的剧情。']];
        unset($patch['entities'][0]['identity_facts']['age']);
        $input=ApplicationWorkflow::revisionInput(['context'=>$context],['document'=>$doc],[],'r1','new',$origin,$patch);$next=$input['context'];
        $creative=GenerationPipeline::creativeContext($next);$correction=$creative['identity_corrections'][0];
        self::assertSame(['gender'=>'女'],$correction['set_facts']);self::assertSame(['age'],$correction['cleared_fields']);
        self::assertStringNotContainsString('library_subject_id',json_encode($correction));self::assertStringNotContainsString('origin',json_encode($correction));
        self::assertSame($context['prompt'],$creative['prompt']);self::assertSame($context['requirements'],$creative['requirements']);
        self::assertArrayNotHasKey('identity_revision_receipts',$creative);
        $document=$next['canonical_input'];$plan=ShotCompiler::compile($document,['shots'=>[['visual_description'=>'画面','refs'=>[['unit'=>'u1']]]]],'shots');
        self::assertSame([$correction],GenerationPipeline::reviewData($next,$document,$plan)['identity_corrections']);
        foreach([GenerationPipeline::screenplayInstruction($next),GenerationPipeline::reviewInstruction($next)] as $instruction) {
            self::assertStringContainsString('不撤销其他要求、事件、世界事实或角色认知',$instruction);
            self::assertStringContainsString('不能从旧背景自动补回',$instruction);
        }
        $oldReceipt=$document['identity_revision_receipts']['hero'];unset($oldReceipt['changed_keys'],$oldReceipt['receipt_hash']);$oldReceipt['receipt_hash']=ContentDocument::hash($oldReceipt);
        EntityIdentityRevision::assertReceipt($oldReceipt,$document['entities'][0]);
        self::assertSame([],EntityIdentityRevision::modelCorrections(['hero'=>$oldReceipt],$document['entities'])[0]['cleared_fields'],'Older proof cannot guess which fields were removed');
    }
}
