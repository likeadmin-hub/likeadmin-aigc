<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaUnifiedReview as Review,ShortDramaContentAcceptance as Acceptance,
    ShortDramaRepairBudget as Budget,ShortDramaExecutionPolicy as Policy};
use PHPUnit\Framework\TestCase;

final class ShortDramaUnifiedReviewTest extends TestCase
{
    private function plan(): array
    {
        return ['title'=>'告别','story_outline'=>'女儿送别父亲','script_lines'=>['女儿送别父亲'],
            'subjects'=>[['id'=>'a','name'=>'女儿','category'=>'character']], 'locations'=>[['id'=>'hall','name'=>'大厅']],
            'storyboard'=>[['shot_id'=>'s1','scene_ref_id'=>'hall','subject_ref_ids'=>['a'],'visual_description'=>'女儿挥手告别',
                'dialogue'=>'女儿：一路平安。','voice_role'=>'女儿','speech_type'=>'character','interior_exterior'=>'interior','recommended_duration_seconds'=>6]]];
    }
    private function request(): array{return ['_input_contract_version'=>9,'prompt'=>'女儿挥手告别。','episode_number'=>1];}
    private function contract(): array
    {
        return Acceptance::prepareContract($this->request(),static fn()=>['requirements'=>[['text'=>'女儿挥手告别','kind'=>'event','source_index'=>0]]]);
    }
    private function reply(array $data): array
    {
        return ['checks'=>array_map(static fn($r)=>['id'=>$r['id'],'status'=>'covered','evidence_refs'=>[['shot_id'=>$data['script']['storyboard'][0]['shot_id'],'field'=>'visual_description']]],$data['requirements']),
            'issues'=>[], 'continuity'=>['summary'=>'女儿挥手告别','changes'=>[],'hooks'=>[],'warnings'=>[]]];
    }
    public function testNormalEpisodeUsesOneCombinedPassAndFrozenRequirements(): void
    {
        $plan=$this->plan();$plan['generation_contract']=$this->contract();$calls=[];
        $result=Review::generate($plan,$this->request(),function($key,$input)use(&$calls){$calls[]=$key;return $this->reply(json_decode($input['content'],true));});
        self::assertCount(1,$calls);self::assertStringStartsWith('unified_v9_inspect_',$calls[0]);
        self::assertSame('verified',$result['content_acceptance']['status']);
        self::assertSame('verified',$result['_continuity']['audit_status']);
        self::assertSame('unified_v9',$result['_continuity']['review_mode']);
        self::assertSame(0,$result['execution_diagnostics']['repair_budget']['used']);
    }
    public function testReviewerSeesInteriorExteriorAndCannotOverrideServerFlags(): void
    {
        $plan=$this->plan();$plan['generation_contract']=$this->contract();
        Review::generate($plan,$this->request()+['series_context'=>['_server_owned_state'=>false]],function($key,$input){
            $data=json_decode($input['content'],true);
            self::assertSame('interior',$data['script']['storyboard'][0]['interior_exterior']);
            return $this->reply($data);
        });
    }
    public function testUnprovenConstraintCannotTriggerContentRewrite(): void
    {
        $requirements=[['id'=>'r1','kind'=>'constraint','text'=>'只有女儿一人说话']];
        $coverage=['r1'=>[['status'=>'violated','evidence_refs'=>[['shot_id'=>'s1','field'=>'dialogue']]]]];
        $calls=0;
        $result=Review::verifyViolations($this->plan(),$requirements,$coverage,function($key,$input)use(&$calls){
            $calls++;$data=json_decode($input['content'],true);
            self::assertSame('女儿：一路平安。',$data['claims'][0]['evidence'][0]['quote']);
            return ['verdicts'=>[['id'=>'r1:0','violated'=>false,'reason'=>'只有女儿说话，没有其他角色发言']]];
        });
        self::assertSame(1,$calls);self::assertSame('covered',$result['r1'][0]['status']);
        Review::verifyViolations($this->plan(),$requirements,$result,static function(){self::fail('Accepted constraint was re-read');});
    }
    public function testLongEpisodeIsBoundedWithoutRepeatingRequirementsExtraction(): void
    {
        $plan=$this->plan();$plan['generation_contract']=$this->contract();$shot=$plan['storyboard'][0];
        $plan['storyboard']=array_map(static fn($i)=>array_replace($shot,['shot_id'=>'s'.$i]),range(1,29));$windows=[];
        $result=Review::generate($plan,$this->request(),function($key,$input)use(&$windows){$data=json_decode($input['content'],true);$windows[]=count($data['script']['storyboard']);return $this->reply($data);});
        self::assertSame([12,12,5],$windows);self::assertCount(29,$result['storyboard']);
    }
    public function testCoverageCannotCiteAnUnknownOrEmptyShot(): void
    {
        $plan=$this->plan();$requirements=$this->contract()['requirements'];$reply=$this->reply(['requirements'=>$requirements,'script'=>$plan]);
        $reply['checks'][0]['evidence_refs'][0]['shot_id']='fabricated';
        $this->expectExceptionCode(422);Review::validate($reply,$plan,$requirements,[]);
    }
    public function testMissingEventMayInsertWithoutOverwritingAcceptedShots(): void
    {
        $plan=$this->plan();$shot=$plan['storyboard'][0];$shot['visual_description']='女儿关门';
        $reply=['patches'=>[],'subject_categories'=>[],'insertions'=>[['after_shot_id'=>'s1','shot'=>$shot]]];
        $result=Review::apply($plan,$reply,['s1'],[['code'=>'missing_event']],'r1');
        self::assertSame($plan['storyboard'][0],$result['storyboard'][0]);self::assertSame('r1_1_s1',$result['storyboard'][1]['shot_id']);
        $this->expectExceptionCode(422);Review::apply($plan,$reply,['s1'],[['code'=>'speaker']],'r1');
    }
    public function testSemanticRepairBudgetIsSharedAndReplayDoesNotSpendAgain(): void
    {
        $budget=new Budget();$budget->consume('density',1);$budget->consume('semantic',1);$budget->consume('semantic',1);
        self::assertSame(2,$budget->snapshot()['used']);
        $this->expectExceptionCode(429);$budget->consume('semantic',2);
    }
    public function testDurableBudgetIsPerEpisodeAndDoesNotChangeLegacyOrRoadmap(): void
    {
        Policy::assertAdmission(['_input_contract_version'=>8],999);
        Policy::assertAdmission(['_input_contract_version'=>9,'multi_episode'=>true,'episode_count'=>500],999);
        Policy::assertAdmission($this->request(),95);
        $this->expectExceptionCode(429);Policy::assertAdmission($this->request(),96);
    }
    public function testMissingEventIsRecheckedAfterLocalRepair(): void
    {
        $plan=$this->plan();$plan['generation_contract']=$this->contract();$inspect=0;$calls=0;
        $result=Review::generate($plan,$this->request(),function($key,$input)use(&$inspect,&$calls){
            $calls++;$data=json_decode($input['content'],true);
            if(str_starts_with($key,'unified_v9_repair_'))return ['patches'=>[['shot_id'=>'s1','field'=>'visual_description','before'=>'女儿挥手告别','after'=>'女儿向父亲挥手告别']],'subject_categories'=>[],'insertions'=>[]];
            $reply=$this->reply($data);if($inspect++===0){$reply['checks'][0]['status']='absent';$reply['checks'][0]['evidence_refs']=[];}return $reply;
        });
        self::assertSame(3,$calls);self::assertSame(1,$result['execution_diagnostics']['repair_budget']['used']);
        self::assertSame('女儿向父亲挥手告别',$result['storyboard'][0]['visual_description']);
    }
}
