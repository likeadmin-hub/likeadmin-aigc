<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaPlanningDispatch as Dispatch, ShortDramaPlanningUnit as Unit, ShortDramaStoryGeneration as Generation};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaLongPlanningDispatchTest extends TestCase
{
    private bool $transaction = false;
    private const TENANT = 2000000731;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Local transaction fixtures only');
        (new \think\App())->initialize(); Db::startTrans(); $this->transaction = true;
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }

    public function testOnlyExplicitModelFileParsesJoinThePlanningWorker():void
    {
        $prefix='file_dispatch_'.bin2hex(random_bytes(5));
        foreach([false,true]as$model){
            $request=['source'=>'market_file_qa_parse','workflow_variant'=>'story_outline_v2'];
            if($model)$request['_file_parse_model']=['model'=>['id'=>'65'],'text'=>'原文'];
            Db::name('aigc_short_drama_script_task')->insert(['tenant_id'=>self::TENANT,'user_id'=>7,'task_id'=>$prefix.(int)$model,
                'status'=>'pending','request_json'=>json_encode($request),'update_time'=>10]);
        }
        self::assertFalse(Dispatch::tick($prefix.'0',self::TENANT,static function(){self::fail('App parser entered text worker');}));
        self::assertTrue(Dispatch::tick($prefix.'1',self::TENANT,static fn()=>true));
    }

    public function testScanPassesTwentyWaitingAndTwentyLockedTasks(): void
    {
        $prefix = 'dispatch_' . bin2hex(random_bytes(6));
        for ($i = 0; $i < 42; $i++) {
            $task = $prefix . '_' . $i;
            Db::name('aigc_short_drama_script_task')->insert(['tenant_id'=>self::TENANT,'user_id'=>7,'task_id'=>$task,
                'status'=>'queued','request_json'=>'{"workflow_variant":"story_outline_v2"}','update_time'=>10]);
            if ($i < 20) Db::name('aigc_short_drama_planning_unit')->insert(['tenant_id'=>self::TENANT,'user_id'=>7,
                'task_id'=>$task,'unit_key'=>'waiting','status'=>'waiting','attempt'=>1,'update_time'=>time()]);
        }
        $seen = [];
        self::assertTrue(Dispatch::tick('', self::TENANT, static function ($t, $u, $task) use (&$seen, $prefix) {
            $seen[] = $task; return $task === $prefix . '_40';
        }));
        self::assertCount(21, $seen); self::assertSame($prefix.'_20', $seen[0]);
        self::assertFalse(Dispatch::tick('', self::TENANT+1, static function () { self::fail('Other tenant dispatched'); }));
        self::assertFalse(Dispatch::tick($prefix.'_0',self::TENANT,static function () { self::fail('Waiting task dispatched'); }));
    }

    /** @dataProvider scales */
    public function testOutlineResumesAfterEveryTurnWithoutRepeatingPaidCalls(int $total): void
    {
        $task = 'long_outline_' . bin2hex(random_bytes(6)); $calls=[]; $turns=0; $completed=null;
        $base=['title'=>'长篇','type_judgement'=>'悬疑','core_theme'=>'信任','story_outline'=>'寻找真相',
            'subjects'=>[['id'=>'p','name'=>'主角']], 'locations'=>[['id'=>'l','name'=>'旧宅']]];
        $request=['_generation_version'=>3,'_input_contract_version'=>11,'multi_episode_stage'=>'episodes',
            'episode_count'=>$total,'quality_review_enabled'=>false,'confirmed_story_snapshot'=>$base];
        $provider=static function($key,$messages,$budget) use($task,$total,&$calls){
            return Unit::call(self::TENANT,7,$task,'v3_policy2_'.$key,$messages+['budget'=>$budget],
                static function() use($key,$total,&$calls){
                    $calls[]=$key;
                    if($key==='roadmap') $payload=['segments'=>[['start'=>1,'end'=>$total,'goal'=>'追查','reveal'=>'线索','ending'=>'真相']]];
                    else {
                        preg_match('/outline_(\d+)_(\d+)/',$key,$m);$episodes=[];
                        for($i=0;$i<(int)$m[2];$i++){$n=(int)$m[1]+$i;$episodes[]=['episode_number'=>$n,'title'=>'标题'.$n,
                            'story_outline'=>'事件'.$n,'conflict_point'=>'冲突'.$n,'ending_hook'=>'线索'.$n];}
                        $payload=['episodes'=>$episodes];
                    }
                    return ['result'=>['content'=>json_encode($payload),'billing'=>['user_charge_points'=>9]]];
                },true);
        };
        $run=static fn()=>Generation::generate($request,['context_window'=>100000,'max_tokens'=>16384],
            static fn($chunk)=>['system_prompt'=>'','content'=>json_encode(['start'=>$chunk['episode_batch_start']??0])],$provider);
        do {
            $before=count($calls);$turns++;
            try {$completed=Unit::withinDispatch(self::TENANT,7,$task,4,$run);}
            catch(\RuntimeException $e){self::assertSame(425,$e->getCode());}
            self::assertLessThanOrEqual(4,count($calls)-$before);
            self::assertLessThan(30,$turns);
        } while($completed===null);
        self::assertSame(range(1,$total),array_column($completed['result']['episodes'],'episode_number'));
        self::assertCount(1+(int)ceil($total/10),$calls);
        self::assertCount(count($calls),array_unique($calls));
        $receipts=Db::name('aigc_short_drama_planning_unit')->where('task_id',$task)->column('result_json','id');
        self::assertSame($completed['result'],$run()['result']);
        self::assertSame($receipts,Db::name('aigc_short_drama_planning_unit')->where('task_id',$task)->column('result_json','id'));
        self::assertCount(count($receipts),$calls);
    }
    public static function scales():array {return [[10],[50],[100],[500]];}
}
