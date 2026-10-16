<?php

namespace Tests\Feature;

use app\common\enum\AdminTerminalEnum;
use app\tenantapi\lists\finance\AccountLogLists;
use PHPUnit\Framework\TestCase;
use think\facade\Config;
use think\facade\Db;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TenantPointLogRemarksTest extends TestCase
{
    protected function setUp(): void
    {
        (new \think\App())->initialize();
        Config::set(['default' => 'point_log_test', 'connections' => ['point_log_test' => [
            'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'la_',
            'fields_strict' => true, 'fields_cache' => false,
        ]]], 'database');
        request()->tenantId = 1;
        request()->source = AdminTerminalEnum::TENANT;
        foreach ([
            'tenant' => 'id INTEGER PRIMARY KEY, sn TEXT, tactics INTEGER DEFAULT 0, delete_time INTEGER',
            'config' => 'id INTEGER PRIMARY KEY, type TEXT, name TEXT, value TEXT',
            'tenant_config' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, name TEXT, value TEXT',
            'user' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, sn TEXT, nickname TEXT, account TEXT, avatar TEXT, mobile TEXT, delete_time INTEGER',
            'user_account_log' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, sn TEXT, action INTEGER, change_amount NUMERIC, left_amount NUMERIC, change_type INTEGER, source_sn TEXT, remark TEXT, create_time INTEGER, delete_time INTEGER',
        ] as $table => $columns) {
            Db::execute("CREATE TABLE la_{$table} ({$columns})");
        }
        Db::name('tenant')->insertAll([['id' => 1, 'sn' => 'first'], ['id' => 2, 'sn' => 'second']]);
        Db::name('user')->insertAll([
            ['id' => 10, 'tenant_id' => 1, 'sn' => 'USER-10', 'nickname' => '用户甲', 'account' => 'first-user', 'avatar' => 'https://example.invalid/avatar.png', 'mobile' => ''],
            ['id' => 20, 'tenant_id' => 2, 'sn' => 'USER-20', 'nickname' => '用户乙', 'account' => 'second-user', 'avatar' => 'https://example.invalid/avatar.png', 'mobile' => ''],
        ]);
        Db::name('user_account_log')->insertAll([
            ['id' => 1, 'tenant_id' => 1, 'user_id' => 10, 'sn' => 'LOG-GIFT', 'action' => 1, 'change_amount' => 500, 'left_amount' => 10500, 'change_type' => 200, 'source_sn' => '', 'remark' => '大客户赠送', 'create_time' => 1791910142],
            ['id' => 2, 'tenant_id' => 1, 'user_id' => 10, 'sn' => 'LOG-RECHARGE', 'action' => 1, 'change_amount' => 100, 'left_amount' => 10600, 'change_type' => 201, 'source_sn' => 'RECHARGE-123', 'remark' => '', 'create_time' => 1791910143],
            ['id' => 3, 'tenant_id' => 1, 'user_id' => 10, 'sn' => 'LOG-DEDUCT', 'action' => 2, 'change_amount' => 20, 'left_amount' => 10580, 'change_type' => 100, 'source_sn' => '', 'remark' => null, 'create_time' => 1791910144],
            ['id' => 4, 'tenant_id' => 2, 'user_id' => 20, 'sn' => 'LOG-PRIVATE', 'action' => 1, 'change_amount' => 50, 'left_amount' => 50, 'change_type' => 200, 'source_sn' => '', 'remark' => '其他租户私有备注', 'create_time' => 1791910145],
        ]);
    }

    public function testListReturnsHistoricalRemarksAndDistinctLogAndSourceNumbers(): void
    {
        $list = $this->listing();
        $rows = $list->lists();
        self::assertCount(3, $rows);
        self::assertSame(3, $list->count());
        self::assertSame('大客户赠送', $rows[2]['remark']);
        self::assertSame('LOG-GIFT', $rows[2]['log_sn']);
        self::assertSame('USER-10', $rows[2]['sn']);
        self::assertSame('', $rows[2]['source_sn']);
        self::assertSame('RECHARGE-123', $rows[1]['source_sn']);
        self::assertSame('', $rows[1]['remark']);
        self::assertNull($rows[0]['remark']);
        self::assertSame('+500', $rows[2]['change_amount']);
        self::assertSame('-20', $rows[0]['change_amount']);
    }

    public function testRemarksRemainScopedToTheCurrentTenant(): void
    {
        request()->tenantId = 2;
        $list = $this->listing();
        $rows = $list->lists();
        self::assertCount(1, $rows);
        self::assertSame(1, $list->count());
        self::assertSame('其他租户私有备注', $rows[0]['remark']);
        self::assertSame('LOG-PRIVATE', $rows[0]['log_sn']);
    }

    private function listing(): AccountLogLists
    {
        // Isolate the real list query from HTTP pagination/authentication.
        return new class extends AccountLogLists {
            public function __construct()
            {
                $this->params = ['type' => 'um'];
                $this->limitOffset = 0;
                $this->limitLength = 20;
            }
        };
    }
}
