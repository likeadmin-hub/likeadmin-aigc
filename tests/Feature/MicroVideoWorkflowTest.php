<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\app\aigc_micro_video\MicroVideoService as V;
use app\common\service\app\aigc_micro_video\MicroVideoStore as S;
use app\common\service\app\aigc_micro_video\MicroVideoSettings as C;

/** Local transactional admission tests: no provider calls, no balance mutation. */
final class MicroVideoWorkflowTest extends TestCase
{
    private int $id;
    protected function setUp(): void
    {
        (new \think\App(dirname(__DIR__,2).'/'))->initialize();$db=config('database.connections.mysql');
        if($db['hostname']!=='127.0.0.1'||$db['database']!=='z_cn')self::markTestSkipped('Local transactional acceptance only');
        $template=S::table('task')->where('tenant_id',1)->where('user_id',1)->order('id')->find();if(!$template)self::markTestSkipped('Owned local acceptance draft required');
        Db::startTrans();unset($template['id']);$template=array_replace($template,['idempotency_key'=>'test_'.bin2hex(random_bytes(12)),'status'=>'awaiting_confirmation','plan_revision'=>1,'quote_token'=>'','quote_json'=>'{}','confirmed_quote'=>'{}','submit_key'=>'','resume_key'=>'','lease_token'=>'','lease_until'=>0,'delete_time'=>0]);$this->id=S::table('task')->insertGetId($template);
        foreach([1,2] as $sort)S::table('segment')->insert(['tenant_id'=>1,'user_id'=>1,'task_id'=>$this->id,'sort'=>$sort,'title'=>'测试镜头'.$sort,'duration'=>6,'prompt'=>'同一红色保温瓶，灰色背景与柔和光线。','image_indices'=>'[0]','start_state'=>'商品全景','end_state'=>'商品细节','continuity'=>'保持商品形状颜色与光线','status'=>'pending','create_time'=>time(),'update_time'=>time()]);
    }
    protected function tearDown(): void{Db::rollback();}
    private function rejection(callable $f): void{try{$f();}catch(\InvalidArgumentException $e){self::assertNotSame('',$e->getMessage());return;}self::fail('Operation should reject');}
    private function quote(): array{return V::estimate(1,1,['id'=>$this->id,'plan_revision'=>1]);}
    public function testUnrestrictedDirectionCanEstimateWithoutSelectingACard(): void
    {
        $form=S::json(S::owned(1,1,$this->id)['params_json']);$form['direction_id']=0;$models=\app\common\service\app\aigc_micro_video\MicroVideoCatalog::models(1);if(!$models)self::markTestSkipped('Callable v2 video model required');$form['model_id']=$models[0]['id'];$form['quality']=$models[0]['specs'][0]['quality'];$form['ratio']=$models[0]['specs'][0]['ratio'];$config=C::get(1);$config['video_model_ids'][]=$form['model_id'];S::table('config')->where('tenant_id',1)->update(['config_json'=>S::encode($config)]);
        $direction=C::direction(1,0);self::assertSame(0,$direction['id']);self::assertSame('free',$direction['code']);
        $before=Db::name('ai_consumption_log')->count();$quote=V::estimate(1,1,$form);
        self::assertTrue($quote['approximate']);self::assertGreaterThan(0,$quote['user_charge_points']);self::assertSame($before,Db::name('ai_consumption_log')->count());
    }
    public function testQuoteAndSubmitAreVersionBoundAndIdempotent(): void
    {
        $before=Db::name('ai_consumption_log')->count();$q=$this->quote();self::assertSame(60.0,(float)$q['user_charge_points']);
        $p=['id'=>$this->id,'plan_revision'=>1,'quote_token'=>$q['quote_token'],'idempotency_key'=>'submit_'.bin2hex(random_bytes(10))];
        $a=V::submit(1,1,$p);$b=V::submit(1,1,$p);self::assertSame('running',$a['status']);self::assertSame($a['id'],$b['id']);self::assertSame([],$a['segments']);self::assertSame($before,Db::name('ai_consumption_log')->count());self::assertCount(2,S::segments($this->id));
    }
    public function testOlderQuoteAndCrossUserAccessCannotSubmit(): void
    {
        $old=$this->quote();$new=$this->quote();self::assertNotSame($old['quote_token'],$new['quote_token']);
        $this->rejection(fn()=>V::submit(1,1,['id'=>$this->id,'plan_revision'=>1,'quote_token'=>$old['quote_token'],'idempotency_key'=>'old_quote_test']));
        try{V::detail(1,2,$this->id);self::fail('Cross-user detail exposed');}catch(\RuntimeException $e){self::assertStringContainsString('无权',$e->getMessage());}
        $this->rejection(fn()=>V::submit(1,1,['id'=>$this->id,'plan_revision'=>99,'quote_token'=>$new['quote_token'],'idempotency_key'=>'wrong_revision']));
    }
    public function testRetryOnlyQueuesFailedSegmentAndKeepsSuccessfulReceipt(): void
    {
        $segments=S::segments($this->id);S::table('segment')->where('id',$segments[0]['id'])->update(['status'=>'success','attempt_no'=>1,'attempt_id'=>987,'video_uri'=>'uploads/kept-success.mp4']);S::table('segment')->where('id',$segments[1]['id'])->update(['status'=>'failed','error'=>'fixture unavailable']);S::update($this->id,['status'=>'waiting_continue']);
        $first=S::segments($this->id)[0];$q=$this->quote();self::assertSame(30.0,(float)$q['user_charge_points']);$p=['id'=>$this->id,'quote_token'=>$q['quote_token'],'idempotency_key'=>'retry_only_failed'];V::retry(1,1,$p);V::retry(1,1,$p);
        $after=S::segments($this->id);self::assertSame($first,$after[0]);self::assertSame('pending',$after[1]['status']);self::assertSame(0,S::table('attempt')->where('task_id',$this->id)->count());self::assertSame('running',V::detail(1,1,$this->id)['status']);
    }
    public function testMergeOnlyRetryCostsZeroAndNeverQueuesSuccessfulSegments(): void
    {
        S::table('segment')->where('task_id',$this->id)->update(['status'=>'success','attempt_no'=>1]);S::update($this->id,['status'=>'waiting_continue','merge_status'=>'failed']);$before=S::segments($this->id);$q=$this->quote();self::assertSame(0.0,(float)$q['user_charge_points']);V::retry(1,1,['id'=>$this->id,'quote_token'=>$q['quote_token'],'idempotency_key'=>'merge_only_resume']);self::assertSame($before,S::segments($this->id));
    }
    public function testPublicConfigAndGenerationDetailsHideInternalData(): void
    {
        $c=C::detail(1);self::assertArrayNotHasKey('config',$c);self::assertArrayNotHasKey('available_models',$c);$json=json_encode($c);foreach(['platform_unit_cost','script_model_id','planning_template'] as $key)self::assertStringNotContainsString($key,$json);
        S::update($this->id,['status'=>'waiting_continue','error'=>'private-provider-host.internal']);$d=V::detail(1,1,$this->id);self::assertSame([],$d['segments']);self::assertArrayNotHasKey('error',$d);self::assertStringNotContainsString('private-provider-host.internal',json_encode($d));
    }
    public function testTimedPlanSaveRejectsGapsWithoutReplacingRowsOrCreatingCharges(): void
    {
        $row=S::owned(1,1,$this->id);$config=S::json($row['config_snapshot']);$config['shot_timeline_version']=1;S::update($this->id,['config_snapshot'=>S::encode($config)]);
        $before=S::segments($this->id);$charges=Db::name('ai_consumption_log')->count();$edited=$before;
        foreach($edited as &$segment){$segment['image_indices']=[0];$segment['prompt']="0-2秒：全景。\n3-6秒：商品细节。";}unset($segment);
        try{V::savePlan(1,1,['id'=>$this->id,'plan_revision'=>1,'segments'=>$edited]);self::fail('Gap was saved');}catch(\InvalidArgumentException $e){self::assertStringContainsString('镜头时间轴无效',$e->getMessage());}
        self::assertSame($before,S::segments($this->id));self::assertSame($charges,Db::name('ai_consumption_log')->count());
        foreach($edited as &$segment)$segment['prompt']="0-3秒：全景缓慢推近。\n3-6秒：承接推进到商品细节。";unset($segment);
        $saved=V::savePlan(1,1,['id'=>$this->id,'plan_revision'=>1,'segments'=>$edited]);self::assertSame(2,(int)$saved['plan_revision']);
        $method=new \ReflectionMethod(\app\common\service\app\aigc_micro_video\MicroVideoWorker::class,'segmentPrompt');$method->setAccessible(true);
        $segments=S::segments($this->id);$prompt=$method->invoke(null,S::owned(1,1,$this->id),$segments[1]);
        self::assertStringContainsString('总时长6秒',$prompt);self::assertStringContainsString("0-3秒：全景缓慢推近。\n3-6秒：承接推进到商品细节。",$prompt);self::assertStringContainsString('前段结束：',$prompt);self::assertSame($charges,Db::name('ai_consumption_log')->count());
    }

