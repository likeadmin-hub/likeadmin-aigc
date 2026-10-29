<?php
namespace Tests\Feature;

use app\common\service\power\MarketPriceWriteTransaction as Transaction;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

final class MarketPriceWriteTransactionTest extends TestCase
{
    private static function deadlock(): \PDOException
    {
        $error = new \PDOException('deadlock');
        $error->errorInfo = ['40001', 1213, 'Deadlock'];
        return $error;
    }

    public function testOnlyStructuredMysqlDeadlocksAreReplayable(): void
    {
        self::assertTrue(Transaction::isDeadlock(self::deadlock()));
        self::assertTrue(Transaction::isDeadlock(new \think\db\exception\PDOException(self::deadlock())));
        self::assertTrue(Transaction::isDeadlock(new \RuntimeException('wrapped', 0, self::deadlock())));
        self::assertFalse(Transaction::isDeadlock(new \RuntimeException('SQLSTATE[40001] 1213 deadlock')));
        $timeout = new \PDOException('lock timeout');
        $timeout->errorInfo = ['HY000', 1205, 'Lock timeout'];
        self::assertFalse(Transaction::isDeadlock($timeout));
    }

    public function testRetryRollsBackEveryWriteAndNestedTransactionsAreNeverReplayed(): void
    {
        if (getenv('MARKET_PRICE_DB_TESTS') !== 'local-existing') self::markTestSkipped('Opt-in local DB only');
        (new \think\App())->initialize();
        $table = 'price_retry_test_' . bin2hex(random_bytes(6));
        Db::execute("CREATE TEMPORARY TABLE `$table` (id INT PRIMARY KEY) ENGINE=InnoDB");
        try {
            $calls = 0;
            $value = Transaction::run(function () use (&$calls, $table) {
                ++$calls;
                Db::execute("INSERT INTO `$table` VALUES (1)");
                if ($calls === 1) throw self::deadlock();
                return 'saved';
            });
            self::assertSame('saved', $value);
            self::assertSame(2, $calls);
            self::assertCount(1, Db::query("SELECT * FROM `$table`"));
            $nested = 0;
            Db::startTrans();
            try {
                Transaction::run(function () use (&$nested) { ++$nested; throw self::deadlock(); });
                self::fail('Deadlock must escape an outer transaction');
            } catch (\PDOException $error) {
                self::assertSame(1, $nested);
            } finally { Db::rollback(); }
        } finally { Db::execute("DROP TEMPORARY TABLE IF EXISTS `$table`"); }
    }
}
