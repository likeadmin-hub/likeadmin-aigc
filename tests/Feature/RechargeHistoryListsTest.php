<?php
namespace Tests\Feature;

use app\api\lists\recharge\RechargeLists;
use app\common\enum\AdminTerminalEnum;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class RechargeHistoryListsTest extends TestCase
{
    private int $tenant;
    private const USER=2000000942;
    private $oldTenant;
    private $oldSource;
    protected function setUp():void
    {
        if(getenv('RECHARGE_HISTORY_DB_TESTS')!=='local-rollback')self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();
        self::assertSame('127.0.0.1',Db::connect()->getConfig('hostname'));
        self::assertContains(Db::connect()->getConfig('database'),['x_cn','z_cn']);
        $this->oldTenant=request()->tenantId;$this->oldSource=request()->source;
        Db::startTrans();
        $this->tenant=Db::name('tenant')->insertGetId(['sn'=>'rh'.bin2hex(random_bytes(5)),'name'=>'Recharge history fixture','create_time'=>time()]);
        request()->tenantId=$this->tenant;request()->source=AdminTerminalEnum::USER;
    }
    protected function tearDown():void
    {
        if(getenv('RECHARGE_HISTORY_DB_TESTS')==='local-rollback'){
            Db::rollback();request()->tenantId=$this->oldTenant;request()->source=$this->oldSource;
        }
    }
    private function record(array $extra=[]):int
    {
        return Db::name('recharge_order')->insertGetId(array_replace([
            'tenant_id'=>$this->tenant,'user_id'=>self::USER,'sn'=>'history-'.bin2hex(random_bytes(12)),
            'order_amount'=>'0.01','recharge_points'=>'1000.00','package_id'=>99999999,'package_name'=>'Frozen package name',
            'pay_status'=>1,'pay_time'=>time(),'create_time'=>time(),'delete_time'=>null,
        ],$extra));
    }
    private function history(int $user=self::USER):RechargeLists
    {
        return new class($user) extends RechargeLists {
            public function __construct(int $user){$this->request=request();$this->userId=$user;$this->limitOffset=0;$this->limitLength=2;}
        };
    }
    public function testPaidPackagesAndCustomOrdersHaveDistinctKeysAndRealPagination():void
    {
        $one=$this->record();$two=$this->record();
        $custom=$this->record(['package_id'=>0,'package_name'=>'','recharge_points'=>'0.01']);
        $this->record(['pay_status'=>0]);$this->record(['delete_time'=>time()]);
        $this->record(['user_id'=>self::USER+1]);$this->record(['tenant_id'=>$this->tenant+1]);
        $list=$this->history();$first=$list->lists();$list->limitOffset=2;$second=$list->lists();
        self::assertSame(3,$list->count());
        self::assertSame([$custom,$two],array_column($first,'id'));
        self::assertSame([$one],array_column($second,'id'));
        self::assertCount(3,array_unique(array_column(array_merge($first,$second),'sn')));
        self::assertSame('1000.00',$second[0]['credited_points']);
        self::assertSame('0.01',$second[0]['order_amount']);
        self::assertSame('0.01',$first[0]['credited_points']);
        self::assertSame('Frozen package name',$second[0]['package_name'],'history does not depend on the mutable package catalogue');
    }
    public function testLegacyOrdersUseTheirStoredAmountWithoutMutatingTheOrder():void
    {
        $id=$this->record(['recharge_points'=>'0.00','order_amount'=>'1.50','package_id'=>0]);
        self::assertSame('1.50',$this->history()->lists()[0]['credited_points']);
        self::assertSame('0.00',Db::name('recharge_order')->where('id',$id)->value('recharge_points'));
    }
    public function testMissingIdentityCannotListGuestOrUnscopedPayments():void
    {
        $this->record(['user_id'=>0]);
        self::assertSame([],$this->history(0)->lists());self::assertSame(0,$this->history(0)->count());
        request()->tenantId=0;$this->record(['tenant_id'=>0]);
        self::assertSame([],$this->history()->lists());self::assertSame(0,$this->history()->count());
    }
}