    public function testRejectedCompleteScriptRetainsReceiptAndAllowsExplicitRetry(): void
    {
        $task=S::owned(1,1,$this->id);$result=S::encode(['content'=>'没有镜头时间轴的正文','usage'=>['total_tokens'=>40]]);
        $id=S::table('attempt')->insertGetId(['tenant_id'=>1,'user_id'=>1,'task_id'=>$this->id,'segment_id'=>0,'kind'=>'script','attempt_no'=>1,'status'=>'success','request_json'=>'{}','result_json'=>$result,'create_time'=>time(),'update_time'=>time()]);
        $count=Db::name('ai_consumption_log')->count();
        try{\app\common\service\app\aigc_micro_video\MicroVideoText::run($task,'script','','','',[],fn($r)=>\app\common\service\app\aigc_micro_video\MicroVideoTimeline::validate($r['content'],15));self::fail('Invalid cached script escaped validation');}catch(\InvalidArgumentException $e){self::assertStringContainsString('镜头时间轴无效',$e->getMessage());}
        $attempt=S::table('attempt')->where('id',$id)->find();self::assertSame('validation_failed',$attempt['status']);self::assertSame($result,$attempt['result_json']);
        try{\app\common\service\app\aigc_micro_video\MicroVideoText::run($task,'script','','','');self::fail('Worker restart must not regenerate rejected content');}catch(\InvalidArgumentException $e){self::assertStringContainsString('镜头时间轴无效',$e->getMessage());}
        S::table('segment')->where('task_id',$this->id)->delete();S::update($this->id,['status'=>'waiting_continue']);$quote=V::estimate(1,1,['id'=>$this->id]);
        V::retry(1,1,['id'=>$this->id,'quote_token'=>$quote['quote_token'],'idempotency_key'=>'explicit_validation_retry']);
        self::assertSame('failed',S::table('attempt')->where('id',$id)->value('status'));
        // Empty model stops before I/O; only the explicit retry authorizes a fresh call.
        try{\app\common\service\app\aigc_micro_video\MicroVideoText::run($task,'script','','','');self::fail('Invalid receipt replayed');}catch(\InvalidArgumentException $e){self::assertStringContainsString('请先配置',$e->getMessage());}
        self::assertSame($count,Db::name('ai_consumption_log')->count());self::assertSame(1,S::table('attempt')->where('task_id',$this->id)->count());
    }

