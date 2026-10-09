<?php
namespace Tests\Feature;
use app\common\service\app\aigc_short_drama\vnext\LocalTransaction;
use PHPUnit\Framework\TestCase;
final class ShortDramaVnextLocalTransactionTest extends TestCase
{
    public function testOnlyAnExplicitDatabaseDeadlockIsEligibleNotTimeoutOrProviderStrings(): void
    {
        foreach ([['40001', 1213, true], ['HY000', 1205, false], ['40001', 1205, false]] as [$state, $code, $expected]) {
            $pdo = new \PDOException('deadlock'); $pdo->errorInfo = [$state, $code, 'fixture'];
            self::assertSame($expected, LocalTransaction::rolledBackDeadlock($pdo));
            self::assertSame($expected, LocalTransaction::rolledBackDeadlock(new \think\db\exception\PDOException($pdo)));
        }
        self::assertFalse(LocalTransaction::rolledBackDeadlock(new \RuntimeException('SQLSTATE[40001] 1213 Deadlock found', 40001)));
    }
}
