<?php

use app\common\enum\AdminTerminalEnum;
use app\common\enum\user\UserTerminalEnum as Terminal;
use app\common\service\pay\WeChatPayService;
use app\common\service\promotion\PackagePaymentGateway;
use app\common\service\promotion\PackagePaymentService;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/** SQLite in memory and a controlled SDK HTTP boundary; never contacts a merchant. */
class WechatNativePaymentTest extends TestCase
{
    protected function setUp(): void
    {
        (new think\App(dirname(__DIR__, 2)))->initialize();
        $connection='native_test_'.bin2hex(random_bytes(4));
        Config::set(['default'=>$connection,'connections'=>[$connection=>[
            'type'=>'sqlite','database'=>':memory:','prefix'=>'la_','fields_strict'=>true,'fields_cache'=>false,
        ]]], 'database');
        request()->tenantId=1;
        request()->source=AdminTerminalEnum::USER;
        foreach ([
            'tenant'=>'id INTEGER PRIMARY KEY, sn TEXT, tactics INTEGER DEFAULT 0, delete_time INTEGER',
            'tenant_config'=>'id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT',
            'config'=>'id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT',
            'tenant_pay_config'=>'id INTEGER PRIMARY KEY, tenant_id INTEGER, pay_way INTEGER, config TEXT',
            'pay_config'=>'id INTEGER PRIMARY KEY, pay_way INTEGER, config TEXT',
            'user_auth'=>"id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, terminal INTEGER, appid TEXT DEFAULT '', openid TEXT",
            'recharge_order'=>"id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, sn TEXT, pay_sn TEXT, pay_way INTEGER, pay_status INTEGER, order_amount TEXT, delete_time INTEGER",
            'tenant_package_price_snapshot'=>"id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, biz_type TEXT, order_id INTEGER, payable_cents INTEGER, payment_context TEXT",
        ] as $name=>$fields) Db::execute("CREATE TABLE la_{$name} ({$fields})");
        Db::name('tenant')->insertAll([['id'=>1,'sn'=>'one'],['id'=>2,'sn'=>'two']]);
        Db::name('tenant_pay_config')->insertAll([
            ['tenant_id'=>1,'pay_way'=>2,'config'=>'{"mch_id":"tenant-one"}'],
            ['tenant_id'=>2,'pay_way'=>2,'config'=>'{"mch_id":"tenant-two"}'],
        ]);
        Db::name('pay_config')->insert(['pay_way'=>2,'config'=>'{"app_id":"platform-pay","mch_id":"platform-merchant"}']);
    }

    private function channel(string $type, string $app, int $tenant=1): void
    {
        Db::name('tenant_config')->where(['tenant_id'=>$tenant,'type'=>$type,'name'=>'app_id'])->delete();
        Db::name('tenant_config')->insert(['tenant_id'=>$tenant,'type'=>$type,'name'=>'app_id','value'=>$app]);
    }

    private function sdk(int $terminal=Terminal::PC, bool $platform=false): array
    {
        $sdk=new WeChatPayService($terminal,7,$platform);
        $client=new class {
            public array $calls=[];
            public function postJson($uri,$payload) {
                $this->calls[]=[$uri,$payload];
                return new class {
                    public function toArray($throw) {return ['code_url'=>'offline-qr','prepay_id'=>'offline-prepay','h5_url'=>'https://example.invalid/h5'];}
                };
            }
        };
        $app=new class($client) {
            public function __construct(private $client) {}
            public function getClient() {return $this->client;}
            public function getUtils() {return $this;}
            public function buildBridgeConfig($prepay,$app) {return ['appId'=>$app,'prepay_id'=>$prepay];}
        };
        (new ReflectionProperty($sdk,'app'))->setValue($sdk,$app);
        return [$sdk,$client];
    }

    private function order(): array
    {
        return ['id'=>9,'pay_sn'=>'offline-order','order_amount'=>'0.29','redirect_url'=>'/result'];
    }

