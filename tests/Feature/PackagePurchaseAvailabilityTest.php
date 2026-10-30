<?php
namespace Tests\Feature;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use app\common\service\promotion\{PackagePricingService as Price,PackagePaymentService,PackagePaymentGateway};
use app\common\service\virtualpay\{VirtualPayService,VirtualPayGateway};
use app\common\logic\PaymentLogic;

final class PackagePurchaseAvailabilityTest extends TestCase
{
    private int $user;
    private const TENANT=2000000911;
    private $source;
    private $tenant;
    protected function setUp():void
    {
        if(getenv('PACKAGE_AVAILABILITY_DB_TESTS')!=='local-existing')self::markTestSkipped('Explicit local rollback fixtures only');
        (new \think\App())->initialize();self::assertSame('127.0.0.1',config('database.connections.mysql.hostname'));
        $this->source=request()->source;$this->tenant=request()->tenantId;request()->source=\app\common\enum\AdminTerminalEnum::PLATFORM;request()->tenantId=self::TENANT;
        Db::startTrans();Db::name('tenant')->insert(['id'=>self::TENANT,'sn'=>'availability-fixture','name'=>'Availability fixture','tactics'=>0,'create_time'=>time()]);$this->user=(int)Db::name('user')->insertGetId(['tenant_id'=>self::TENANT,'account'=>'checkout-'.bin2hex(random_bytes(8)),'sn'=>random_int(10000000,99999999),'user_money'=>0,'create_time'=>time()]);
    }
    protected function tearDown():void
    {
        if(getenv('PACKAGE_AVAILABILITY_DB_TESTS')==='local-existing'){Db::rollback();request()->source=$this->source;request()->tenantId=$this->tenant;}
    }
    private function package(string $type,int $amount=10):int
    {
        return (int)Db::name($type==='membership'?'membership_plan':'recharge_package')->insertGetId(['tenant_id'=>self::TENANT,'name'=>'Availability fixture','status'=>1,'delete_time'=>null]+($type==='membership'?['monthly_price'=>$amount,'yearly_price'=>$amount,'duration_months'=>1,'features'=>'[]']:['amount'=>$amount,'points'=>10]));
    }
    private function stop(string $type,int $id):void{Db::name($type==='membership'?'membership_plan':'recharge_package')->where('id',$id)->update(['status'=>0]);}
    private function rejected(callable $fn):void
    {
        $message='';try{$fn();}catch(\RuntimeException $e){$message=$e->getMessage();}self::assertSame('套餐不存在或已下架',$message);
    }
    public function testInactiveCatalogQuoteAndNewOrderAreRejected():void
    {
        foreach(['membership','recharge']as$type){$p=$this->package($type);$this->stop($type,$p);
            $this->rejected(fn()=>Price::quote(self::TENANT,$this->user,$type,$p));
            $this->rejected(fn()=>Price::create(self::TENANT,$this->user,4,$type,$p));
        }
        self::assertSame(0,Db::name('cash_checkout')->where('tenant_id',self::TENANT)->count());
    }
    public function testIdempotentCreateCannotResumeAPackageAfterItIsStopped():void
    {
        foreach(['membership','recharge']as$type){$p=$this->package($type);$key=bin2hex(random_bytes(16));Price::create(self::TENANT,$this->user,4,$type,$p,'package','',$key);$this->stop($type,$p);
            $this->rejected(fn()=>Price::create(self::TENANT,$this->user,4,$type,$p,'package','',$key));
        }
    }
    public function testOrdinaryChannelsCannotPrepayStaleOrders():void
    {
        $calls=[];$pay=new PackagePaymentService(new PackagePaymentGateway(static function($action)use(&$calls){$calls[]=$action;return ['app_id'=>'test','merchant'=>'test'];}));
        foreach(['membership','recharge']as$type){$p=$this->package($type);$order=Price::create(self::TENANT,$this->user,4,$type,$p);$o=VirtualPayService::order(self::TENANT,$this->user,$type,$order['order_id'])->toArray();$this->stop($type,$p);
            foreach([2,3]as$channel)foreach([1,2,4]as$terminal)$this->rejected(fn()=>$pay->prepay($type,$o,$channel,$terminal,'/'));
        }
        self::assertSame([],$calls);
    }
    public function testVirtualPayRejectsBeforeReadingKeysOrCallingAChannel():void
    {
        foreach(['membership','recharge']as$type){$p=$this->package($type);$order=Price::create(self::TENANT,$this->user,1,$type,$p);$this->stop($type,$p);
            $this->rejected(fn()=>(new VirtualPayService())->prepay(self::TENANT,$this->user,1,$type,$order['order_id'],'','android'));
        }
    }
    public function testZeroPriceAndLegacyPaymentEntryDoNotGrantStoppedPackages():void
    {
        foreach(['membership','recharge']as$type){$p=$this->package($type,0);$order=Price::create(self::TENANT,$this->user,4,$type,$p);$o=VirtualPayService::order(self::TENANT,$this->user,$type,$order['order_id'])->toArray();$this->stop($type,$p);
            self::assertFalse(PaymentLogic::pay(2,$type,$o,4,'/'));self::assertSame('套餐不存在或已下架',PaymentLogic::getError());
            self::assertSame(0,(int)VirtualPayService::order(self::TENANT,$this->user,$type,$order['order_id'])['pay_status']);
        }
    }
    public function testConfirmedDeliveryStillConsumesItsFrozenRightsAfterStopping():void
    {
        foreach(['membership','recharge']as$type){$p=$this->package($type);$order=Price::create(self::TENANT,$this->user,4,$type,$p);$before=Price::snapshot(self::TENANT,$type,$order['order_id']);$this->stop($type,$p);Price::consume(self::TENANT,$type,$order['order_id']);$after=Price::snapshot(self::TENANT,$type,$order['order_id']);self::assertSame('paid',$after['status']);self::assertSame($before['rights'],$after['rights']);}
    }
}
