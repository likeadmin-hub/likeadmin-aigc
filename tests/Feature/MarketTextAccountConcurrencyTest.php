<?php
namespace Tests\Feature;

use app\common\model\ai\AiConsumptionLog;
use app\common\service\power\MarketTextModelRuntimeService as Runtime;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\ShortDramaAccountFixture as Fixture;
use think\facade\Db;

require_once dirname(__DIR__).'/fixtures/short_drama_account_fixture.php';

/** Eight independent PHP processes, committed private accounts, no provider calls. */
final class MarketTextAccountConcurrencyTest extends TestCase
{
    private string $prefix='';
    private int $user=0;
    private array $processes=[];
    protected function setUp(): void
    {
        if (getenv('SHORT_DRAMA_STORY_DB_TESTS')!=='1') self::markTestSkipped('Opt in to private account processes');
        (new \think\App())->initialize();
        $prefix='account_'.bin2hex(random_bytes(8));
        Db::name('tenant')->insert(['id'=>2000000816,'sn'=>$prefix,'point_balance'=>8,'create_time'=>time(),'update_time'=>time()]);
        $this->prefix=$prefix;
        $this->user=(int)Db::name('user')->insertGetId(['tenant_id'=>2000000816,'nickname'=>$prefix,
            'sn'=>(string)random_int(10000000,99999999),'user_money'=>16]);
    }
    protected function tearDown(): void
    {
        foreach ($this->processes as [$process,$pipes]) if (is_resource($process)) {
            proc_terminate($process,9); foreach($pipes as $pipe) if(is_resource($pipe)) fclose($pipe); proc_close($process);
        }
        if (!$this->prefix) return;
        $owned=Db::name('tenant')->where(['id'=>2000000816,'sn'=>$this->prefix])->count();
        if (!$owned) return;
        if ($this->user) {
            $scope=['tenant_id'=>2000000816,'user_id'=>$this->user];
            $ids=Db::name('ai_consumption_log')->where($scope)->column('id');
            if ($ids) Db::name('ai_consumption_event')->whereIn('consumption_id',$ids)->delete();
            Db::name('ai_consumption_log')->where($scope)->delete();
            Db::name('ai_app_task')->where($scope)->delete();
            Db::name('user_account_log')->where('user_id',$this->user)->delete();
            Db::name('user')->where(['id'=>$this->user,'tenant_id'=>2000000816,'nickname'=>$this->prefix])->delete();
        }
        Db::name('tenant_point_log')->where('tenant_id',2000000816)->delete();
        Db::name('tenant')->where(['id'=>2000000816,'sn'=>$this->prefix])->delete();
    }
    private function compete(array $keys,string $mode): array
    {
        foreach ($keys as $key) {
            $process=proc_open([PHP_BINARY,dirname(__DIR__).'/fixtures/short_drama_account_worker.php',
                $this->prefix,(string)$this->user,(string)$key,$mode],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            self::assertIsResource($process); fclose($pipes[0]);
            $this->processes[]=[$process,$pipes];
        }
        $outputs=[];
        foreach($this->processes as [$process,$pipes]) {
            $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($process),$error.$out);
            $outputs[]=trim($out);
        }
        $this->processes=[];return $outputs;
    }
    public function testCompetingRequestsCannotOverspendAndPreparedReplaySubmitsOnlyOnce(): void
    {
        $outputs=$this->compete(range(0,7),'reserve');
        self::assertCount(2,array_filter($outputs,static fn($v)=>str_starts_with($v,'reserve:')));
        self::assertCount(6,array_filter($outputs,static fn($v)=>$v==='rejected:422'));
        self::assertSame(0.0,(float)Db::name('tenant')->where('id',2000000816)->value('point_balance'));
        self::assertSame(0.0,(float)Db::name('user')->where('id',$this->user)->value('user_money'));
        $logs=AiConsumptionLog::where(['tenant_id'=>2000000816,'user_id'=>$this->user])->select();
        self::assertCount(2,$logs);
        $a=$logs[0];$key=null;
        foreach(range(0,7) as $candidate) if(hash('sha256',$this->prefix.'_'.$candidate)===$a['request_summary']['account_reservation']['request_key']) $key=$candidate;
        self::assertNotNull($key);
        $outputs=$this->compete(array_fill(0,8,$key),'submit');
        self::assertCount(1,array_filter($outputs,static fn($v)=>str_starts_with($v,'submit:')));
        self::assertCount(7,array_filter($outputs,static fn($v)=>$v==='rejected:409'));
        self::assertSame(2,Db::name('user_account_log')->where('user_id',$this->user)->count());
        $context=['consumption'=>$a,'appTask'=>['id'=>$a['app_task_id']]];
        Runtime::recoverDurableResult(2000000816,$this->user,'aigc_short_drama_script_task',123,Fixture::receipt($context));
        Runtime::recoverDurableResult(2000000816,$this->user,'aigc_short_drama_script_task',123,Fixture::receipt($context));
        Fixture::call('fail',['consumption'=>$logs[1]],'fixture proven unsubmitted','not_submitted');
        self::assertSame(7.0,(float)Db::name('tenant')->where('id',2000000816)->value('point_balance'));
        self::assertSame(14.0,(float)Db::name('user')->where('id',$this->user)->value('user_money'));
        self::assertSame(4,Db::name('user_account_log')->where('user_id',$this->user)->count());
    }
}
