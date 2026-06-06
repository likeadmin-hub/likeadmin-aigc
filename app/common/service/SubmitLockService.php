<?php

namespace app\common\service;

use Exception;

class SubmitLockService
{
    public static function acquire(string $action, int $tenantId = 0, int $userId = 0, bool $blocking = false)
    {
        $lockDir = runtime_path() . 'submit_lock' . DIRECTORY_SEPARATOR;
        if (!is_dir($lockDir) && !mkdir($lockDir, 0777, true) && !is_dir($lockDir)) {
            throw new Exception('系统繁忙，请稍后重试');
        }

        $lockFile = $lockDir . sha1($action . '|' . $tenantId . '|' . $userId) . '.lock';
        $handle = fopen($lockFile, 'c+');
        if (!$handle) {
            throw new Exception('系统繁忙，请稍后重试');
        }

        $lockFlag = $blocking ? LOCK_EX : (LOCK_EX | LOCK_NB);
        if (!flock($handle, $lockFlag)) {
            fclose($handle);
            throw new Exception('请求正在处理中，请勿重复提交');
        }

        ftruncate($handle, 0);
        fwrite($handle, (string)getmypid());
        fflush($handle);

        return $handle;
    }

    public static function tryAcquire(string $action, int $tenantId = 0, int $userId = 0)
    {
        try {
            return self::acquire($action, $tenantId, $userId);
        } catch (Exception $e) {
            if ($e->getMessage() === '请求正在处理中，请勿重复提交') {
                return null;
            }
            throw $e;
        }
    }

    public static function release($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
