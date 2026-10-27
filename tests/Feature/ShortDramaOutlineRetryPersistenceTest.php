<?php
namespace Tests\Feature;

use app\common\service\app\aigc_short_drama\{ShortDramaStoryGeneration as Generation, ShortDramaPlanningUnit as Unit};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

class ShortDramaOutlineRetryPersistenceTest extends TestCase
{
    private bool $transaction = false;
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS') !== '1') self::markTestSkipped('Transactional local fixtures only');
        (new \think\App())->initialize(); Db::startTrans(); $this->transaction = true;
    }
    protected function tearDown(): void { if ($this->transaction) Db::rollback(); }

    /** @dataProvider failureKinds */
    public function testExplicitRetryRepairsOnlyRejectedUnitAndPreservesReceipts(string $kind): void
    {
        $task = 'outline_retry_' . bin2hex(random_bytes(8)); $calls = []; $repair = false;
        $base = ['title'=>'测试剧','type_judgement'=>'悬疑','core_theme'=>'信任','story_outline'=>'调查真相',
            'subjects'=>[['id'=>'s1','name'=>'甲']], 'locations'=>[['id'=>'l1','name'=>'老宅']]];
        $request = ['_generation_version'=>3,'_input_contract_version'=>10,'multi_episode_stage'=>'episodes',
            'episode_count'=>11,'confirmed_story_snapshot'=>$base];
        $provider = static function ($key, $messages, $budget) use ($task, &$calls, &$repair, $kind) {
            return Unit::call(2000000719, 7, $task, 'v3_policy2_' . $key, $messages + ['budget'=>$budget],
                static function () use ($key, &$calls, &$repair, $kind) {
                    $calls[]=$key;
                    if (str_starts_with($key,'roadmap')) {
                        $result=['segments'=>[['start'=>$kind==='roadmap' && !$repair ? 2 : 1,'end'=>11,'goal'=>'追查','reveal'=>'证据','ending'=>'找到真相']]];
                    } else {
                        preg_match('/outline_(\d+)_(\d+)/',$key,$matches); $episodes=[];
                        for($i=0;$i<(int)$matches[2];$i++) {
                            $n=(int)$matches[1]+$i;
                            $episodes[]=['episode_number'=>$n,'title'=>'本集'.$n,'story_outline'=>'剧情'.($kind==='duplicate' && $n===11 && !$repair ? 1 : $n),
                                'conflict_point'=>'线索冲突','ending_hook'=>'新的证据'];
                        }
                        $result=['episodes'=>$episodes];
                    }
                    return ['result'=>['content'=>json_encode($result),'billing'=>['user_charge_points'=>9]]];
                }, true);
        };
        $run = static fn() => Generation::generate($request,['context_window'=>100000,'max_tokens'=>16384],
            static fn($chunk)=>['system_prompt'=>'','content'=>json_encode(['start'=>$chunk['episode_batch_start']??0])],$provider);
        try { $run(); self::fail('Invalid outline passed'); } catch (\RuntimeException $e) { self::assertSame(422,$e->getCode()); }
        $before=Db::name('aigc_short_drama_planning_unit')->where('task_id',$task)->column('result_json','id');
        $count=count($calls); Unit::prepareExplicitRetry(2000000719,7,$task); $repair=true;
        $result=$run();
        self::assertSame(range(1,11),array_column($result['result']['episodes'],'episode_number'));
        self::assertSame($base['subjects'],$result['result']['subjects']);
        self::assertSame($kind==='roadmap' ? 3 : 1,count($calls)-$count);
        foreach($before as $id=>$receipt) self::assertSame($receipt,Db::name('aigc_short_drama_planning_unit')->where('id',$id)->value('result_json'));
        $count=count($calls); self::assertSame($result['result'],$run()['result']); self::assertCount($count,$calls);
    }
    public static function failureKinds(): array { return [['roadmap'],['duplicate']]; }
}
