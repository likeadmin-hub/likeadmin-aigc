<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{AigcShortDramaService as Service, ShortDramaContinuity as Continuity, ShortDramaEpisodeReview as Review, ShortDramaEpisodeService as Episodes, ShortDramaReviewDeferred as Deferred, ShortDramaReviewOutcome as Outcome, ShortDramaStoryWorkflow as Workflow};
use PHPUnit\Framework\TestCase;

class ShortDramaReviewOutcomeTest extends TestCase
{
    private function plan(): array
    {
        return ['title'=>'等候','story_outline'=>'陈溪在走廊等候','script_lines'=>['陈溪在走廊等候'],
            'subjects'=>[['id'=>'p','name'=>'陈溪','category'=>'character']],
            'locations'=>[['id'=>'hall','name'=>'走廊','interior_exterior'=>'interior']],
            'generation_settings'=>['input_contract_version'=>19,'quality_review_enabled'=>true],
            'storyboard'=>[['shot_id'=>'s1','scene_ref_id'=>'hall','scene_name'=>'走廊','subject_ref_ids'=>['p'],
                'visual_description'=>'陈溪在走廊等候。','dialogue'=>'','voice_role'=>'','speech_type'=>'none',
                'interior_exterior'=>'interior','recommended_duration_seconds'=>10,'image_url'=>'original-image','video_url'=>'original-video']]];
    }

    private function request(): array
    {
        return ['_input_contract_version'=>19,'prompt'=>'陈溪在走廊等候','episode_number'=>2,
            'series_context'=>['outline'=>[],'continuity'=>['previous_digest'=>'previous','state'=>[],'open_hooks'=>[]]]];
    }

    private function validReply(array $data): array
    {
        if (isset($data['source'])) return ['requirements'=>[]];
        if (isset($data['check_metadata'])) return ['checks'=>[],'issues'=>[]];
        if (isset($data['response_contract']['checks'])) return ['checks'=>[]];
        if (isset($data['script'])) return ['summary'=>'陈溪在走廊等候','changes'=>[],'hooks'=>[],'warnings'=>[]];
        if (isset($data['subjects'])) return ['categories'=>array_map(static fn($s)=>['id'=>$s['id'],'category'=>$s['category']],$data['subjects'])];
        throw new \LogicException('Unexpected review request');
    }

    public function testVerifiedScriptStaysIntactAndCanFeedNextEpisode(): void
    {
        $before=$this->plan(); $calls=0;
        $result=Review::generate($before,$this->request(),function($key,$input,$tokens,$validate)use(&$calls){
            $calls++;$reply=$this->validReply(json_decode($input['content'],true));$validate($reply);return $reply;
        });
        self::assertSame($before['storyboard'],$result['storyboard']);
        self::assertSame('verified',$result['script_review']['status']);
        self::assertFalse(Outcome::blocksNext($result));
        self::assertLessThanOrEqual(6,$calls);
        self::assertSame('verified',Outcome::present($result)['script_review']['status']);
        $result['storyboard'][0]['visual_description']='陈溪到了车站。';
        self::assertSame('needs_review',Outcome::present($result)['script_review']['status']);
    }

    public function testUnresolvedMeaningStopsAfterTwoLocalRepairsAndKeepsUsableDraft(): void
    {
        $before=$this->plan();$repairs=0;$checks=0;
        $result=Review::generate($before,$this->request(),function($key,$input,$tokens,$validate)use(&$repairs,&$checks){
            $d=json_decode($input['content'],true);
            if(isset($d['source']))$reply=['requirements'=>[['text'=>'陈溪递出信','kind'=>'event','source_index'=>0]]];
            elseif(isset($d['check_metadata'])){$checks++;$reply=['checks'=>[['id'=>'r1','status'=>'absent','evidence'=>[],'target_shot_ids'=>['s1']]],'issues'=>[]];}
            elseif(isset($d['targets'])){$repairs++;$reply=['patches'=>[['shot_id'=>'s1','field'=>'visual_description',
                'before'=>$d['targets'][0]['visual_description'],'after'=>'陈溪在走廊举起信，第'.$repairs.'次调整。']],'subject_categories'=>[]];}
            else $reply=$this->validReply($d);
            $validate($reply);return $reply;
        });
        self::assertSame(2,$repairs);self::assertSame(3,$checks);
        self::assertSame('needs_review',$result['script_review']['status']);
        self::assertNotEmpty($result['script_review']['issues']);
        self::assertTrue(Outcome::blocksNext($result));self::assertSame([],$result['_continuity']['state']);
        foreach(['shot_id','scene_ref_id','subject_ref_ids','recommended_duration_seconds','image_url','video_url'] as$field) {
            self::assertSame($before['storyboard'][0][$field],$result['storyboard'][0][$field]);
        }
        self::assertSame($before['script_lines'],$result['content_acceptance']['source_script_lines']);
        $row=Episodes::summary(['status'=>'success','completed_once'=>1,'needs_review'=>true,'continuity_json'=>json_encode($result['_continuity'])]);
        self::assertTrue($row['ready']);self::assertTrue($row['can_retry']);self::assertSame('success',$row['status']);
    }