    public function testLegacyFrozenQuoteSurvivesRemovalFromNewTaskWhitelist(): void
    {
        $task=S::owned(1,1,$this->id);$snapshot=S::json($task['config_snapshot']);unset($snapshot['frame_feature_version']);S::update($this->id,['config_snapshot'=>S::encode($snapshot)]);$config=C::get(1);$config['video_model_ids']=[];S::table('config')->where('tenant_id',1)->update(['config_json'=>S::encode($config)]);$q=$this->quote();self::assertSame(60.0,(float)$q['user_charge_points']);
    }

    public function testContentWorkerKeepsAnUnequalFifteenSecondStoryDespiteThirtySecondTarget(): void
    {
        $task=S::owned(1,1,$this->id);$p=S::json($task['params_json']);$p['model_id']='market_video_model:182';$p['quality']='768P';$p['ratio']='16:9';$p['mode']='prompt';$p['prompt']='整片15秒剧情带货视频，先发现商品，再拿起展示。';$p['target_duration']=30;
        $model=\app\common\service\app\aigc_micro_video\MicroVideoCatalog::model(1,$p['model_id']);
        $snapshot=S::json($task['config_snapshot']);$snapshot['content_planning_version']=1;$snapshot['shot_timeline_version']=1;
        S::update($this->id,['status'=>'planning','params_json'=>S::encode($p),'model_snapshot'=>S::encode($model),'config_snapshot'=>S::encode($snapshot),'analysis'=>'参考图展示同一商品。','next_run_at'=>0]);
        $segments=[];foreach([6,9] as $d)$segments[]=['title'=>'自然剧情','duration'=>$d,'prompt'=>"0-2秒：人物走向商品。\n2-{$d}秒：延续动作展示。",'image_indices'=>[0],'start_state'=>'站在商品旁','end_state'=>'持瓶展示','continuity'=>'同一人物与机位，动作自然承接'];
        S::table('attempt')->insert(['tenant_id'=>1,'user_id'=>1,'task_id'=>$this->id,'segment_id'=>0,'kind'=>'script','attempt_no'=>1,'status'=>'success','request_json'=>'{}','result_json'=>S::encode(['content'=>S::encode(['continuity'=>'保持同一人物与场景','segments'=>$segments])]),'create_time'=>time(),'update_time'=>time()]);
        $before=Db::name('ai_consumption_log')->count();
        \app\common\service\app\aigc_micro_video\MicroVideoWorker::tick($this->id);
        $row=S::owned(1,1,$this->id);self::assertSame('awaiting_confirmation',$row['status'],$row['error']);self::assertSame([6,9],array_column(S::segments($this->id),'duration'));self::assertSame($before,Db::name('ai_consumption_log')->count());
    }

