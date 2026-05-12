<?php

namespace app\common\service;

use Exception;

class SubmitLockService
{
    public static function acquire(string $action, int $tenantId = 0, int $userId = 0)
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

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new Exception('请求正在处理中，请勿重复提交');
        }

        ftruncate($handle, 0);
        fwrite($handle, (string)getmypid());
        fflush($handle);

        return $handle;
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
