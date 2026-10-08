<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\ShortDramaNarrativeMemory as Memory;
use app\common\service\app\aigc_short_drama\ShortDramaPlanningBudget as Budget;
use app\common\service\app\aigc_short_drama\ShortDramaContinuity as Continuity;
use app\common\service\app\aigc_short_drama\ShortDramaEpisodeReview as Review;
use app\common\service\app\aigc_short_drama\ShortDramaDurationAllocation as Allocation;
use PHPUnit\Framework\TestCase;

class ShortDramaNarrativeMemoryTest extends TestCase
{
    private function archive(int $episodes): array
    {
        $state = []; $hooks = []; $history = [];
        for ($n = 1; $n <= $episodes; $n++) {
            for ($j = 0; $j < 8; $j++) $state["subject_$n:fact_$j"] = str_repeat("第{$n}集角色的独立经历。", 8);
            for ($j = 0; $j < 3; $j++) $hooks["promise_{$n}_$j"] = "第{$n}集另一个人的约定";
            $history[$n] = ['episode_number'=>$n, 'summary'=>str_repeat("第{$n}集发生的独立故事。", 20), 'digest'=>"source_$n"];
        }
        $hooks['copper_key_20'] = '第二十集埋下的紫铜钥匙用于打开灯塔密室';
        $history[20] = ['episode_number'=>20, 'summary'=>'紫铜钥匙藏在灯塔密室门前', 'digest'=>'episode20-source'];
        return ['state'=>$state,'open_hooks'=>$hooks,'history'=>$history,'audit_status'=>'verified'];
    }

    public function testFiveHundredEpisodeMemoryIsBoundedAndFindsDistantSources(): void
    {
        foreach ([10,100,500] as $count) {
            $source = $this->archive($count); $copy = $source;
            $messages = ['system_prompt'=>'生成本集', 'content'=>json_encode(['user_prompt'=>'用紫铜钥匙打开灯塔密室',
                'series_context'=>['current_episode'=>['episode_number'=>501], 'continuity'=>$source]], JSON_UNESCAPED_UNICODE)];
            $budget = Budget::fitMessages($messages, [], 'script', 4096, true);
            self::assertLessThan(15000, $budget['input_estimate']);
            $view = json_decode($messages['content'], true)['series_context']['continuity'];
            self::assertSame($source['open_hooks']['copper_key_20'], $view['open_hooks']['copper_key_20']);
            self::assertSame('episode20-source', $view['history'][20]['digest']);
            self::assertSame($copy, $source);
            self::assertSame(count($source['state']), $view['memory_scope']['state']['total']);
        }
    }

    public function testVerifierPinsExactOldRecordsEvenWhenTheyDoNotMatchTheQuery(): void
    {
        $source = $this->archive(500);
        $review = ['changes'=>[['entity_id'=>'subject_499','field'=>'fact_7','after'=>'完全不同的事件']],
            'hooks'=>[['id'=>'promise_499_2','status'=>'resolved']]];
        $messages = ['content'=>json_encode(['previous_state'=>$source['state'], 'previous_open_hooks'=>$source['open_hooks'], 'review'=>$review], JSON_UNESCAPED_UNICODE)];
        $result = json_decode(Memory::messages($messages, 1000)['content'], true);
        self::assertSame($source['state']['subject_499:fact_7'], $result['previous_state']['subject_499:fact_7']);
        self::assertSame($source['open_hooks']['promise_499_2'], $result['previous_open_hooks']['promise_499_2']);
        self::assertLessThan(100, count($result['previous_state']));
    }

    public function testLegacyCapacityFailureUsesWorkingMemoryButFittingReceiptsStayExact(): void
    {
        $messages=['system_prompt'=>'生成本集','content'=>json_encode(['series_context'=>['continuity'=>$this->archive(500)],'user_prompt'=>'紫铜钥匙'],JSON_UNESCAPED_UNICODE)];
        $budget=Budget::fitMessages($messages,[],'script',4096);
        self::assertLessThan(15000,$budget['input_estimate']);
        $small=['system_prompt'=>'旧协议','content'=>json_encode(['series_context'=>['continuity'=>['state'=>['p:x'=>'原值']]]],JSON_UNESCAPED_UNICODE)];
        $original=$small; Budget::fitMessages($small,[],'script',4096); self::assertSame($original,$small);
    }