    public function testMalformedSettledReviewResponseDefersWithoutDestroyingScript(): void
    {
        $calls=0;$before=$this->plan();$request=$this->request();
        $result=Review::generate($before,$request,static function($key,$input,$tokens,$validate)use(&$calls,$request){
            $calls++;return Outcome::decode(['content'=>'{"requirements":','finish_reason'=>'stop'],$request,$validate);
        });
        self::assertSame(3,$calls);self::assertSame($before['storyboard'],$result['storyboard']);
        self::assertSame('needs_review',$result['script_review']['status']);
    }

    /** @dataProvider technicalFailures */
    public function testProviderBillingCancelAndDispatchFailuresAreNeverConvertedToSuccess(int $code, string $message): void
    {
        $error=new \RuntimeException($message,$code);
        try {Review::generate($this->plan(),$this->request(),static function()use($error){throw $error;});self::fail('Technical failure was swallowed');}
        catch(\RuntimeException $caught){self::assertSame($error,$caught);}
    }

    public static function technicalFailures(): array
    {
        return [[425,'等待继续生成'],[409,'计费结算失败'],[429,'上游限流'],[499,'用户取消'],[500,'数据库写入失败'],[422,'provider rejected request'],[413,'provider capacity failure']];
    }

    /** @dataProvider technicalFailures */
    public function testTechnicalReviewFailureKeepsDraftWithoutConvertingFailureToSuccess(int $code, string $message): void
    {
        $base=$this->plan();$request=array_replace($this->request(),['_input_contract_version'=>21]);$saved=[];
        $error=new \RuntimeException($message,$code);
        try {
            Review::generate($base,$request,static function()use($error){throw $error;},null,
                static function($plan,$reviewed,$resume)use(&$saved){$saved[]=compact('plan','reviewed','resume');});
            self::fail('Technical failure must remain visible');
        } catch(\RuntimeException $caught){self::assertSame($error,$caught);}
        self::assertCount(1,$saved);self::assertSame($base,$saved[0]['plan']);self::assertSame(0,$saved[0]['reviewed']);
        self::assertSame(\app\common\service\app\aigc_short_drama\ShortDramaReviewCheckpoint::signature($base,$request),$saved[0]['resume']['signature']);
    }

    public function testReviewResumeDoesNotOverwriteExistingCheckpointWithInitialDraft(): void
    {
        $base=$this->plan();$request=array_replace($this->request(),['_input_contract_version'=>21]);
        $saved=['protocol_version'=>3,'reviewed_shots'=>1,'plan'=>$base,'resume'=>['signature'=>\app\common\service\app\aigc_short_drama\ShortDramaReviewCheckpoint::signature($base,$request)]];
        $writes=[];
        try {Review::generate($base,$request,static function(){throw new \RuntimeException('provider unavailable',503);},null,
            static function($plan,$reviewed,$resume)use(&$writes){$writes[]=compact('plan','reviewed','resume');},$saved);self::fail('Expected provider failure');}
        catch(\RuntimeException $error){self::assertSame(503,$error->getCode());}
        self::assertSame([],$writes);
    }

    public function testOldFrozenRequestsKeepFailureBehavior(): void
    {
        $error=new Deferred('整集内容检查尚未通过');
        $this->expectExceptionObject($error);
        Review::generate($this->plan(),array_replace($this->request(),['_input_contract_version'=>18]),static function()use($error){throw $error;});
    }