    public function testSmartPromptAndEstimateBothHonorSelectedThirtySeconds(): void
    {
        $p=S::json(S::owned(1,1,$this->id)['params_json']);$p=array_replace($p,['model_id'=>'market_video_model:182','quality'=>'768P','ratio'=>'16:9','mode'=>'prompt','prompt'=>"整片15秒。\n0-3秒：发现商品。\n3-15秒：拿起展示。",'target_duration'=>30,'idempotency_key'=>'prompt_test_'.bin2hex(random_bytes(8))]);
        $prepared=V::form(1,1,$p);$create=new \ReflectionMethod(V::class,'createDraft');$create->setAccessible(true);
        $row=$create->invoke(null,1,1,$prepared,'prompt:'.$p['idempotency_key'],'prompt_draft');S::update((int)$row['id'],['analysis'=>'参考图为同一商品。']);
        S::table('attempt')->insert(['tenant_id'=>1,'user_id'=>1,'task_id'=>$row['id'],'segment_id'=>0,'kind'=>'prompt','attempt_no'=>1,'status'=>'success','request_json'=>'{}','result_json'=>S::encode(['content'=>"0-5秒：发现商品。\n5-12秒：拿起商品。\n12-20秒：展示细节。\n20-30秒：装包离开。"]),'create_time'=>time(),'update_time'=>time()]);
        $before=Db::name('ai_consumption_log')->count();$r=V::writePrompt(1,1,$p);self::assertStringContainsString('20-30秒',$r['prompt']);
        $q=V::estimate(1,1,$p);self::assertSame(30,$q['planned_duration']);self::assertTrue($q['approximate']);self::assertSame($before,Db::name('ai_consumption_log')->count());
    }

    /** @dataProvider targetDurations */
    public function testTargetWorkerCannotAcceptAStoryShorterThanTheSelectedDuration(array $durations,string $expected,int $version=2): void
    {
        $task=S::owned(1,1,$this->id);$p=S::json($task['params_json']);$p=array_replace($p,['model_id'=>'market_video_model:182','quality'=>'768P','ratio'=>'16:9','mode'=>'prompt','prompt'=>'原有15秒短故事，女生拿起商品。','target_duration'=>30]);
        $model=\app\common\service\app\aigc_micro_video\MicroVideoCatalog::model(1,$p['model_id']);$snapshot=S::json($task['config_snapshot']);$snapshot['content_planning_version']=$version;$snapshot['shot_timeline_version']=1;
        S::update($this->id,['status'=>'planning','params_json'=>S::encode($p),'model_snapshot'=>S::encode($model),'config_snapshot'=>S::encode($snapshot),'analysis'=>'参考图展示同一人物和商品。','next_run_at'=>0]);
        $segments=[];foreach($durations as $d)$segments[]=['title'=>'连续剧情','duration'=>$d,'prompt'=>"0-2秒：人物走向商品。\n2-{$d}秒：延续动作展示。",'image_indices'=>[0],'start_state'=>'人物站在商品旁','end_state'=>'持瓶展示','continuity'=>'同一机位，动作自然承接'];
        S::table('attempt')->insert(['tenant_id'=>1,'user_id'=>1,'task_id'=>$this->id,'segment_id'=>0,'kind'=>'script','attempt_no'=>1,'status'=>'success','request_json'=>'{}','result_json'=>S::encode(['content'=>S::encode(['continuity'=>'同一人物场景','segments'=>$segments])]),'create_time'=>time(),'update_time'=>time()]);
        $before=Db::name('ai_consumption_log')->count();\app\common\service\app\aigc_micro_video\MicroVideoWorker::tick($this->id);$row=S::owned(1,1,$this->id);self::assertSame($expected,$row['status'],$row['error']);
        if($expected==='awaiting_confirmation'){
            self::assertSame($durations,array_column(S::segments($this->id),'duration'));
            $saved=S::segments($this->id);$short=$saved;array_pop($short);foreach($short as &$segment)$segment['image_indices']=[0];unset($segment);
            $this->rejection(fn()=>V::savePlan(1,1,['id'=>$this->id,'plan_revision'=>$row['plan_revision'],'segments'=>$short]));self::assertSame($saved,S::segments($this->id));
        }else self::assertStringContainsString('目标时长',$row['error']);
        self::assertSame($before,Db::name('ai_consumption_log')->count());
    }
    public static function targetDurations(): array
    {
        return [[[8,10,12],'awaiting_confirmation'],[[10,11,12],'awaiting_confirmation'],[[6,4,5],'waiting_continue'],[[15,15],'awaiting_confirmation',3],[[6,8,7,9],'waiting_continue',3]];
    }

}
