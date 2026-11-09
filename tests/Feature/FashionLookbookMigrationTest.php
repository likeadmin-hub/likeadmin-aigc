<?php
namespace Tests\Feature;

use app\common\service\app\AppRegistryService;
use app\common\service\database\SqlMigrationExecutor;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

/** @runTestsInSeparateProcesses
 *  @preserveGlobalState disabled
 */
final class FashionLookbookMigrationTest extends TestCase
{
    private const MIGRATION = 'zz_20261109_fashion_lookbook_try_on_mode.sql';

    public function testOutfitUpgradePassesTheRealHistoricalMigrationGate(): void
    {
        (new \think\App())->initialize();
        $entries = AppRegistryService::validateRuntimeMigrations('aigc_fashion_lookbook');
        self::assertTrue($entries['install.sql']['historical']);
        self::assertNotEmpty($entries[self::MIGRATION]['plan']['prepare']);
        self::assertSame('', $entries[self::MIGRATION]['plan']['complete']);
    }

    public function testExistingTasksKeepIndividualModeAndUpgradeCanBeReplayed(): void
    {
        if (getenv('FASHION_LOOKBOOK_MYSQL_TEST') !== '1') self::markTestSkipped('Local MySQL temporary-table verification only');
        (new \think\App())->initialize();
        $db = Db::connect();
        self::assertContains($db->getConfig('hostname'), ['127.0.0.1', 'localhost']);
        $prefix = 'lookbook_test_'.bin2hex(random_bytes(5)).'_';
        $table = $prefix.'aigc_fashion_lookbook_task';
        $db->execute("CREATE TEMPORARY TABLE `{$table}` (id INT PRIMARY KEY, quantity INT NOT NULL, user_charge_points DECIMAL(12,2) NOT NULL)");
        try {
            $db->execute("INSERT INTO `{$table}` VALUES (1, 3, 30.00)");
            $sql = file_get_contents(dirname(__DIR__, 2).'/app/apps/aigc_fashion_lookbook/migrations/'.self::MIGRATION);
            SqlMigrationExecutor::execute($sql, $prefix, $db);
            SqlMigrationExecutor::execute($sql, $prefix, $db);
            self::assertSame(['id'=>1, 'quantity'=>3, 'user_charge_points'=>'30.00', 'try_on_mode'=>'individual'], $db->query("SELECT * FROM `{$table}`")[0]);
            $db->execute("INSERT INTO `{$table}` VALUES (2, 1, 10.00, 'outfit')");
            self::assertSame('outfit', $db->query("SELECT try_on_mode FROM `{$table}` WHERE id=2")[0]['try_on_mode']);
        } finally {
            $db->execute("DROP TEMPORARY TABLE IF EXISTS `{$table}`");
        }
    }
}