    public function testActualUnresolvedReviewBlocksDependenciesEvenWhenOptionalReviewIsOff(): void
    {
        $plan=Outcome::pending($this->plan(),$this->request(),'复核发现未解决问题');
        $ledger=$plan['_continuity'];$ledger['previous_digest']='';
        $next=array_replace($ledger,['audit_status'=>'verified','digest'=>'next','previous_digest'=>$ledger['digest']]);
        $rows=[['id'=>1,'status'=>'success','production_project_id'=>1,'continuity_json'=>json_encode($ledger)],
            ['id'=>2,'status'=>'success','production_project_id'=>2,'continuity_json'=>json_encode($next)]];
        $result=Continuity::dependencyStatus($rows,[],false);
        self::assertTrue($result[0]['needs_review']);self::assertTrue($result[1]['needs_review']);
        $ledger['audit_status']='verified';$rows[0]['continuity_json']=json_encode($ledger);
        $result=Continuity::dependencyStatus($rows,[],false);
        self::assertFalse($result[0]['needs_review']);self::assertFalse($result[1]['needs_review']);
    }

    public function testNewDefaultAndExplicitOptOutAreSeparateFromHistoricalCanvasPolicy(): void
    {
        $m=new \ReflectionMethod(Service::class,'normalizeCreateRequest');$m->setAccessible(true);
        self::assertFalse($m->invoke(null,[],[])['quality_review_enabled']);
        self::assertFalse($m->invoke(null,['quality_review_enabled'=>false],[])['quality_review_enabled']);
        $canvas=['_submission_key'=>'canvas-production:test'];
        self::assertFalse(Workflow::qualityReviewEnabled($canvas));
        self::assertFalse(Workflow::qualityReviewEnabled($canvas+['_input_contract_version'=>19]));
        self::assertTrue(Workflow::qualityReviewEnabled($canvas+['_input_contract_version'=>19,'quality_review_enabled'=>true]));
        self::assertFalse(Workflow::qualityReviewEnabled($canvas+['_input_contract_version'=>19,'quality_review_enabled'=>false]));
    }

    public function testSkippedReviewContinuesAndRetainsNarrativeMemoryWithoutClaimingVerification(): void
    {
        $plan=$this->plan();
        $plan['_continuity']=['source_memory'=>['version'=>1,'blocks'=>[['text'=>'陈溪在走廊等候']]]];
        $result=Outcome::unreviewed($plan,$this->request());
        self::assertSame($plan['storyboard'],$result['storyboard']);
        self::assertSame($plan['_continuity']['source_memory'],$result['_continuity']['source_memory']);
        self::assertSame('skipped',$result['_continuity']['audit_status']);
        self::assertSame('unreviewed',$result['script_review']['status']);
        self::assertSame('陈溪在走廊等候',$result['_continuity']['summary']);
        self::assertFalse(Outcome::blocksNext($result));
        $rows=[['id'=>1,'status'=>'success','result_json'=>json_encode($result),'continuity_json'=>json_encode($result['_continuity'])]];
        self::assertFalse(Continuity::dependencyStatus($rows,[],false)[0]['needs_review']);
    }

    public function testLegacyOptOutIsReleasedButFailedReviewAndDifferentRevisionAreNot(): void
    {
        $old=Outcome::pending($this->plan(),$this->request(),'旧版未复核',[],'unreviewed');
        self::assertFalse(Outcome::blocksNext($old));
        $row=['id'=>1,'production_project_id'=>1,'status'=>'success','result_json'=>json_encode($old),
            'continuity_json'=>json_encode($old['_continuity'])];
        $current=[1=>['ledger'=>$old['_continuity'],'narrative_digest'=>$old['_continuity']['digest']]];
        $result=Continuity::dependencyStatus([$row],$current,false);
        self::assertFalse($result[0]['needs_review']);
        self::assertSame('skipped',json_decode($result[0]['continuity_json'],true)['audit_status']);
        $failed=$old;$failed['script_review']['status']='needs_review';
        self::assertTrue(Outcome::blocksNext($failed));
        $row['result_json']=json_encode($failed);
        self::assertTrue(Continuity::dependencyStatus([$row],$current,false)[0]['needs_review']);
        $old['storyboard'][0]['visual_description']='角色已进入车站';
        self::assertTrue(Outcome::blocksNext($old),'Different narrative cannot use an old opt-out to waive review');
    }
}
