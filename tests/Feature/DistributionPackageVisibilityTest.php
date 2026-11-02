<?php
namespace Tests\Feature;

use app\common\service\distribution\DistributionService as Distribution;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class DistributionPackageVisibilityTest extends TestCase
{
    private const TENANT = 2000000810;
    protected function setUp(): void
    {
        if (getenv('DISTRIBUTION_PACKAGE_DB_TESTS') !== 'local-existing') self::markTestSkipped('Explicit local rollback fixture only');
        (new \think\App())->initialize();
        self::assertSame('127.0.0.1', config('database.connections.mysql.hostname'));
        Db::startTrans();
    }
    protected function tearDown(): void
    {
        if (getenv('DISTRIBUTION_PACKAGE_DB_TESTS') === 'local-existing') Db::rollback();
    }
    private function package(string $type, array $extra = []): int
    {
        $table = $type === 'membership' ? 'membership_plan' : 'recharge_package';
        $price = $type === 'membership' ? ['monthly_price'=>'39.90', 'yearly_price'=>'299.00'] : ['amount'=>'19.90'];
        return (int)Db::name($table)->insertGetId(array_merge(['tenant_id'=>self::TENANT, 'name'=>'distribution-fixture', 'status'=>1, 'sort'=>0, 'delete_time'=>null], $price, $extra));
    }
    public function testOnlyActiveNonDeletedPackagesOfThisTenantAppearInBothTabs(): void
    {
        foreach (['membership','recharge'] as $type) {
            $active = $this->package($type);
            $this->package($type, ['status'=>0]);
            $this->package($type, ['delete_time'=>time()]);
            $this->package($type, ['tenant_id'=>self::TENANT+1]);
            $rows = Distribution::packageRulesForTenant(self::TENANT, $type);
            self::assertSame([$active], array_column($rows, 'id'));
        }
        self::assertCount(2, Distribution::packageRulesForTenant(self::TENANT));
    }
    public function testMembershipPriceUsesTheFixedPackagePriceAndKeepsFreeZero(): void
    {
        $this->package('membership');
        $this->package('membership', ['monthly_price'=>'0.00', 'yearly_price'=>'0.00']);
        $rows = Distribution::packageRulesForTenant(self::TENANT, 'membership');
        self::assertSame('39.90', $rows[0]['price'] ?? null);
        self::assertSame('0.00', $rows[1]['price'] ?? null);
        $this->package('recharge');
        self::assertSame('19.90', Distribution::packageRulesForTenant(self::TENANT, 'recharge')[0]['amount']);
    }
    public function testStaleDeletedForeignAndMissingPackagesCannotReceiveNewRules(): void
    {
        foreach (['membership','recharge'] as $type) {
            foreach ([['status'=>0], ['delete_time'=>time()], ['tenant_id'=>self::TENANT+1]] as $extra) {
                $id = $this->package($type, $extra);
                try {
                    Distribution::savePackageRule(self::TENANT, $type, $id, ['rule_mode'=>'custom', 'level1_rate'=>12]);
                    self::fail('Unavailable package must be rejected');
                } catch (\RuntimeException $e) {
                    self::assertSame('套餐不存在或已停用，请刷新列表', $e->getMessage());
                }
            }
            $id = $this->package($type);
            Db::name($type === 'membership' ? 'membership_plan' : 'recharge_package')->where('id',$id)->delete();
            try {
                Distribution::savePackageRule(self::TENANT, $type, $id, []);
                self::fail('Missing package must be rejected');
            } catch (\RuntimeException $e) {
                self::assertSame('套餐不存在或已停用，请刷新列表', $e->getMessage());
            }
        }
        self::assertSame(0, Db::name('distribution_package_rule')->where('tenant_id',self::TENANT)->count());
    }
    public function testDisablingHidesWithoutDeletingRulesAndReenablingRestoresThem(): void
    {
        Db::name('distribution_config')->insert(['tenant_id'=>self::TENANT,'status'=>1,'level_count'=>2]);
        foreach (['membership','recharge'] as $type) {
            $id = $this->package($type);
            Distribution::savePackageRule(self::TENANT, $type, $id, ['rule_mode'=>'custom', 'level1_rate'=>12, 'level2_rate'=>3]);
            $before = Distribution::packageRule(self::TENANT, $type, $id);
            $effective = Distribution::effectiveRule(self::TENANT, $type, $id);
            $table = $type === 'membership' ? 'membership_plan' : 'recharge_package';
            Db::name($table)->where('id',$id)->update(['status'=>0]);
            self::assertSame([], Distribution::packageRulesForTenant(self::TENANT, $type));
            self::assertSame($before, Distribution::packageRule(self::TENANT, $type, $id));
            self::assertSame($effective, Distribution::effectiveRule(self::TENANT, $type, $id));
            Db::name($table)->where('id',$id)->update(['status'=>1]);
            self::assertSame($before, Distribution::packageRulesForTenant(self::TENANT, $type)[0]['rule']);
        }
    }
}
