<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use think\facade\Db;

/** Retry only complete local transactions that MySQL explicitly rolled back. Never wraps provider I/O. */
final class LocalTransaction
{
    public static function run(callable $callback)
    {
        $pdo = Db::connect()->getPdo();
        // An outer transaction may contain work outside this callback. Its owner
        // must handle rollback; retrying only this nested portion would lose data.
        $nested = $pdo && $pdo->inTransaction();
        for ($attempt = 0; ; $attempt++) {
            try { return Db::transaction($callback); }
            catch (\Throwable $error) {
                if ($nested || $attempt >= 2 || !self::rolledBackDeadlock($error)) throw $error;
                usleep(($attempt + 1) * 10000 + random_int(0, 10000));
            }
        }
    }

    public static function rolledBackDeadlock(\Throwable $error): bool
    {
        if ($error instanceof \think\db\exception\PDOException) {
            $info = $error->getData()['PDO Error Info'] ?? [];
            return ($info['SQLSTATE'] ?? '') === '40001' && (int)($info['Driver Error Code'] ?? 0) === 1213;
        }
        if ($error instanceof \PDOException) return ($error->errorInfo[0] ?? '') === '40001' && (int)($error->errorInfo[1] ?? 0) === 1213;
        return false;
    }
}