    /** @dataProvider nativeChannels */
    public function testNativeUsesOneIdentityForContextAndPayload(string $oa,string $mini,string $expected): void
    {
        $this->channel('oa_setting',$oa);
        $this->channel('mnp_setting',$mini);
        [$sdk,$http]=$this->sdk();
        $context=$sdk->packageContext();
        self::assertSame($expected,$context['app_id']);
        self::assertSame('tenant-one',$context['merchant']);
        self::assertSame(['config'=>'offline-qr','pay_way'=>2],$sdk->pay('recharge',$this->order()));
        self::assertSame('v3/pay/transactions/native',$http->calls[0][0]);
        self::assertSame($context['app_id'],$http->calls[0][1]['appid']);
        self::assertSame(29,$http->calls[0][1]['amount']['total']);
        self::assertArrayNotHasKey('payer',$http->calls[0][1]);
        if($oa==='official') {
            // Existing OA snapshots keep the exact pre-fix fingerprint representation.
            $stable=(new ReflectionProperty($sdk,'config'))->getValue($sdk);
            unset($stable['http'],$stable['notify_url']);
            $legacy=['app_id'=>'official','merchant'=>'tenant-one'];
            self::assertSame(hash('sha256',json_encode([$legacy,$stable],JSON_THROW_ON_ERROR)),$context['fingerprint']);
        }
    }

    public function nativeChannels(): array
    {
        return [['official','','official'],['','mini','mini'],['official','mini','official'],['  ',' mini ','mini']];
    }

    public function testMissingTenantChannelsCannotUseAnotherTenantOrPlatformOrProjectDefaults(): void
    {
        $this->channel('mnp_setting','other-tenant',2);
        Db::name('config')->insertAll([
            ['type'=>'oa_setting','name'=>'app_id','value'=>'platform-official'],
            ['type'=>'mnp_setting','name'=>'app_id','value'=>'platform-mini'],
        ]);
        Config::set(['oa_setting'=>['app_id'=>'project-official'],'mnp_setting'=>['app_id'=>'project-mini']], 'project');
        [$sdk,$http]=$this->sdk();
        self::assertFalse($sdk->pay('recharge',$this->order()));
        self::assertStringContainsString('AppID',$sdk->getError());
        self::assertSame([],$http->calls);
        $this->expectException(RuntimeException::class);
        $sdk->packageContext();
    }

    public function testNativeTenantIdentityDoesNotDependOnRequestSource(): void
    {
        $this->channel('mnp_setting','tenant-mini');
        Db::name('config')->insert(['type'=>'oa_setting','name'=>'app_id','value'=>'platform-official']);
        request()->source=AdminTerminalEnum::PLATFORM;
        [$sdk,$http]=$this->sdk();
        self::assertSame('tenant-mini',$sdk->packageContext()['app_id']);
    }

    public function testTenantSwitchUsesItsOwnMerchantAndApp(): void
    {
        $this->channel('oa_setting','tenant-one-official');
        $this->channel('mnp_setting','tenant-two-mini',2);
        request()->tenantId=2;
        [$sdk,$http]=$this->sdk();
        self::assertSame('tenant-two-mini',$sdk->packageContext()['app_id']);
        self::assertSame('tenant-two',$sdk->packageContext()['merchant']);
    }

    public function testPlatformPaymentUsesOnlyItsIndependentPaymentAppId(): void
    {
        $this->channel('oa_setting','tenant-official');
        $this->channel('mnp_setting','tenant-mini');
        [$sdk,$http]=$this->sdk(Terminal::PC,true);
        self::assertSame('platform-pay',$sdk->packageContext()['app_id']);
        self::assertSame('platform-merchant',$sdk->packageContext()['merchant']);
        $sdk->pay('tenant_power',$this->order());
        self::assertSame('platform-pay',$http->calls[0][1]['appid']);
        Db::name('pay_config')->where('pay_way',2)->update(['config'=>'{"mch_id":"platform-merchant"}']);
        [$missing,$http]=$this->sdk(Terminal::PC,true);
        self::assertFalse($missing->pay('tenant_power',$this->order()));
        self::assertSame([],$http->calls);
    }

    public function testPreparedIdentityRemainsTheSameUntilNextGatewayContext(): void
    {
        $this->channel('mnp_setting','mini-original');
        [$sdk,$http]=$this->sdk();
        $old=$sdk->packageContext();
        $this->channel('oa_setting','official-new');
        $sdk->pay('recharge',$this->order());
        self::assertSame('mini-original',$http->calls[0][1]['appid']);
        self::assertSame($old,$sdk->packageContext());

        $gateway=new PackagePaymentGateway();
        $first=$gateway->call('context',2,Terminal::PC,7);
        $this->channel('oa_setting','official-modified');
        $second=$gateway->call('context',2,Terminal::PC,7);
        self::assertSame('official-modified',$second['app_id']);
        self::assertNotSame($first,$second);
        Db::name('tenant_pay_config')->where('tenant_id',1)->update(['config'=>'{"mch_id":"merchant-modified"}']);
        $third=$gateway->call('context',2,Terminal::PC,7);
        self::assertSame('merchant-modified',$third['merchant']);
        self::assertNotSame($second,$third);
    }

