<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\{ShortDramaEpisodeService as Episodes,ShortDramaEpisodeRange as Ranges};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaEpisodeRangeTest extends TestCase
{
    private const T=2000000741;
    private bool $transaction=false;
    protected function setUp():void
    {
        if(getenv('SHORT_DRAMA_STORY_DB_TESTS')!=='1')self::markTestSkipped('Local rolled-back fixtures only');
        (new \think\App())->initialize();Db::startTrans();$this->transaction=true;
        \think\facade\Env::set('short_drama.redis_host','127.0.0.1');\think\facade\Env::set('short_drama.redis_port',1);
    }
    protected function tearDown():void{if($this->transaction)Db::rollback();}
    private function fixture(int $count=12,bool $staged=true):array
    {
        $task='range_'.bin2hex(random_bytes(8));
        $project=Db::name('aigc_short_drama_project')->insertGetId(['tenant_id'=>self::T,'user_id'=>7,'multi_episode'=>1,'episode_count'=>$count,'last_task_id'=>$task]);
        $plan=['title'=>'旧宅','type_judgement'=>'悬疑','core_theme'=>'信任','story_outline'=>'追查真相',
            'subjects'=>[['id'=>'p','name'=>'主角']],'locations'=>[['id'=>'l','name'=>'旧宅']],'episodes'=>[]];
        for($i=1;$i<=$count;$i++)$plan['episodes'][]=['episode_number'=>$i,'title'=>'标题'.$i,'story_outline'=>'剧情'.$i,
            'conflict_point'=>'冲突'.$i,'ending_hook'=>'钩子'.$i,'target_duration_seconds'=>120,'custom_material'=>'original'];
        Db::name('aigc_short_drama_script_task')->insert(['tenant_id'=>self::T,'user_id'=>7,'project_id'=>$project,
            'task_id'=>$task,'status'=>'success','request_json'=>'{"quality_review_enabled":false}',
            'result_json'=>json_encode($plan),'billing_status'=>'deducted','user_charge_points'=>9]);
        $params=['project_id'=>$project,'task_id'=>$task];if($staged)$params['generation_mode']='staged';
        return Episodes::start(self::T,7,$params);
    }
    private function confirm(array $series,int $end):array
    {
        $control=$series['generation_control'];
        return ['project_id'=>$series['project_id'],'action'=>'confirm_range','control_revision'=>$control['revision'],
            'confirmation_key'=>'range_'.bin2hex(random_bytes(8)),'range_start'=>$control['next_start'],'range_end'=>$end,
            'outlines'=>array_values(array_filter($control['outline'],static fn($e)=>$e['episode_number']<=$end))];
    }
    private function reject(array $params,string $message):void
    {
        try{Episodes::start(self::T,7,$params);self::fail('Invalid request accepted');}
        catch(\Exception $e){self::assertStringContainsString($message,$e->getMessage());}
    }
    public function testStagedStartDoesNotQueueOrGenerateUntilConfirmed():void
    {
        $s=$this->fixture();self::assertSame(0,$s['generation_control']['confirmed_end']);
        self::assertTrue($s['lists'][0]['awaiting_confirmation']);
        self::assertSame(Ranges::AWAITING_CONFIRMATION,(int)$s['lists'][0]['next_retry_at']);self::assertSame(0,Episodes::tick(self::T,$s['project_id']));
        self::assertSame(0,Db::name('aigc_short_drama_episode_job')->where('project_id',$s['project_id'])->count());
        self::assertSame(0,Db::name('aigc_short_drama_episode_task')->where('project_id',$s['project_id'])->where('production_project_id','>',0)->count());
    }
    public function testRangeIsAtomicIdempotentAndPreservesOriginalOutlineAndMetadata():void
    {
        $s=$this->fixture();$original=Db::name('aigc_short_drama_script_task')->where('project_id',$s['project_id'])->find();
        $p=$this->confirm($s,3);$p['outlines'][0]['story_outline']='新线索只影响尚未开始的本集';
        $next=Episodes::start(self::T,7,$p);Episodes::start(self::T,7,$p);
        self::assertSame(3,$next['generation_control']['confirmed_end']);self::assertSame(2,$next['generation_control']['revision']);
        self::assertSame(1,Db::name('aigc_short_drama_episode_job')->where('project_id',$s['project_id'])->count());
        $row=Db::name('aigc_short_drama_episode_task')->where('id',$s['lists'][0]['id'])->find();$outline=json_decode($row['outline_json'],true);
        self::assertSame(0,(int)$row['next_retry_at']);
        self::assertSame(Ranges::AWAITING_CONFIRMATION,(int)$next['lists'][3]['next_retry_at']);
        self::assertSame(120,$outline['target_duration_seconds']);self::assertSame('original',$outline['custom_material']);
        self::assertSame($p['outlines'][0]['story_outline'],$outline['story_outline']);
        self::assertSame($original,Db::name('aigc_short_drama_script_task')->where('project_id',$s['project_id'])->find());
        $p['outlines'][0]['story_outline']='变更同一请求';$this->reject($p,'内容已变化');
    }
    public function testSkippedRangesStaleTabsAndInvalidDraftsAreRejectedWithoutPartialWrites():void
    {
        $s=$this->fixture();$p=$this->confirm($s,3);$p['range_start']=2;$this->reject($p,'连续集数');
        $p=$this->confirm($s,3);$p['outlines'][2]['ending_hook']='';$before=Db::name('aigc_short_drama_episode_task')->where('project_id',$s['project_id'])->select()->toArray();
        $this->reject($p,'补全');self::assertSame($before,Db::name('aigc_short_drama_episode_task')->where('project_id',$s['project_id'])->select()->toArray());
        $valid=$this->confirm($s,3);Episodes::start(self::T,7,$valid);$this->reject($this->confirm($s,2),'其他页面更新');
    }
    public function testPauseResumeAndOldCancelCannotReachUnconfirmedEpisodes():void
    {
        $s=$this->fixture();$s=Episodes::start(self::T,7,$this->confirm($s,2));
        $paused=Episodes::start(self::T,7,['project_id'=>$s['project_id'],'action'=>'pause_range','control_revision'=>2]);
        self::assertTrue($paused['generation_control']['paused']);self::assertSame(0,Episodes::tick(self::T,$s['project_id']));
        $resumed=Episodes::start(self::T,7,['project_id'=>$s['project_id'],'action'=>'resume_range','control_revision'=>3]);
        self::assertFalse($resumed['generation_control']['paused']);
        Episodes::cancelAll(self::T,7,$s['project_id']);
        $rows=Db::name('aigc_short_drama_episode_task')->where('project_id',$s['project_id'])->order('episode_number')->select()->toArray();
        self::assertSame('canceled',$rows[0]['status']);self::assertSame('pending',$rows[2]['status']);
        try{Episodes::retry(self::T,7,(int)$rows[2]['id']);self::fail();}catch(\Exception $e){self::assertStringContainsString('先确认',$e->getMessage());}
    }
    public function testCompletedRangeStopsBeforeNextChildAndNextRangeRequiresPreviousSuccess():void
    {
        $s=$this->fixture();$s=Episodes::start(self::T,7,$this->confirm($s,2));
        $this->reject($this->confirm($s,4),'尚未完成');
        Db::name('aigc_short_drama_episode_task')->where('project_id',$s['project_id'])->where('episode_number','<=',2)->update(['status'=>'success','completed_once'=>1]);
        self::assertSame(0,Episodes::tick(self::T,$s['project_id']));
        $s=Episodes::lists(self::T,7,$s['project_id']);self::assertTrue($s['generation_control']['can_confirm']);self::assertSame(3,$s['generation_control']['next_start']);
        $s=Episodes::start(self::T,7,$this->confirm($s,4));self::assertSame(4,$s['generation_control']['confirmed_end']);
        self::assertSame(2,Db::name('aigc_short_drama_episode_job')->where('project_id',$s['project_id'])->count());
    }
    public function testWorkerCompletionCannotQueueBeyondConfirmedRange():void
    {
        $s=$this->fixture();$s=Episodes::start(self::T,7,$this->confirm($s,2));
        foreach(array_slice($s['lists'],0,2)as$row){
            $task='range_child_'.bin2hex(random_bytes(8));
            $project=Db::name('aigc_short_drama_project')->insertGetId(['tenant_id'=>self::T,'user_id'=>7,'last_task_id'=>$task]);
            $plan=['title'=>'原剧本','story_outline'=>'原剧情','subjects'=>[['id'=>'p','name'=>'主角']],
                'locations'=>[['id'=>'l','name'=>'旧宅']],'storyboard'=>[['shot_id'=>'1','visual_description'=>'原动作']]];
            Db::name('aigc_short_drama_script_task')->insert(['tenant_id'=>self::T,'user_id'=>7,'project_id'=>$project,
                'task_id'=>$task,'status'=>'success','request_json'=>'{"_generation_version":3,"quality_review_enabled":false}',
                'result_json'=>json_encode($plan),'billing_status'=>'deducted','user_charge_points'=>9]);
            Db::name('aigc_short_drama_episode_task')->where('id',$row['id'])->update(['task_id'=>$task,'production_project_id'=>$project]);
        }
        self::assertSame(1,Episodes::tick(self::T,$s['project_id']));
        self::assertSame(1,Episodes::tick(self::T,$s['project_id']));
        self::assertSame(0,Episodes::tick(self::T,$s['project_id']));
        $rows=Db::name('aigc_short_drama_episode_task')->where('project_id',$s['project_id'])->order('episode_number')->select()->toArray();
        self::assertSame(['success','success','pending'],array_column(array_slice($rows,0,3),'status'));
        self::assertSame(0,(int)$rows[2]['queue_job_id']);self::assertSame(0,(int)$rows[2]['production_project_id']);
        self::assertSame(2,Db::name('aigc_short_drama_episode_job')->where('project_id',$s['project_id'])->where('status','success')->count());
    }

    public function testPauseDrainsOnlyTheAlreadyStartedEpisode():void
    {
        $s=$this->fixture();$s=Episodes::start(self::T,7,$this->confirm($s,2));$row=$s['lists'][0];
        $task='range_drain_'.bin2hex(random_bytes(8));
        $project=Db::name('aigc_short_drama_project')->insertGetId(['tenant_id'=>self::T,'user_id'=>7,'last_task_id'=>$task]);
        $plan=['title'=>'原剧本','story_outline'=>'原剧情','subjects'=>[['id'=>'p','name'=>'主角']],
            'locations'=>[['id'=>'l','name'=>'旧宅']],'storyboard'=>[['shot_id'=>'1','visual_description'=>'原动作']]];
        Db::name('aigc_short_drama_script_task')->insert(['tenant_id'=>self::T,'user_id'=>7,'project_id'=>$project,
            'task_id'=>$task,'status'=>'success','request_json'=>'{"_generation_version":3,"quality_review_enabled":false}',
            'result_json'=>json_encode($plan),'billing_status'=>'deducted','user_charge_points'=>9]);
        Db::name('aigc_short_drama_episode_task')->where('id',$row['id'])->update(['status'=>'running','task_id'=>$task,'production_project_id'=>$project]);
        $paused=Episodes::start(self::T,7,['project_id'=>$s['project_id'],'action'=>'pause_range','control_revision'=>2]);
        self::assertTrue($paused['generation_control']['draining']);
        self::assertSame(1,Episodes::tick(self::T,$s['project_id']));
        self::assertSame(0,Episodes::tick(self::T,$s['project_id']));
        $after=Episodes::lists(self::T,7,$s['project_id']);
        self::assertFalse($after['generation_control']['draining']);self::assertTrue($after['generation_control']['paused']);
        self::assertSame('success',$after['lists'][0]['status']);self::assertSame(0,(int)$after['lists'][1]['production_project_id']);
        self::assertSame(9.0,(float)Db::name('aigc_short_drama_script_task')->where('task_id',$task)->value('user_charge_points'));
        $control=Ranges::load(self::T,7,$s['project_id']);
        $changedAttempt=array_replace($after['lists'][0],['retry_count'=>1]);
        self::assertFalse(Ranges::canRun($control,$changedAttempt));
    }

    public function testRoadmapAndStartedContentCannotBeOverridden():void
    {
        $s=$this->fixture();$first=$s['lists'][0]['id'];
        $snapshot=Episodes::decode(Db::name('aigc_short_drama_episode_task')->where('id',$first)->value('series_json'));
        $snapshot['plan']['series_roadmap']=[['start'=>1,'end'=>4,'goal'=>'开篇调查']];
        Db::name('aigc_short_drama_episode_task')->where('id',$first)->update(['series_json'=>json_encode($snapshot)]);
        $s=Episodes::lists(self::T,7,$s['project_id']);self::assertSame(4,$s['generation_control']['max_end']);
        $p=$this->confirm($s,4);$p['range_end']=5;$this->reject($p,'连续集数');
        Db::name('aigc_short_drama_episode_task')->where('id',$first)->update(['task_id'=>'protected_existing_task']);
        $this->reject($this->confirm($s,2),'不能覆盖大纲');
        self::assertSame(0,Ranges::load(self::T,7,$s['project_id'])['confirmed_end']);
    }

    public function testLegacyModeAndTenantBoundaryRemainIntact():void
    {
        $s=$this->fixture(12,false);self::assertNull($s['generation_control']);
        self::assertSame(1,Db::name('aigc_short_drama_episode_job')->where('project_id',$s['project_id'])->count());
        $this->reject(['project_id'=>$s['project_id'],'action'=>'pause_range','control_revision'=>1],'原生成流程');
        $s=$this->fixture();$p=$this->confirm($s,2);
        try{Episodes::start(self::T+1,7,$p);self::fail();}catch(\Exception $e){self::assertSame('项目不存在',$e->getMessage());}
        self::assertSame(0,Ranges::load(self::T,7,$s['project_id'])['confirmed_end']);
    }
    public function testFiveHundredEpisodesAdvanceInFiftyExplicitRanges():void
    {
        $s=$this->fixture(500);
        for($end=10;$end<=500;$end+=10){
            $s=Episodes::start(self::T,7,$this->confirm($s,$end));
            Db::name('aigc_short_drama_episode_task')->where('project_id',$s['project_id'])->where('episode_number','<=',$end)->update(['status'=>'success','completed_once'=>1]);
            self::assertSame(0,Episodes::tick(self::T,$s['project_id']));
            $s=Episodes::lists(self::T,7,$s['project_id']);
        }
        self::assertCount(50,$s['generation_control']['confirmed_ranges']);self::assertSame(500,$s['completed_count']);
        self::assertFalse($s['generation_control']['can_confirm']);
        self::assertSame(50,Db::name('aigc_short_drama_episode_job')->where('project_id',$s['project_id'])->count());
        self::assertSame(1,Db::name('aigc_short_drama_script_task')->where('project_id',$s['project_id'])->count());
    }
}