    public function testCapacityProjectionPreservesUserRequirementsAndRepairSuffix(): void
    {
        $text = '第480集才能揭露身世，必须保留这句话。';
        $messages = ['content'=>json_encode(['user_prompt'=>$text,'series_context'=>['continuity'=>$this->archive(500)]], JSON_UNESCAPED_UNICODE) . "\n修复指定字段，不能更改对白。"];
        $projected = Memory::messages($messages);
        self::assertStringContainsString($text, $projected['content']);
        self::assertStringEndsWith("\n修复指定字段，不能更改对白。", $projected['content']);
        self::assertSame(['content'=>$text], Memory::messages(['content'=>$text]));
        $input=['user_prompt'=>'保留符号 A/B 与 https://example.test/story','series_context'=>['continuity'=>$this->archive(500)]];
        $wire=['content'=>json_encode($input,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        $decoded=json_decode(Memory::messages($wire)['content'],true);
        self::assertSame($input['user_prompt'],$decoded['user_prompt']);
        self::assertArrayHasKey('memory_scope',$decoded['series_context']['continuity']);
    }

    public function testLongHistoricalSourceIsRetrievedAsExactExcerpt(): void
    {
        $summary=str_repeat('平常的一天。',500).'紫铜钥匙打开灯塔密室'.str_repeat('平常的一天。',500);
        $view=Memory::continuity(['history'=>[20=>['episode_number'=>20,'summary'=>$summary,'digest'=>'original']]],'紫铜钥匙打开灯塔密室');
        $matches=array_filter($view['history'],static fn($r)=>str_contains($r['excerpt'] ?? '', '紫铜钥匙打开灯塔密室'));
        self::assertNotEmpty($matches);
        foreach($matches as $row) {
            self::assertSame(mb_substr($summary,$row['start_character'],600),$row['excerpt']);
            self::assertSame('original',$row['digest']);
        }
    }

    public function testFiveHundredSequentialEpisodesKeepFactsAndBoundEveryReviewCall(): void
    {
        $previous=[]; $maxInput=0; $calls=0;
        for($episode=1;$episode<=500;$episode++) {
            $plan=['subjects'=>[['id'=>'p','name'=>'林舟']], 'locations'=>[],
                'storyboard'=>[['shot_id'=>'s1','visual_description'=>"林舟完成第{$episode}日记录"]]];
            $request=['_input_contract_version'=>7,'episode_number'=>$episode,'series_context'=>[
                'current_episode'=>['episode_number'=>$episode], 'continuity'=>Continuity::context($previous,true)]];
            $result=Review::generate($plan,$request,static function($key,$input,$desired,$validate) use($episode,&$maxInput,&$calls) {
                $budget=Budget::fitMessages($input,[],'script',$desired,true); $maxInput=max($maxInput,$budget['input_estimate']); $calls++;
                $data=json_decode($input['content'],true);
                $reply=isset($data['response_contract']['checks']) ? ['checks'=>array_map(static fn($c)=>array_replace($c,['supported'=>true,'reason'=>'镜头记载该事实','failure_kind'=>'uncertain']),$data['response_contract']['checks'])]
                    : ['summary'=>"第{$episode}日完成记录",'changes'=>[['entity_id'=>'p','field'=>'record_'.$episode,'after'=>'完成',
                        'evidence_refs'=>[['shot_id'=>'s1','field'=>'visual_description']]]], 'hooks'=>[],'warnings'=>[]];
                $validate($reply); return $reply;
            });
            self::assertSame('verified',$result['_continuity']['audit_status']);
            self::assertCount($episode,$result['_continuity']['state']);
            $previous[]=$result['_continuity'];
        }
        self::assertSame('完成',$previous[499]['state']['p:record_20']);
        self::assertSame(1000,$calls); self::assertLessThan(18000,$maxInput);
    }

    public function testLargeLedgerIsNotTruncatedOrRejectedByWorkingMemoryLimits(): void
    {
        $source = $this->archive(500);
        $plan = ['subjects'=>[], 'locations'=>[], 'storyboard'=>[]];
        $ledger = Continuity::ledger(['summary'=>'本集已结束','changes'=>[],'hooks'=>[],'warnings'=>[]], $plan,
            ['continuity'=>$source,'_bounded_memory'=>true], 501);
        self::assertSame($source['state'], $ledger['state']);
        self::assertSame($source['open_hooks'], $ledger['open_hooks']);
    }

    public function testFiveHundredDurationsPreserveFixedValuesAndExactTotal(): void
    {
        $episodes = array_map(static fn($n)=>['episode_number'=>$n,'story_outline'=>str_repeat('事件', $n % 9 + 1)], range(1,500));
        $times = Allocation::allocate($episodes, 30000.125, [20=>72.125,480=>89.75]);
        self::assertCount(500, $times); self::assertEqualsWithDelta(30000.125,array_sum($times),.00001);
        self::assertSame(72.125,$times[19]); self::assertSame(89.75,$times[479]); self::assertGreaterThan(0,min($times));
    }

    public function testUnseenFactChangeCannotBeMistakenForUnchangedDependency(): void
    {
        $source = $this->archive(500); $first = Memory::dependency($source, '紫铜钥匙');
        $source['state']['subject_499:fact_7'] = '已死亡';
        self::assertNotSame($first, Memory::dependency($source, '紫铜钥匙'));
    }

    public function testDependencyPropagationStopsOnlyWhenAllNarrativeFactsRemainUnchanged(): void
    {
        $first=['version'=>1,'episode_number'=>1,'digest'=>'first','summary'=>'林舟保管钥匙',
            'state'=>['p:key_owner'=>'林舟'],'open_hooks'=>[],'audit_status'=>'verified','warnings'=>[]];
        $second=['version'=>1,'episode_number'=>2,'digest'=>'second','previous_digest'=>'first','audit_status'=>'verified',
            'memory_dependency'=>['version'=>1,'query'=>'钥匙','fingerprint'=>Memory::dependency(Continuity::context([$first],true),'钥匙')]];
        $rows=[['production_project_id'=>1,'status'=>'success','continuity_json'=>$first],
            ['production_project_id'=>2,'status'=>'success','continuity_json'=>$second]];
        $reviewed=$first; $reviewed['digest']='edited-and-reviewed';
        $actual=Continuity::dependencyStatus($rows,[1=>['ledger'=>$reviewed,'narrative_digest'=>$reviewed['digest']]]);
        self::assertFalse($actual[1]['needs_review'],'Prose edit with unchanged reviewed facts does not force every later episode to call the model');
        $reviewed['state']['p:key_owner']='陈伯';
        $actual=Continuity::dependencyStatus($rows,[1=>['ledger'=>$reviewed,'narrative_digest'=>$reviewed['digest']]]);
        self::assertTrue($actual[1]['needs_review'],'Actual fact change must propagate even if not retrieved');
        $actual=Continuity::dependencyStatus($rows,[1=>['ledger'=>$first,'narrative_digest'=>'unreviewed-edit']]);
        self::assertTrue($actual[0]['needs_review']); self::assertTrue($actual[1]['needs_review']);
    }

    public function testResumeSkipsAcceptedBlocksAndInvalidatesAfterSourceEdit(): void
    {
        $plan = ['subjects'=>[], 'locations'=>[], 'storyboard'=>array_map(static fn($i)=>['shot_id'=>'s'.$i,'visual_description'=>'雨落在街道'], range(1,8))];
        $request = ['_input_contract_version'=>7]; $saved = [];
        $save = static function($plan,$count,$resume) use (&$saved) { $saved = ['protocol_version'=>3,'plan'=>$plan,'reviewed_shots'=>$count,'resume'=>$resume]; };
        $reply = static function($key,$input) {
            return str_contains($key,'_evidence_') ? ['checks'=>[]] : ['summary'=>'雨落在街道','changes'=>[],'hooks'=>[],'warnings'=>[]];
        };
        try {
            Review::generate($plan,$request,static function($key,$input) use ($reply) {
                if (str_starts_with($key,'review_4_')) throw new \RuntimeException('模拟进程中断',503);
                return $reply($key,$input);
            },null,$save);
            self::fail('Expected interruption');
        } catch (\RuntimeException $error) { self::assertSame(503,$error->getCode()); }
        self::assertSame(4,$saved['reviewed_shots']); $calls=[];
        $request['_execution_attempt']=2; $request['_prompt_requests']=['runtime'=>'new trace'];
        $request['_generation_recovery_version']=1;
        $run = static function($key,$input) use ($reply,&$calls) { $calls[]=$key; return $reply($key,$input); };
        $result=Review::generate($plan,$request,$run,null,null,$saved);
        self::assertCount(2,$calls); self::assertStringStartsWith('review_4_',$calls[0]);
        self::assertSame('verified',$result['_continuity']['audit_status']);
        self::assertSame($plan['storyboard'],$result['storyboard']);
        $plan['storyboard'][0]['visual_description']='雨已经停了'; $calls=[];
        Review::generate($plan,$request,$run,null,null,$saved);
        self::assertCount(4,$calls); self::assertStringStartsWith('review_0_',$calls[0]);
        $request['series_context']['continuity']['state']=['world:weather'=>'下雪']; $calls=[];
        Review::generate($saved['plan'],$request,$run,null,null,$saved);
        self::assertCount(4,$calls);
    }
}
