<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\{ShortDramaEpisodeService as Episodes,ShortDramaContinuity as Continuity};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaEpisodeRecoveryTest extends TestCase
{
    private bool $transaction=false;
    private array $scope=['tenant_id'=>2000000725,'user_id'=>7];
    protected function setUp(): void
    {
        if(getenv('SHORT_DRAMA_STORY_DB_TESTS')!=='1')self::markTestSkipped('Transactional local fixtures only');
        (new \think\App())->initialize();Db::startTrans();$this->transaction=true;
        \think\facade\Env::set('short_drama.redis_host','127.0.0.1');\think\facade\Env::set('short_drama.redis_port',1);
    }
    protected function tearDown(): void { if($this->transaction)Db::rollback(); }
    private function fixture(string $status='queued'): array
    {
        $task='episode_recover_'.bin2hex(random_bytes(8));
        $p=Db::name('aigc_short_drama_project')->insertGetId($this->scope+['multi_episode'=>1,'episode_count'=>2]);
        $c=Db::name('aigc_short_drama_project')->insertGetId($this->scope+['last_task_id'=>$task,'title'=>'历史项目']);
        $ep=Db::name('aigc_short_drama_episode_task')->insertGetId($this->scope+['project_id'=>$p,'production_project_id'=>$c,
            'episode_number'=>1,'task_id'=>$task,'status'=>$status==='failed'?'failed':'pending']);
        $request=['_generation_version'=>3,'_input_contract_version'=>10,'episode_id'=>$ep,'quality_review_enabled'=>false,
            'series_context'=>['continuity'=>Continuity::context([],true),'previous_episodes'=>Continuity::context([])['recent_episodes']]];
        $plan=['title'=>'原剧本','story_outline'=>'原剧情','storyboard'=>[['shot_id'=>'1','visual_description'=>'原动作']],
            'subjects'=>[['id'=>'s','name'=>'原角色']],'locations'=>[['id'=>'l','name'=>'原场景']]];
        Db::name('aigc_short_drama_script_task')->insert($this->scope+['project_id'=>$c,'task_id'=>$task,'status'=>$status,
            'request_json'=>json_encode($request),'result_json'=>json_encode($plan),'billing_status'=>'deducted','user_charge_points'=>9]);
        Db::name('aigc_short_drama_generation_task')->insert($this->scope+['project_id'=>$c,'task_id'=>$task,
            'task_type'=>'script_plan','status'=>$status,'billing_status'=>'deducted','user_charge_points'=>9,'result_json'=>'{"old_receipt":1}']);
        $job=Db::name('aigc_short_drama_episode_job')->insertGetId($this->scope+['project_id'=>$p,'episode_id'=>$ep,
            'job_type'=>'episode','status'=>$status==='failed'?'failed':'pending','idempotency_key'=>$task,'result_json'=>'{"old_receipt":1}']);
        Db::name('aigc_short_drama_episode_task')->where('id',$ep)->update(['queue_job_id'=>$job]);
        return compact('task','p','c','ep','job','plan');
    }
    private function wait(array $f): void
    {
        Db::name('aigc_short_drama_planning_unit')->insert($this->scope+['task_id'=>$f['task'],'unit_key'=>'v3_pending',
            'status'=>'waiting','attempt'=>1,'request_json'=>'{}','update_time'=>time()]);
    }
    public function testBackoffYieldsWithoutTouchingWaitingEpisode(): void
    {
        $f=$this->fixture();$this->wait($f);$before=Db::name('aigc_short_drama_episode_task')->where('id',$f['ep'])->find();
        self::assertSame(0,Episodes::tick(2000000725,$f['p']));
        self::assertSame($before,Db::name('aigc_short_drama_episode_task')->where('id',$f['ep'])->find());
    }
    public function testBackoffOfFirstEpisodeIsNotBypassedByDueSibling(): void
    {
        $f=$this->fixture();Db::name('aigc_short_drama_episode_task')->where('id',$f['ep'])->update(['next_retry_at'=>time()+120]);
        Db::name('aigc_short_drama_episode_task')->insert($this->scope+['project_id'=>$f['p'],'episode_number'=>2,'status'=>'pending']);
        self::assertSame(0,Episodes::tick(2000000725,$f['p']));
    }
    public function testWaitingSeriesDoesNotStarveAnotherSeriesAndCompletionClosesJob(): void
    {
        $waiting=$this->fixture();$this->wait($waiting);$ready=$this->fixture('success');
        self::assertSame(1,Episodes::tick(2000000725));
        self::assertSame('pending',Db::name('aigc_short_drama_episode_task')->where('id',$waiting['ep'])->value('status'));
        self::assertSame('success',Db::name('aigc_short_drama_episode_task')->where('id',$ready['ep'])->value('status'));
        $job=Db::name('aigc_short_drama_episode_job')->where('id',$ready['job'])->find();
        self::assertSame('success',$job['status']);self::assertSame('{"old_receipt":1}',$job['result_json']);
    }
    public function testExplicitRetrySynchronizesStatesWithoutRewritingPaidHistory(): void
    {
        $f=$this->fixture('failed');$before=Db::name('aigc_short_drama_script_task')->where('task_id',$f['task'])->find();
        Db::name('aigc_short_drama_episode_task')->where('id',$f['ep'])->update(['next_retry_at'=>time()+120,'error_code'=>'old']);
        Episodes::retry(2000000725,7,$f['ep']);
        $script=Db::name('aigc_short_drama_script_task')->where('task_id',$f['task'])->find();
        $mirror=Db::name('aigc_short_drama_generation_task')->where('task_id',$f['task'])->find();
        $episode=Db::name('aigc_short_drama_episode_task')->where('id',$f['ep'])->find();
        self::assertSame(['pending','pending','pending'],[$script['status'],$mirror['status'],$episode['status']]);
        self::assertSame((int)$before['retry_count']+1,(int)$script['retry_count']);
        self::assertSame(0,(int)$episode['next_retry_at']);self::assertSame('',$episode['error_code']);
        self::assertSame($before['result_json'],$script['result_json']);self::assertSame($before['user_charge_points'],$script['user_charge_points']);
        self::assertSame('failed',Db::name('aigc_short_drama_episode_job')->where('id',$f['job'])->value('status'));
        self::assertSame('pending',Db::name('aigc_short_drama_episode_job')->where('id',$episode['queue_job_id'])->value('status'));
    }
    public function testSetupFailureEndsAllActiveStatesAndPreservesHistoricalContent(): void
    {
        $f=$this->fixture(); // Empty prompt fails before resolving a model or submitting anything.
        $before=Db::name('aigc_short_drama_script_task')->where('task_id',$f['task'])->find();
        self::assertSame(1,Episodes::tick(2000000725,$f['p']));
        foreach(['script_task','generation_task'] as $table){
            $row=Db::name('aigc_short_drama_'.$table)->where('task_id',$f['task'])->find();
            self::assertSame('failed',$row['status']);self::assertEquals(9,$row['user_charge_points']);
        }
        $episode=Db::name('aigc_short_drama_episode_task')->where('id',$f['ep'])->find();
        self::assertSame('input_invalid',$episode['error_code']);self::assertSame('failed',$episode['status']);
        self::assertSame('failed',Db::name('aigc_short_drama_episode_job')->where('id',$f['job'])->value('status'));
        self::assertSame($before['result_json'],Db::name('aigc_short_drama_script_task')->where('task_id',$f['task'])->value('result_json'));
    }
    public function testCancelAllClosesMirrorsAndJobsWithoutChangingFees(): void
    {
        $f=$this->fixture();Episodes::cancelAll(2000000725,7,$f['p']);
        foreach(['script_task','generation_task']as$table){
            $row=Db::name('aigc_short_drama_'.$table)->where('task_id',$f['task'])->find();
            self::assertSame('canceled',$row['status']);self::assertEquals(9,$row['user_charge_points']);
        }
        $job=Db::name('aigc_short_drama_episode_job')->where('id',$f['job'])->find();
        self::assertSame('canceled',$job['status']);self::assertGreaterThan(0,(int)$job['finished_at']);
    }
    public function testReadingTerminalEpisodeOnlyHealsCurrentJobState(): void
    {
        $f=$this->fixture('success');
        Db::name('aigc_short_drama_episode_task')->where('id',$f['ep'])->update(['status'=>'success','completed_once'=>1]);
        $before=Db::name('aigc_short_drama_script_task')->where('task_id',$f['task'])->find();
        Episodes::lists(2000000725,7,$f['p']);
        $job=Db::name('aigc_short_drama_episode_job')->where('id',$f['job'])->find();
        self::assertSame('success',$job['status']);self::assertSame('{"old_receipt":1}',$job['result_json']);
        self::assertSame($before,Db::name('aigc_short_drama_script_task')->where('task_id',$f['task'])->find());
    }
    public function testCanceledCompletedWorkRequiresExplicitResumeAndReusesPaidResult(): void
    {
        $f=$this->fixture('success');
        Db::name('aigc_short_drama_episode_task')->where('id',$f['ep'])->update(['status'=>'canceled','completed_once'=>1]);
        Db::name('aigc_short_drama_episode_job')->where('id',$f['job'])->update(['status'=>'canceled']);
        $next=Db::name('aigc_short_drama_episode_task')->insertGetId($this->scope+['project_id'=>$f['p'],'episode_number'=>2,'status'=>'pending']);
        $before=Db::name('aigc_short_drama_script_task')->where('task_id',$f['task'])->find();
        $jobBefore=Db::name('aigc_short_drama_episode_job')->where('id',$f['job'])->find();
        $s=Episodes::lists(2000000725,7,$f['p']);
        self::assertSame('success',$s['lists'][0]['status']);self::assertTrue($s['lists'][0]['ready']);
        self::assertSame('canceled',$s['lists'][0]['queue_state']);self::assertTrue($s['lists'][0]['can_retry']);
        self::assertTrue($s['paused']);self::assertSame(0,Episodes::tick(2000000725,$f['p']));
        self::assertSame('canceled',Db::name('aigc_short_drama_episode_task')->where('id',$f['ep'])->value('status'));
        $result=Episodes::retry(2000000725,7,$f['ep']);
        self::assertSame('success',$result['status']);self::assertSame($f['task'],$result['task_id']);
        $s=Episodes::lists(2000000725,7,$f['p']);self::assertFalse($s['paused']);
        self::assertSame($next,(int)Episodes::nextInitialEpisode($s['lists'])['id']);
        self::assertSame($before,Db::name('aigc_short_drama_script_task')->where('task_id',$f['task'])->find());
        self::assertSame($jobBefore,Db::name('aigc_short_drama_episode_job')->where('id',$f['job'])->find());
        self::assertSame(1,Db::name('aigc_short_drama_script_task')->where('project_id',$f['c'])->count());
        self::assertFalse($s['lists'][0]['can_retry']);
    }

}
