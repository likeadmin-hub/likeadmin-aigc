<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaSceneBatch as Batch, ShortDramaPlanningUnit as Unit,
    ShortDramaScriptGeneration as Script, ShortDramaStructuredResponse as Response};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/ShortDramaSceneBatchTest.php';

/** Synthetic task rows roll back; the provider boundary is local and never touches a real wallet. */
class ShortDramaSceneBatchPersistenceTest extends TestCase
{
    private const TENANT = 2000000740;
    private bool $transaction = false;
    private string $task;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Opt in to transactional local fixtures');
        (new \think\App())->initialize(); Db::startTrans(); $this->transaction = true;
        $this->task = 'batch_test_' . bin2hex(random_bytes(8));
        Db::name('aigc_short_drama_script_task')->insert(['tenant_id' => self::TENANT, 'user_id' => 7,
            'task_id' => $this->task, 'project_id' => 0, 'status' => 'running', 'request_json' => '{}']);
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }

    private function snapshot(): array
    {
        return ['version' => 1, 'signature' => 'same-skeleton', 'part_size' => 4, 'groups' => [[0, 1], [2, 3]]];
    }

    public function testGroupingIsFrozenAndOwnershipIsCheckedWithoutPaidUnits(): void
    {
        $first = Unit::freezeSceneBatches(self::TENANT, 7, $this->task, $this->snapshot());
        $next = $this->snapshot(); $next['part_size'] = 2; $next['groups'] = [];
        self::assertSame($first, Unit::freezeSceneBatches(self::TENANT, 7, $this->task, $next));
        $row = Db::name('aigc_short_drama_planning_unit')->where('task_id', $this->task)->find();
        self::assertSame('checkpoint', $row['status']); self::assertSame(0, (int)$row['attempt']);
        self::assertSame('{}', $row['request_json']);
        foreach ([[self::TENANT + 1, 7], [self::TENANT, 8]] as [$tenant, $user]) {
            try { Unit::freezeSceneBatches($tenant, $user, $this->task, $next); self::fail('Wrong owner'); }
            catch (\RuntimeException $error) { self::assertSame(403, $error->getCode()); }
        }
        Unit::prepareExplicitRetry(self::TENANT, 7, $this->task);
        self::assertSame($row, Db::name('aigc_short_drama_planning_unit')->where('id', $row['id'])->find());
        $next['signature'] = 'changed-skeleton';
        $this->expectExceptionCode(409);
        Unit::freezeSceneBatches(self::TENANT, 7, $this->task, $next);
    }

    public function testAlreadyStartedSingleSceneUnitsKeepTheWholeTaskOnOriginalPath(): void
    {
        Db::name('aigc_short_drama_planning_unit')->insert(['tenant_id' => self::TENANT, 'user_id' => 7,
            'task_id' => $this->task, 'unit_key' => 'v3_policy2_timed_scene_1_1', 'status' => 'received',
            'request_json' => '{"content":"original-paid-input"}', 'result_json' => '{"result":{"content":"paid-result"}}']);
        $before = Db::name('aigc_short_drama_planning_unit')->where('task_id', $this->task)->find();
        $plan = Unit::freezeSceneBatches(self::TENANT, 7, $this->task, $this->snapshot());
        self::assertSame([], $plan['groups']);
        self::assertSame($before, Db::name('aigc_short_drama_planning_unit')->where('id', $before['id'])->find());
    }

    public function testDispatchPauseAndRejectedSceneRecoveryReuseEveryReceivedPaidUnit(): void
    {
        $plan = ShortDramaSceneBatchTest::fixture(); $request = ShortDramaSceneBatchTest::request();
        $text = '{"user_prompt":"只交信A，信B在柜内，第十集揭晓"}';
        $messages = ['system_prompt' => '保留全部剧情与对白', 'content' => $text, '_stage_content' => $text];
        $calls = [];
        $provider = function ($key, $input) use ($plan, &$calls): array {
            return Unit::call(self::TENANT, 7, $this->task, 'v3_policy2_' . substr($key, 3), $input,
                static function () use ($key, $input, $plan, &$calls) {
                    $calls[] = $key;
                    if (str_contains($key, 'skeleton')) $payload = $plan;
                    else {
                        $context = json_decode(explode("\n", $input['content'], 2)[0], true, 512, JSON_THROW_ON_ERROR);
                        $specs = $context['required_shots'];
                        if (!isset($context['scene_groups'])) foreach ($specs as &$spec) $spec['scene_ref_id'] = $context['scene_beat']['scene_ref_id'];
                        unset($spec);
                        $payload = ['storyboard' => array_map(static fn($spec) => ShortDramaSceneBatchTest::shot($spec, $plan), $specs)];
                        if ($key === 'v3_timed_batch_1_2') $payload['storyboard'][1]['recommended_duration_seconds'] = 7;
                    }
                    return ['result' => ['content' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'finish_reason' => 'stop',
                        'billing' => ['user_charge_points' => 9]]];
                }, true);
        };
        $run = fn() => Script::generate($request, ['context_window' => 65536, 'max_output_tokens' => 8192], $messages,
            $provider, null, null, fn($snapshot) => Unit::freezeSceneBatches(self::TENANT, 7, $this->task, $snapshot));
        $result = null; $turns = 0;
        do {
            $before = count($calls); $turns++;
            try { $result = Unit::withinDispatch(self::TENANT, 7, $this->task, 1, $run); }
            catch (\RuntimeException $error) { self::assertSame(425, $error->getCode()); }
            self::assertLessThanOrEqual(1, count($calls) - $before);
            self::assertLessThan(5, $turns);
        } while ($result === null);
        self::assertSame(['v3_timed_skeleton_0', 'v3_timed_batch_1_2', 'v3_timed_scene_2_1'], $calls);
        self::assertSame(['s1_1', 's2_1'], array_column($result['payload']['storyboard'], 'shot_id'));
        $rows = Db::name('aigc_short_drama_planning_unit')->where('task_id', $this->task)->order('id')->select()->toArray();
        self::assertCount(4, $rows); // one non-billable checkpoint + three paid receipts
        self::assertCount(3, array_filter($rows, static fn($r) => $r['status'] === 'received' && (int)$r['attempt'] === 1));
        self::assertSame($result['payload'], $run()['payload']);
        self::assertCount(3, $calls);
        self::assertSame($rows, Db::name('aigc_short_drama_planning_unit')->where('task_id', $this->task)->order('id')->select()->toArray());
    }

    public function testPartialBatchRecoveryReplaysPersistedReceiptsAcrossDispatchPauses(): void
    {
        $plan = ShortDramaSceneBatchTest::fixture([2, 1]);
        $request = ShortDramaSceneBatchTest::request([2, 1]);
        $request['_input_contract_version'] = 20;
        $text = '{"user_prompt":"保留信件的交接过程"}';
        $messages = ['system_prompt'=>'保持剧情与对白', 'content'=>$text, '_stage_content'=>$text];
        $calls = [];
        $provider = function ($key, $input) use ($plan, &$calls): array {
            return Unit::call(self::TENANT, 7, $this->task, 'v3_policy2_' . substr($key, 3), $input,
                static function () use ($key, $input, $plan, &$calls) {
                    $calls[] = $key;
                    if (str_contains($key, 'skeleton')) $payload = $plan;
                    else {
                        $context = json_decode(explode("\n", $input['content'], 2)[0], true, 512, JSON_THROW_ON_ERROR);
                        $specs = $context['required_shots'];
                        if (!isset($context['scene_groups'])) foreach ($specs as &$spec) $spec['scene_ref_id'] = $context['scene_beat']['scene_ref_id'];
                        unset($spec);
                        $payload = ['storyboard'=>array_map(static fn($spec)=>ShortDramaSceneBatchTest::shot($spec, $plan), $specs)];
                        if ($key === 'v3_timed_batch_1_2') array_splice($payload['storyboard'], 1, 1);
                    }
                    return ['result'=>['content'=>json_encode($payload, JSON_UNESCAPED_UNICODE), 'finish_reason'=>'stop',
                        'billing'=>['user_charge_points'=>9]]];
                }, true);
        };
        $run = fn() => Script::generate($request, ['context_window'=>65536, 'max_output_tokens'=>8192], $messages,
            $provider, null, null, fn($snapshot)=>Unit::freezeSceneBatches(self::TENANT, 7, $this->task, $snapshot));
        $result = null; $turns = 0;
        do {
            $before = count($calls); $turns++;
            try { $result = Unit::withinDispatch(self::TENANT, 7, $this->task, 1, $run); }
            catch (\RuntimeException $error) { self::assertSame(425, $error->getCode()); }
            self::assertLessThanOrEqual(1, count($calls) - $before);
            self::assertLessThan(6, $turns);
        } while ($result === null);
        self::assertSame(['v3_timed_skeleton_0', 'v3_timed_batch_1_2', 'v3_timed_scene_1_1_missing_2'], $calls);
        self::assertSame(['s1_1','s1_2','s2_1'], array_column($result['payload']['storyboard'], 'shot_id'));
        $rows = Db::name('aigc_short_drama_planning_unit')->where('task_id', $this->task)->order('id')->select()->toArray();
        self::assertCount(4, $rows);
        $receipts = array_column($rows, 'result_json', 'unit_key');
        self::assertSame($result['payload'], $run()['payload']);
        self::assertCount(3, $calls);
        self::assertSame($receipts, Db::name('aigc_short_drama_planning_unit')->where('task_id', $this->task)->column('result_json', 'unit_key'));
    }

    public function testReviewProviderFailureLeavesTenantScopedDraftCheckpoint(): void
    {
        $plan=ShortDramaSceneBatchTest::fixture([1,1]);
        $plan['storyboard']=[ShortDramaSceneBatchTest::shot(['shot_id'=>'s1_1','scene_ref_id'=>'l1','duration_seconds'=>6],$plan)];
        $request=ShortDramaSceneBatchTest::request([1,1]);$request['_input_contract_version']=21;
        $checkpoint=\app\common\service\app\aigc_short_drama\ShortDramaReviewCheckpoint::class;
        $review=\app\common\service\app\aigc_short_drama\ShortDramaEpisodeReview::class;
        try {
            $review::generate($plan,$request,static function(){throw new \RuntimeException('provider unavailable',503);},null,
                function($draft,$reviewed,$resume)use($checkpoint){$checkpoint::save(self::TENANT,7,$this->task,$draft,$reviewed,$resume);});
            self::fail('Provider error must remain visible');
        } catch(\RuntimeException $error){self::assertSame(503,$error->getCode());}
        $saved=$checkpoint::load(self::TENANT,7,$this->task);
        self::assertSame('draft',$saved['content_status']);self::assertSame($plan,$saved['plan']);
        self::assertSame([],$checkpoint::load(self::TENANT+1,7,$this->task));
        self::assertSame([],$checkpoint::load(self::TENANT,8,$this->task));
        self::assertSame('running',Db::name('aigc_short_drama_script_task')->where('task_id',$this->task)->value('status'));
    }

    public function testFieldPatchRetriesAndDispatchRecoveryReusePaidReceipts(): void
    {
        $plan=ShortDramaSceneBatchTest::fixture([2,1]);$request=ShortDramaSceneBatchTest::request([2,1]);$request['_input_contract_version']=21;
        $text='{"user_prompt":"保留信件的交接过程"}';$messages=['system_prompt'=>'保持剧情与对白','content'=>$text,'_stage_content'=>$text];$calls=[];
        $provider=function($key,$input)use($plan,&$calls):array{
            return Unit::call(self::TENANT,7,$this->task,'v3_policy2_'.substr($key,3),$input,static function()use($key,$input,$plan,&$calls){
                $calls[]=$key;
                if(str_contains($key,'skeleton'))$payload=$plan;
                elseif(str_ends_with($key,'_fields_v1'))$payload=['patches'=>[]];
                elseif(str_ends_with($key,'_fields_v1_repair'))$payload=['patches'=>[['shot_id'=>'s1_2','fields'=>['visual_description'=>'甲把信交给乙。']]]];
                elseif(str_contains($key,'timed_batch')){
                    $context=json_decode(explode("\n", $input['content'], 2)[0], true, 512, JSON_THROW_ON_ERROR);
                    $shots=array_map(static fn($spec)=>ShortDramaSceneBatchTest::shot($spec,$plan),$context['required_shots']);
                    $shots[1]['visual_description']='';$payload=['storyboard'=>$shots];
                } else throw new \RuntimeException('Unexpected unit '.$key);
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop','billing'=>['user_charge_points'=>9]]];
            },true);
        };
        $run=fn()=>Script::generate($request,['context_window'=>65536,'max_output_tokens'=>8192],$messages,$provider,null,null,
            fn($snapshot)=>Unit::freezeSceneBatches(self::TENANT,7,$this->task,$snapshot));
        $result=null;$turns=0;
        do {
            $before=count($calls);$turns++;
            try {$result=Unit::withinDispatch(self::TENANT,7,$this->task,1,$run);}
            catch(\RuntimeException $error){self::assertSame(425,$error->getCode());}
            self::assertLessThanOrEqual(1,count($calls)-$before);self::assertLessThan(7,$turns);
        } while($result===null);
        self::assertSame(['v3_timed_skeleton_0','v3_timed_batch_1_2','v3_timed_scene_1_1_fields_v1','v3_timed_scene_1_1_fields_v1_repair'],$calls);
        self::assertSame('甲把信交给乙。',$result['payload']['storyboard'][1]['visual_description']);
        $receipts=Db::name('aigc_short_drama_planning_unit')->where('task_id',$this->task)->column('result_json','unit_key');
        self::assertCount(5,$receipts);self::assertSame($result['payload'],$run()['payload']);self::assertCount(4,$calls);
        self::assertSame($receipts,Db::name('aigc_short_drama_planning_unit')->where('task_id',$this->task)->column('result_json','unit_key'));
    }

    public function testMixedPartialRecoveryResumesAllReceiptsWithoutResubmission(): void
    {
        $plan=ShortDramaSceneBatchTest::fixture([2,1]);$request=ShortDramaSceneBatchTest::request([2,1]);$request['_input_contract_version']=22;
        $text='{"user_prompt":"保留信件的交接过程"}';$messages=['system_prompt'=>'保持剧情与对白','content'=>$text,'_stage_content'=>$text];$calls=[];
        $provider=function($key,$input)use($plan,&$calls):array{
            return Unit::call(self::TENANT,7,$this->task,'v3_policy2_'.substr($key,3),$input,static function()use($key,$input,$plan,&$calls){
                $calls[]=$key;
                if(str_contains($key,'skeleton'))$payload=$plan;
                elseif(str_ends_with($key,'_partial_fields_v1'))$payload=['patches'=>[['shot_id'=>'s1_1','fields'=>['visual_description'=>'甲拿着信。']]]];
                elseif(str_contains($key,'timed_batch')){
                    $context=json_decode(explode("\n", $input['content'], 2)[0], true, 512, JSON_THROW_ON_ERROR);
                    $shots=array_map(static fn($spec)=>ShortDramaSceneBatchTest::shot($spec,$plan),$context['required_shots']);
                    $shots[0]['visual_description']='';$payload=['storyboard'=>[$shots[0],$shots[2]]];
                } elseif(str_contains($key,'_missing_')) {
                    $context=json_decode(explode("\n", $input['content'], 2)[0], true, 512, JSON_THROW_ON_ERROR);
                    $spec=$context['required_shots'][0];$spec['scene_ref_id']=$context['scene_beat']['scene_ref_id'];
                    $payload=['storyboard'=>[ShortDramaSceneBatchTest::shot($spec,$plan)]];
                } else throw new \RuntimeException('Unexpected unit '.$key);
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop','billing'=>['user_charge_points'=>9]]];
            },true);
        };
        $run=fn()=>Script::generate($request,['context_window'=>65536,'max_output_tokens'=>8192],$messages,$provider,null,null,
            fn($snapshot)=>Unit::freezeSceneBatches(self::TENANT,7,$this->task,$snapshot));
        $result=null;$turns=0;
        do {
            $before=count($calls);$turns++;
            try {$result=Unit::withinDispatch(self::TENANT,7,$this->task,1,$run);}
            catch(\RuntimeException $error){self::assertSame(425,$error->getCode());}
            self::assertLessThanOrEqual(1,count($calls)-$before);self::assertLessThan(7,$turns);
        } while($result===null);
        self::assertSame(['v3_timed_skeleton_0','v3_timed_batch_1_2','v3_timed_scene_1_1_partial_fields_v1','v3_timed_scene_1_1_missing_2'],$calls);
        self::assertSame(['s1_1','s1_2','s2_1'],array_column($result['payload']['storyboard'],'shot_id'));
        $receipts=Db::name('aigc_short_drama_planning_unit')->where('task_id',$this->task)->column('result_json','unit_key');
        self::assertCount(5,$receipts);self::assertSame($result['payload'],$run()['payload']);self::assertCount(4,$calls);
        self::assertSame($receipts,Db::name('aigc_short_drama_planning_unit')->where('task_id',$this->task)->column('result_json','unit_key'));
    }

    public function testExplicitRetrySafelyRecoversLegacyTruncatedReceipt(): void
    {
        $plan=ShortDramaSceneBatchTest::fixture([3]);$request=ShortDramaSceneBatchTest::request([3]);$request['_input_contract_version']=20;
        Db::name('aigc_short_drama_script_task')->where('task_id',$this->task)->update(['request_json'=>json_encode($request)]);
        $text='{"user_prompt":"保留信件的交接过程"}';$messages=['system_prompt'=>'保持剧情与对白','content'=>$text,'_stage_content'=>$text];$calls=[];
        $provider=function($key,$input)use($plan,&$calls):array{
            return Unit::call(self::TENANT,7,$this->task,'v3_policy2_'.substr($key,3),$input,static function()use($key,$input,$plan,&$calls){
                $calls[]=$key;
                if(str_contains($key,'skeleton'))$result=['content'=>json_encode($plan,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop'];
                else {
                    $context=json_decode(explode("\n", $input['content'], 2)[0], true, 512, JSON_THROW_ON_ERROR);$specs=$context['required_shots'];
                    foreach($specs as &$spec)$spec['scene_ref_id']=$context['scene_beat']['scene_ref_id'];unset($spec);
                    $shots=array_map(static fn($spec)=>ShortDramaSceneBatchTest::shot($spec,$plan),$specs);
                    if($key==='v3_timed_scene_1_1')$result=['content'=>'{"storyboard":['.json_encode($shots[0],JSON_UNESCAPED_UNICODE).',{"shot_id":"s1_2","dialogue":"截断','finish_reason'=>'length'];
                    elseif(str_contains($key,'_missing_'))$result=['content'=>json_encode(['storyboard'=>$shots],JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop'];
                    else throw new \RuntimeException('Unexpected unit '.$key);
                }
                return ['result'=>$result];
            },true);
        };
        $run=fn()=>Script::generate($request,['context_window'=>65536,'max_output_tokens'=>8192],$messages,$provider,null,null,
            fn($snapshot)=>Unit::freezeSceneBatches(self::TENANT,7,$this->task,$snapshot));
        for($turn=0;$turn<2;$turn++) {
            try {Unit::withinDispatch(self::TENANT,7,$this->task,1,$run);self::fail('Dispatch must pause');}
            catch(\RuntimeException $error){self::assertSame(425,$error->getCode());}
        }
        $scope=['tenant_id'=>self::TENANT,'user_id'=>7,'task_id'=>$this->task,'unit_key'=>'v3_policy2_timed_scene_1_1'];
        $before=Db::name('aigc_short_drama_planning_unit')->where($scope)->find();
        self::assertSame('superseded:continued with a smaller or repaired unit', $before['error']);
        Db::name('aigc_short_drama_script_task')->where('task_id',$this->task)->update(['status'=>'failed']);
        Unit::prepareExplicitRetry(self::TENANT,7,$this->task);
        $after=Db::name('aigc_short_drama_planning_unit')->where($scope)->find();
        self::assertSame($before['request_json'],$after['request_json']);self::assertSame($before['result_json'],$after['result_json']);
        self::assertCount(1,Db::name('aigc_short_drama_planning_unit')->where('task_id',$this->task)->whereLike('unit_key','checkpoint_legacy_recovery_%')->select()->toArray());
        $result=null;$turns=0;
        do {
            try {$result=Unit::withinDispatch(self::TENANT,7,$this->task,1,$run);}
            catch(\RuntimeException $error){self::assertSame(425,$error->getCode());}
            self::assertLessThan(5,++$turns);
        } while($result===null);
        self::assertSame(['s1_1','s1_2','s1_3'],array_column($result['payload']['storyboard'],'shot_id'));
        self::assertSame(['v3_timed_skeleton_0','v3_timed_scene_1_1','v3_timed_scene_1_1_missing_2','v3_timed_scene_1_1_missing_3'],$calls);
        Unit::prepareExplicitRetry(self::TENANT,7,$this->task);
        self::assertCount(0,Db::name('aigc_short_drama_planning_unit')->where('task_id',$this->task)->whereLike('unit_key','v3_policy2_timed_scene_1_1_retry_%')->select()->toArray());
        self::assertSame($result['payload'],$run()['payload']);self::assertCount(4,$calls);
    }

    public function testLegacyRecoveryDoesNotChangePathWhenLaterPaidUnitExists(): void
    {
        $plan=ShortDramaSceneBatchTest::fixture([2]);$request=ShortDramaSceneBatchTest::request([2]);$request['_input_contract_version']=20;
        Db::name('aigc_short_drama_script_task')->where('task_id',$this->task)->update(['status'=>'failed','request_json'=>json_encode($request)]);
        $specs=[['shot_id'=>'s1_1','duration_seconds'=>6],['shot_id'=>'s1_2','duration_seconds'=>6]];
        $input=['content'=>json_encode(['locked_plan'=>$plan,'scene_beat'=>$plan['scene_beats'][0],'required_shots'=>$specs],JSON_UNESCAPED_UNICODE)];
        $shot=ShortDramaSceneBatchTest::shot($specs[0]+['scene_ref_id'=>'l1'],$plan);
        $receipt=['result'=>['content'=>'{"storyboard":['.json_encode($shot,JSON_UNESCAPED_UNICODE).',{"shot_id":"s1_2","dialogue":"截断','finish_reason'=>'length']];
        $scope=['tenant_id'=>self::TENANT,'user_id'=>7,'task_id'=>$this->task];
        Db::name('aigc_short_drama_planning_unit')->insert($scope+['unit_key'=>'v3_policy2_timed_scene_1_1','status'=>'received',
            'attempt'=>1,'request_json'=>json_encode($input,JSON_UNESCAPED_UNICODE),'result_json'=>json_encode($receipt,JSON_UNESCAPED_UNICODE),'error'=>'validation:长度上限']);
        Db::name('aigc_short_drama_planning_unit')->insert($scope+['unit_key'=>'v3_policy2_timed_scene_1_1_split_1','status'=>'received',
            'attempt'=>1,'request_json'=>'{}','result_json'=>'{}']);
        Unit::prepareExplicitRetry(self::TENANT,7,$this->task);
        self::assertCount(0,Db::name('aigc_short_drama_planning_unit')->where($scope)->whereLike('unit_key','checkpoint_legacy_recovery_%')->select()->toArray());
        self::assertCount(1,Db::name('aigc_short_drama_planning_unit')->where($scope)->whereLike('unit_key','v3_policy2_timed_scene_1_1_retry_1_%')->select()->toArray());
    }

    public function testSkeletonFieldRepairResumesWithoutRepeatingReceivedUnits(): void
    {
        $plan=ShortDramaSceneBatchTest::fixture([2]);$plan['scene_beats'][0]['goal']='';$plan['scene_beats'][0]['scene_name']='错误地点';
        $request=ShortDramaSceneBatchTest::request([2]);$request['_input_contract_version']=23;
        $text='{"user_prompt":"保留信件"}';$messages=['system_prompt'=>'保持剧情与对白','content'=>$text,'_stage_content'=>$text];$calls=[];
        $provider=function($key,$input)use($plan,&$calls):array{
            return Unit::call(self::TENANT,7,$this->task,'v3_policy2_'.substr($key,3),$input,static function()use($key,$input,$plan,&$calls){
                $calls[]=$key;
                if(str_ends_with($key,'skeleton_fields_v1'))$payload=['patches'=>[['scene_index'=>0,'fields'=>['goal'=>'完成事件1','scene_name'=>'地点1']]]];
                elseif(str_contains($key,'skeleton'))$payload=$plan;
                else {
                    $context=json_decode(explode("\n", $input['content'], 2)[0], true, 512, JSON_THROW_ON_ERROR);$specs=$context['required_shots'];
                    foreach($specs as &$spec)$spec['scene_ref_id']=$context['scene_beat']['scene_ref_id'];unset($spec);
                    $payload=['storyboard'=>array_map(static fn($spec)=>ShortDramaSceneBatchTest::shot($spec,$plan),$specs)];
                }
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
            },true);
        };
        $run=fn()=>Script::generate($request,['context_window'=>65536,'max_output_tokens'=>8192],$messages,$provider,null,null,
            fn($snapshot)=>Unit::freezeSceneBatches(self::TENANT,7,$this->task,$snapshot));
        $result=null;$turns=0;
        do {
            $before=count($calls);
            try {$result=Unit::withinDispatch(self::TENANT,7,$this->task,1,$run);}
            catch(\RuntimeException $error){self::assertSame(425,$error->getCode());}
            self::assertLessThanOrEqual(1,count($calls)-$before);self::assertLessThan(6,++$turns);
        } while($result===null);
        self::assertSame(['v3_timed_skeleton_0','v3_timed_skeleton_fields_v1','v3_timed_scene_1_1'],$calls);
        self::assertSame('完成事件1',$result['payload']['scene_beats'][0]['goal']);
        $receipts=Db::name('aigc_short_drama_planning_unit')->where('task_id',$this->task)->column('result_json','unit_key');
        self::assertSame($result['payload'],$run()['payload']);self::assertCount(3,$calls);
        self::assertSame($receipts,Db::name('aigc_short_drama_planning_unit')->where('task_id',$this->task)->column('result_json','unit_key'));
    }

    /** @dataProvider ineligibleLegacyReceipts */
    public function testLegacyRecoveryDoesNotAdoptUnsupportedOrAmbiguousReceipts(int $version, bool $duplicate): void
    {
        $plan=ShortDramaSceneBatchTest::fixture([3]);$request=ShortDramaSceneBatchTest::request([3]);$request['_input_contract_version']=$version;
        Db::name('aigc_short_drama_script_task')->where('task_id',$this->task)->update(['status'=>'failed','request_json'=>json_encode($request)]);
        $specs=[['shot_id'=>'s1_1','duration_seconds'=>6],['shot_id'=>'s1_2','duration_seconds'=>6],['shot_id'=>'s1_3','duration_seconds'=>6]];
        $input=['content'=>json_encode(['locked_plan'=>$plan,'scene_beat'=>$plan['scene_beats'][0],'required_shots'=>$specs],JSON_UNESCAPED_UNICODE)];
        $shot=ShortDramaSceneBatchTest::shot($specs[0]+['scene_ref_id'=>'l1'],$plan);$encoded=json_encode($shot,JSON_UNESCAPED_UNICODE);
        $receipt=['result'=>['content'=>'{"storyboard":['.$encoded.($duplicate ? ','.$encoded : '').',{"shot_id":"unfinished','finish_reason'=>'length']];
        $scope=['tenant_id'=>self::TENANT,'user_id'=>7,'task_id'=>$this->task];
        Db::name('aigc_short_drama_planning_unit')->insert($scope+['unit_key'=>'v3_policy2_timed_scene_1_1','status'=>'received',
            'attempt'=>1,'request_json'=>json_encode($input,JSON_UNESCAPED_UNICODE),'result_json'=>json_encode($receipt,JSON_UNESCAPED_UNICODE),'error'=>'validation:长度上限']);
        Unit::prepareExplicitRetry(self::TENANT,7,$this->task);
        self::assertCount(0,Db::name('aigc_short_drama_planning_unit')->where($scope)->whereLike('unit_key','checkpoint_legacy_recovery_%')->select()->toArray());
        self::assertCount(1,Db::name('aigc_short_drama_planning_unit')->where($scope)->whereLike('unit_key','v3_policy2_timed_scene_1_1_retry_1_%')->select()->toArray());
    }
    public static function ineligibleLegacyReceipts(): array { return [[19,false],[20,true]]; }

    public function testLongScriptContinuesAcrossDispatchesWithoutReplayingPaidCalls(): void
    {
        $counts=array_fill(0,24,9);$plan=ShortDramaSceneBatchTest::fixture($counts);
        $request=ShortDramaSceneBatchTest::request($counts);$request['_input_contract_version']=24;$request['_prompt_task_id']=$this->task;
        $text='{"user_prompt":"按顺序保留全部场景"}';$messages=['system_prompt'=>'保持剧情与对白','content'=>$text,'_stage_content'=>$text];$calls=[];
        $provider=function($key,$input)use($plan,&$calls):array{
            return Unit::call(self::TENANT,7,$this->task,'v3_policy2_'.substr($key,3),$input,static function()use($key,$input,$plan,&$calls){
                $calls[]=$key;
                if(str_contains($key,'skeleton'))$payload=$plan;
                else {
                    $context=json_decode(explode("\n", $input['content'], 2)[0], true, 512, JSON_THROW_ON_ERROR);$specs=$context['required_shots'];
                    foreach($specs as &$spec)$spec['scene_ref_id']=$context['scene_beat']['scene_ref_id'];unset($spec);
                    $payload=['storyboard'=>array_map(static fn($spec)=>ShortDramaSceneBatchTest::shot($spec,$plan),$specs)];
                }
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
            },true);
        };
        $run=fn()=>Script::generate($request,['context_window'=>65536,'max_output_tokens'=>8192],$messages,$provider,null,null,
            fn($snapshot)=>Unit::freezeSceneBatches(self::TENANT,7,$this->task,$snapshot));
        $result=null;$turns=0;
        do {
            $before=count($calls);
            try {$result=Unit::withinDispatch(self::TENANT,7,$this->task,4,$run);}
            catch(\RuntimeException $error){self::assertSame(425,$error->getCode());}
            self::assertLessThanOrEqual(4,count($calls)-$before);self::assertLessThan(30,++$turns);
        } while($result===null);
        self::assertCount(216,$result['payload']['storyboard']);
        self::assertGreaterThan(48,count($calls));
        self::assertCount(count(array_unique($calls)),$calls);
        $count=count($calls);self::assertSame($result['payload'],Unit::withinDispatch(self::TENANT,7,$this->task,4,$run)['payload']);self::assertCount($count,$calls);
        $legacy=array_replace($request,['_input_contract_version'=>23,'_generation_version'=>3]);
        $oldRun=fn()=>Script::generate($legacy,['context_window'=>65536,'max_output_tokens'=>8192],$messages,$provider,null,null,
            fn($snapshot)=>Unit::freezeSceneBatches(self::TENANT,7,$this->task,$snapshot));
        self::assertSame($result['payload'], Unit::withinDispatch(self::TENANT,7,$this->task,4,$oldRun)['payload']);
        self::assertCount($count,$calls);
        $resumed=\app\common\service\app\aigc_short_drama\ShortDramaInputContract::resumeAfterLimit($legacy,'本集已达到自动处理上限，已保留完成内容，请缩小生成范围');
        self::assertSame(\app\common\service\app\aigc_short_drama\ShortDramaPlanningContext::templateRequest($legacy),
            \app\common\service\app\aigc_short_drama\ShortDramaPlanningContext::templateRequest($resumed));
        $resumedRun=fn()=>Script::generate($resumed,['context_window'=>65536,'max_output_tokens'=>8192],$messages,$provider,null,null,
            fn($snapshot)=>Unit::freezeSceneBatches(self::TENANT,7,$this->task,$snapshot));
        self::assertSame($result['payload'],Unit::withinDispatch(self::TENANT,7,$this->task,4,$resumedRun)['payload']);
        self::assertCount($count,$calls);
    }

    public function testLegacyLongScriptContinuesPastFormerLimitWithoutResubmittingReceipts(): void
    {
        $counts=array_fill(0,24,9);$plan=ShortDramaSceneBatchTest::fixture($counts);
        $request=ShortDramaSceneBatchTest::request($counts);$request['_input_contract_version']=23;
        $request['_generation_version']=3;$request['_prompt_task_id']=$this->task;
        $text='{"user_prompt":"按顺序保留全部场景"}';$messages=['system_prompt'=>'保持剧情与对白','content'=>$text,'_stage_content'=>$text];$calls=[];
        $provider=function($key,$input)use($plan,&$calls):array{
            return Unit::call(self::TENANT,7,$this->task,'v3_policy2_'.substr($key,3),$input,static function()use($key,$input,$plan,&$calls){
                $calls[]=$key;
                if(str_contains($key,'skeleton'))$payload=$plan;
                else {
                    $context=json_decode(explode("\n", $input['content'], 2)[0], true, 512, JSON_THROW_ON_ERROR);$specs=$context['required_shots'];
                    foreach($specs as &$spec)$spec['scene_ref_id']=$context['scene_beat']['scene_ref_id'];unset($spec);
                    $payload=['storyboard'=>array_map(static fn($spec)=>ShortDramaSceneBatchTest::shot($spec,$plan),$specs)];
                }
                return ['result'=>['content'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'finish_reason'=>'stop']];
            },true);
        };
        $run=fn($input)=>Script::generate($input,['context_window'=>65536,'max_output_tokens'=>8192],$messages,$provider,null,null,
            fn($snapshot)=>Unit::freezeSceneBatches(self::TENANT,7,$this->task,$snapshot));
        // Legacy receipts also continue past the removed business budget. Yielding
        // is a recoverable dispatch boundary, never a terminal creation failure.
        $partial=null;
        for($turn=0;$turn<20 && count($calls)<=48;$turn++) {
            try {$partial=Unit::withinDispatch(self::TENANT,7,$this->task,4,fn()=>$run($request));}
            catch(\RuntimeException $error){self::assertSame(425,$error->getCode());}
        }
        self::assertNull($partial);self::assertGreaterThan(48,count($calls));$receivedBefore=$calls;
        $request=\app\common\service\app\aigc_short_drama\ShortDramaInputContract::resumeAfterLimit($request,'本集已达到自动处理上限，已保留完成内容，请缩小生成范围');
        $result=null;$turn=0;
        do {
            try {$result=Unit::withinDispatch(self::TENANT,7,$this->task,4,fn()=>$run($request));}
            catch(\RuntimeException $error){self::assertSame(425,$error->getCode());}
            self::assertLessThan(25,++$turn);
        } while($result===null);
        self::assertCount(216,$result['payload']['storyboard']);self::assertGreaterThan(count($receivedBefore),count($calls));
        self::assertCount(count(array_unique($calls)),$calls);
        self::assertSame($receivedBefore,array_slice($calls,0,count($receivedBefore)));
    }
}