    /** @dataProvider unchangedTerminals */
    public function testOtherTerminalsRetainTheirOriginalChannels(int $terminal,string $app,string $uri): void
    {
        $this->channel('oa_setting','official');
        $this->channel('mnp_setting','mini');
        $this->channel('open_platform','app');
        Db::name('user_auth')->insert(['tenant_id'=>1,'user_id'=>7,'terminal'=>$terminal,'appid'=>$app,'openid'=>'fixture-openid']);
        [$sdk,$http]=$this->sdk($terminal);
        self::assertSame($app,$sdk->packageContext()['app_id']);
        self::assertIsArray($sdk->pay('recharge',$this->order()));
        self::assertSame($uri,$http->calls[0][0]);
        self::assertSame($app,$http->calls[0][1]['appid']);
    }

    public function unchangedTerminals(): array
    {
        return [
            [Terminal::WECHAT_MMP,'mini','v3/pay/transactions/jsapi'],
            [Terminal::WECHAT_OA,'official','v3/pay/transactions/jsapi'],
            [Terminal::H5,'official','v3/pay/transactions/h5'],
            [Terminal::IOS,'app','v3/pay/transactions/app'],
            [Terminal::ANDROID,'app','v3/pay/transactions/app'],
        ];
    }

    public function testH5StillRequiresOfficialAppIdInsteadOfFallingBackToMini(): void
    {
        $this->channel('oa_setting','');
        $this->channel('mnp_setting','mini');
        [$sdk,$http]=$this->sdk(Terminal::H5);
        self::assertFalse($sdk->pay('recharge',$this->order()));
        self::assertSame([],$http->calls);
    }

    /** @dataProvider changedReceipts */
    public function testFrozenInspectionStillRejectsEveryIdentityOrReceiptMismatch(string $change): void
    {
        $this->channel('mnp_setting','mini-original');
        $identity=(new WeChatPayService(Terminal::PC))->packageContext();
        $frozen=json_encode(['terminal'=>Terminal::PC,'identity'=>$identity]);
        Db::name('recharge_order')->insert(['id'=>9,'tenant_id'=>1,'user_id'=>7,'sn'=>'order','pay_sn'=>'offline-order','pay_way'=>2,'pay_status'=>0,'order_amount'=>'0.29']);
        Db::name('tenant_package_price_snapshot')->insert(['id'=>1,'tenant_id'=>1,'user_id'=>7,'biz_type'=>'recharge','order_id'=>9,'payable_cents'=>29,'payment_context'=>$frozen]);
        if($change==='config')$this->channel('oa_setting','new-official');
        if($change==='merchant-config')Db::name('tenant_pay_config')->where('tenant_id',1)->update(['config'=>'{"mch_id":"new-merchant"}']);
        $queried=false;
        $gateway=new PackagePaymentGateway(function($action)use($change,$identity,&$queried){
            if($action==='context')return (new WeChatPayService(Terminal::PC))->packageContext();
            $queried=true;
            $receipt=['state'=>'NOTPAY','order_sn'=>'offline-order','transaction'=>'offline-tx','amount_cents'=>29,'currency'=>'CNY','app_id'=>$identity['app_id'],'merchant'=>$identity['merchant']];
            if(in_array($change,['order_sn','app_id','merchant','currency'],true))$receipt[$change]='wrong';
            if($change==='amount_cents')$receipt['amount_cents']=30;
            return $receipt;
        });
        try {
            (new PackagePaymentService($gateway))->reconcile(1,7,'recharge',9);
            self::assertSame('unchanged',$change);
        } catch(RuntimeException $e) {
            self::assertNotSame('unchanged',$change);
            self::assertStringContainsString(in_array($change,['config','merchant-config'])?'原收款配置已变化':'不匹配',$e->getMessage());
        }
        self::assertSame(!in_array($change,['config','merchant-config']),$queried);
        self::assertSame($frozen,Db::name('tenant_package_price_snapshot')->where('id',1)->value('payment_context'));
        self::assertSame(0,Db::name('recharge_order')->where('id',9)->value('pay_status'));
    }

    public function changedReceipts(): array
    {
        return array_map(fn($key)=>[$key],['unchanged','config','merchant-config','order_sn','app_id','merchant','currency','amount_cents']);
    }
}
