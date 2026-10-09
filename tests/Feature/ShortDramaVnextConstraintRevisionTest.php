<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\vnext\ApplicationWorkflow;
use app\common\service\app\aigc_short_drama\vnext\ConstraintRevision;
use app\common\service\app\aigc_short_drama\vnext\ContentDocument;
use app\common\service\app\aigc_short_drama\vnext\GenerationPipeline;
use app\common\service\app\aigc_short_drama\vnext\ManualRevisionHistory;
use app\common\service\app\aigc_short_drama\vnext\SourceTrace;
use app\common\service\app\aigc_short_drama\vnext\StructuralRevision;
use PHPUnit\Framework\TestCase;

final class ShortDramaVnextConstraintRevisionTest extends TestCase
{
    private function fixture(): array
    {
        $input=ShortDramaVnextSceneShotTest::input(2);$context=$input['context'];$doc=$context['canonical_input'];
        $source=SourceTrace::capture('source_choices','1','要求白天。要求夜晚。保留原文。','inspiration');
        $context['sources']=[$source['source_id']=>$source];$context['prompt']=$source['text'];
        $context['requirements']=SourceTrace::requirements($source,[['start'=>0,'end'=>5,'quote'=>'要求白天。'],['start'=>5,'end'=>10,'quote'=>'要求夜晚。'],['start'=>10,'end'=>15,'quote'=>'保留原文。']]);
        $context['events']=[['id'=>'event_ending','text'=>'完整尾句事件','label'=>'完整尾句事件','blocking'=>true,'episode_ids'=>['e1']]];
        $origin=['tenant_id'=>10,'user_id'=>20,'created_at'=>1791500000,'parent_task_id'=>'old','task_id'=>'choice1'];
        $patch=['constraint_revision'=>['before_hash'=>ConstraintRevision::targets($context)['before_hash'],
            'supersedes'=>array_column(array_slice($context['requirements'],0,2),'id'),'text'=>'本集明确发生在夜晚，保留全部对白😀。']];
        return [['context'=>$context],$doc,$origin,$patch];
    }
    private function revise(array $input,array $doc,array $origin,array $patch): array
    {
        return ApplicationWorkflow::revisionInput($input,['document'=>$doc],[],$doc['revision_id'],$origin['task_id'],$origin,$patch);
    }
    public function testChoiceChangesOnlySelectedChecksWithCompleteBeforeAfterHistoryAndSources(): void
    {
        [$input,$doc,$origin,$patch]=$this->fixture();$original=$input;
        $next=$this->revise($input,$doc,$origin,$patch);$edited=$next['context']['canonical_input'];
        self::assertSame($original,$input);self::assertSame($doc['scenes'],$edited['scenes']);
        self::assertSame($input['context']['events'],$next['context']['events']);self::assertCount(2,$next['context']['requirements']);
        self::assertSame($input['context']['requirements'][2],$next['context']['requirements'][0]);
        self::assertSame($patch['constraint_revision']['text'],$next['context']['requirements'][1]['text']);
        self::assertSame(['e1'],$next['context']['requirements'][1]['episode_ids']);
        self::assertSame($input['context']['prompt'],$next['context']['prompt']);
        $history=ManualRevisionHistory::page($next,10,20,1)['records'][0]['changes'][0];
        self::assertSame('本集要求冲突选择',$history['target_label']);self::assertCount(2,json_decode($history['before_text'],true)['selected']);
        self::assertSame($patch['constraint_revision']['text'],json_decode($history['after_text'],true)['text']);
        $proof=$edited['constraint_revision_receipts'][0];self::assertSame($proof['source'],$next['context']['sources'][$proof['source']['source_id']]);
        self::assertCount(3,StructuralRevision::targets($edited,[],$next['context'])['constraint_revision']['items']);
    }
    public function testRecheckReplaysLocalChoiceAndKeepsUnrelatedNewRequirements(): void
    {
        [$input,$doc,$origin,$patch]=$this->fixture();$next=$this->revise($input,$doc,$origin,$patch);$edited=$next['context']['canonical_input'];
        $fresh=$input['context'];$fresh['dependency_versions']=$doc['dependency_versions'];$fresh['requirements'][]=['id'=>'requirement_added','text'=>'新增未撤销要求'];
        $recheck=ApplicationWorkflow::recheckInput($next,['document'=>$edited],$fresh,$edited['revision_id'],'recheck');
        self::assertSame(['保留原文。','新增未撤销要求',$patch['constraint_revision']['text']],array_column($recheck['context']['requirements'],'text'));
        self::assertSame($next['context']['events'],$recheck['context']['events']);
        self::assertSame($edited['constraint_revision_receipts'],$recheck['context']['canonical_input']['constraint_revision_receipts']);
        $fresh['requirements'][0]['text']='白天要求已更新';$this->expectExceptionCode(409);
        ApplicationWorkflow::recheckInput($next,['document'=>$edited],$fresh,$edited['revision_id'],'recheck2');
    }
    public function testPipelineReviewsNewChecksWithoutRepeatingCreativeGenerationOrLeakingReceipts(): void
    {
        [$input,$doc,$origin,$patch]=$this->fixture();$next=$this->revise($input,$doc,$origin,$patch);$keys=[];
        $result=GenerationPipeline::run($next['context'],static function($key,$messages) use (&$keys) {
            $keys[]=$key;$data=json_decode($messages['content'],true);$reply=ShortDramaVnextSceneShotTest::reply($data);
            self::assertStringNotContainsString('manual_constraint_choice',$messages['content']);
            if($key==='review') {
                self::assertStringContainsString('只', $messages['system_prompt']);self::assertCount(1,$data['constraint_choices']);
                $decoded=json_decode($reply['content'],true);$unit=$data['document']['scenes'][0]['units'][0];
                foreach(array_merge($data['requirements'],$data['events']) as $target)$decoded['checks'][]=['id'=>$target['id'],'status'=>'covered','evidence'=>[['unit'=>$unit['ref'],'start'=>0,'end'=>mb_strlen($unit['text']),'quote'=>$unit['text']]]];
                $reply['content']=json_encode($decoded,JSON_UNESCAPED_UNICODE);
            }
            return $reply;
        },static function(){self::fail('Manual content cannot auto-repair');},static function(){});
        self::assertSame(['shots','review'],$keys);self::assertSame('ready',$result['coverage_report']['status']);
        self::assertSame($doc['scenes'],$result['document']['scenes']);self::assertCount(6,$result['coverage_report']['coverage']);
    }
    public function testEventMayBeReplacedOnlyWhenExplicitlySelected(): void
    {
        [$input,$doc,$origin,$patch]=$this->fixture();$patch['constraint_revision']['supersedes']=['event_ending'];
        $next=$this->revise($input,$doc,$origin,$patch);self::assertSame([],$next['context']['events']);
        self::assertSame(array_column($input['context']['requirements'],'id'),array_slice(array_column($next['context']['requirements'],'id'),0,3));
    }
    /** @dataProvider invalidChoices */
    public function testInvalidOrBroadImplicitChoiceIsRejected(string $case,int $code): void
    {
        [$input,$doc,$origin,$patch]=$this->fixture();$change=&$patch['constraint_revision'];
        if($case==='stale')$change['before_hash']='stale';
        if($case==='unknown')$change['supersedes']=['other_episode'];
        if($case==='duplicate')$change['supersedes']=[$change['supersedes'][0],$change['supersedes'][0]];
        if($case==='none')$change['supersedes']=[];
        if($case==='empty')$change['text']=' ';
        if($case==='long')$change['text']=str_repeat('字',16001);
        if($case==='extra')$change['skip_review']=true;
        if($case==='combine')$patch['entities']=[];
        $this->expectExceptionCode($code);$this->revise($input,$doc,$origin,$patch);
    }
    public static function invalidChoices(): array {return [['stale',409],['unknown',409],['duplicate',409],['none',422],['empty',422],['long',413],['extra',409],['combine',422]];}
    public function testForeignScopeOrAnotherEpisodeCannotReuseTheChoice(): void
    {
        [$input,$doc,$origin,$patch]=$this->fixture();$next=$this->revise($input,$doc,$origin,$patch);$proof=$next['context']['canonical_input']['constraint_revision_receipts'];
        try {ConstraintRevision::assertReceipts($proof,'e1',['tenant_id'=>10,'user_id'=>21]);self::fail('Foreign scope accepted');}catch(\RuntimeException $error){self::assertSame(409,$error->getCode());}
        $this->expectExceptionCode(409);ConstraintRevision::assertReceipts($proof,'e2');
    }
}
