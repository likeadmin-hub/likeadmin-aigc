<?php

use app\common\command\Crontab;
use app\common\command\ExpireTenantBrandOrders;
use app\common\command\ExpireTenantContracts;
use app\common\command\SettleDistributionCommission;
use app\common\enum\AdminTerminalEnum;
use app\common\enum\CrontabEnum;
use app\common\service\ConfigService;
use PHPUnit\Framework\TestCase;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Config;
use think\facade\Db;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class IssueIkjf3nRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App())->initialize();
        Config::set(['default' => 'issue_test', 'connections' => ['issue_test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_', 'fields_strict' => true, 'fields_cache' => false,
        ]]], 'database');
        request()->source = AdminTerminalEnum::PLATFORM;
        foreach ([
            'config' => 'id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT',
            'tenant_config' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT',
            'tenant' => 'id INTEGER PRIMARY KEY, tactics INTEGER DEFAULT 0, contract_status INTEGER, contract_expire_time INTEGER, disable INTEGER, update_time INTEGER, delete_time INTEGER',
            'tenant_brand_order' => 'id INTEGER PRIMARY KEY, pay_status INTEGER, reserve_status INTEGER, reserve_expire_time INTEGER, order_sn TEXT, delete_time INTEGER',
            'distribution_commission' => 'id INTEGER PRIMARY KEY, status TEXT, settle_time INTEGER',
            'dev_crontab' => 'id INTEGER PRIMARY KEY, status INTEGER, command TEXT, params TEXT, expression TEXT, last_time INTEGER, time NUMERIC, max_time NUMERIC, error TEXT, delete_time INTEGER',
        ] as $name => $columns) {
            Db::execute("CREATE TABLE la_{$name} ({$columns})");
        }
    }

    public function testCommandsCompleteWithSuccessExitCode(): void
    {
        foreach ([new ExpireTenantBrandOrders(), new ExpireTenantContracts(), new SettleDistributionCommission()] as $command) {
            self::assertSame(0, $command->run(new Input([]), new Output('buffer')));
        }
    }

    public function testSchedulerContinuesAfterPhpErrorAndRecordsFailure(): void
    {
        $console = app()->console;
        $console->addCommand(new class extends Command {
            protected function configure() { $this->setName('issue:throw'); }
            protected function execute(Input $input, Output $output) { throw new \Error('simulated PHP Error'); }
        });
        $console->addCommand(new class extends Command {
            protected function configure() { $this->setName('issue:next'); }
            protected function execute(Input $input, Output $output) { ConfigService::set('issue', 'next_executed', 1); return 0; }
        });
        foreach ([1 => 'issue:throw', 2 => 'issue:next'] as $id => $command) {
            Db::name('dev_crontab')->insert(['id' => $id, 'status' => CrontabEnum::START, 'command' => $command,
                'params' => '', 'expression' => '* * * * *', 'last_time' => time() - 120, 'max_time' => 0, 'error' => 'old error']);
        }
        (new Crontab())->run(new Input([]), new Output('buffer'));
        $failed = Db::name('dev_crontab')->where('id', 1)->find();
        self::assertSame(CrontabEnum::ERROR, (int)$failed['status']);
        self::assertSame('simulated PHP Error', $failed['error']);
        self::assertGreaterThan(time() - 10, (int)$failed['last_time']);
        self::assertSame(1, ConfigService::get('issue', 'next_executed'));
        self::assertSame('', Db::name('dev_crontab')->where('id', 2)->value('error'));
    }

    public function testBothRegisterSettingsAcceptMissingQqAndValidateProvidedSwitch(): void
    {
        Db::name('tenant')->insert(['id' => 1, 'tactics' => 0]);
        foreach (['platformapi' => AdminTerminalEnum::PLATFORM, 'tenantapi' => AdminTerminalEnum::TENANT] as $terminal => $source) {
            request()->source = $source;
            request()->tenantId = 1;
            $validator = "app\\{$terminal}\\validate\\setting\\UserConfigValidate";
            $logic = "app\\{$terminal}\\logic\\setting\\user\\UserLogic";
            $params = ['login_way' => ['1', '2'], 'coerce_mobile' => 0, 'login_agreement' => 1, 'third_auth' => 0, 'wechat_auth' => 0];
            self::assertTrue((new $validator())->scene('register')->check($params));
            self::assertTrue($logic::setRegisterConfig($params));
            self::assertSame(0, ConfigService::get('login', 'qq_auth'));
            self::assertSame(1, ConfigService::get('login', 'login_agreement'));
            $params['qq_auth'] = '1';
            self::assertTrue((new $validator())->scene('register')->check($params));
            self::assertTrue($logic::setRegisterConfig($params));
            self::assertSame(1, ConfigService::get('login', 'qq_auth'));
            $params['qq_auth'] = 2;
            self::assertFalse((new $validator())->scene('register')->check($params));
        }
    }
}
