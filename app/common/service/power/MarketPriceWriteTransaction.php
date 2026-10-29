<?php
declare(strict_types=1);
namespace app\common\service\power;

use think\facade\Db;
use Throwable;

/** Replay only fully rolled-back local configuration writes, never supplier/billing work. */
final class MarketPriceWriteTransaction
{
    public static function isDeadlock(Throwable $error): bool
    {
        do {
            if ($error instanceof \PDOException) {
                $info = $error->errorInfo;
                if (($info[0] ?? '') === '40001' && (int)($info[1] ?? 0) === 1213) return true;
            }
            if ($error instanceof \think\db\exception\PDOException) {
                $info = $error->getData()['PDO Error Info'] ?? [];
                if (($info['SQLSTATE'] ?? '') === '40001' && (int)($info['Driver Error Code'] ?? 0) === 1213) return true;
            }
            $error = $error->getPrevious();
        } while ($error !== null);
        return false;
    }

    public static function run(callable $write)
    {
        $connection = Db::connect();
        // The outer owner is responsible for recovering its whole transaction.
        $pdo = $connection->getPdo();
        $nested = $pdo !== false && $pdo->inTransaction();
        for ($attempt = 0; ; ++$attempt) {
            try { return $connection->transaction($write); }
            catch (Throwable $error) {
                if ($nested || $attempt >= 2 || !self::isDeadlock($error)) throw $error;
                usleep(random_int(20000, 60000) * ($attempt + 1));
            }
        }
    }
}
