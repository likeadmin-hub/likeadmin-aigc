<?php

namespace app\common\service\storage\engine;


use app\common\service\storage\engine\Server;

/**
 * 本地文件驱动
 * Class Local
 * @package app\common\library\storage\drivers
 */
class Local extends Server
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 上传
     * @param $save_dir (保存路径)
     * @return bool
     */
    public function upload($save_dir)
    {
        if ($this->isInternal) {
            $dir = rtrim(public_path(), '/') . '/' . trim($save_dir, '/');
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            if (!copy($this->getRealPath(), $dir . '/' . $this->fileName)) {
                $this->error = '内部文件保存失败';
                return false;
            }
            return true;
        }
        // 验证文件并上传
        $info = $this->file->move($save_dir, $this->fileName);
        if (empty($info)) {
            $this->error = $this->file->getError();
            return false;
        }
        return true;
    }

    public function fetch($url, $key=null) {}

    /**
     * Serialize writers for one immutable result key. Never copy over its public URI:
     * process death leaves a recoverable sibling .part, while rename publishes only verified bytes.
     * The stable empty lock file must remain; unlinking it could split waiting writers across inodes.
     */
    public function uploadManaged(string $saveDir, string $checksum, int $bytes): bool
    {
        $lock = null;
        try {
            if (!$this->isInternal || $bytes < 1 || !preg_match('/^[a-f0-9]{64}$/D', $checksum)
                || !preg_match('~^uploads/ai_result/[0-9]+/[0-9]+$~D', $saveDir)
                || !preg_match('/^asset-[1-9][0-9]*-' . $checksum . '\\.[a-z0-9]+$/D', (string)$this->fileName)
                || !self::matches($this->getRealPath(), $checksum, $bytes)) {
                throw new \RuntimeException('受管结果文件或目标无效');
            }
            $dir = rtrim(public_path(), '/') . '/' . $saveDir;
            if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) throw new \RuntimeException('结果目录创建失败');
            $target = $dir . '/' . $this->fileName;
            $part = $dir . '/.' . $this->fileName . '.part';
            $lockPath = $dir . '/.' . $this->fileName . '.lock';
            if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) throw new \RuntimeException('结果文件锁无效');
            $lock = fopen($lockPath, 'c+b');
            if (!is_resource($lock) || !flock($lock, LOCK_EX)) throw new \RuntimeException('结果文件锁获取失败');
            // No database transaction is held while waiting for this file lock or copying bytes.
            if (is_link($target) || is_link($part) || (file_exists($target) && !is_file($target))
                || (file_exists($part) && !is_file($part))) throw new \RuntimeException('结果文件目标无效');
            if (self::matches($target, $checksum, $bytes)) {
                if (is_file($part) && !unlink($part)) throw new \RuntimeException('结果暂存清理失败');
                return true;
            }
            if (!self::matches($part, $checksum, $bytes)) {
                $temporary = fopen($part, 'c+b');
                if (!is_resource($temporary)) throw new \RuntimeException('结果暂存创建失败');
                fclose($temporary);
                if (!chmod($part, 0600) || !copy($this->getRealPath(), $part) || !self::matches($part, $checksum, $bytes)) {
                    throw new \RuntimeException('结果暂存校验失败');
                }
            }
            if (!chmod($part, 0644) || !rename($part, $target)) throw new \RuntimeException('结果文件发布失败');
            return true;
        } catch (\Throwable $error) {
            $this->error = $error->getMessage();
            return false;
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private static function matches(string $path, string $checksum, int $bytes): bool
    {
        clearstatcache(true, $path);
        return is_file($path) && !is_link($path) && filesize($path) === $bytes && hash_file('sha256', $path) === $checksum;
    }

    /**
     * 删除文件
     * @param $fileName
     * @return bool|mixed
     */
    public function delete($fileName)
    {
        $check = strpos($fileName, '/');
        if ($check !== false && $check == 0) {
            // 文件所在目录
            $fileName = substr_replace($fileName,"",0,1);
        }
        $filePath = public_path() . "{$fileName}";
        return !file_exists($filePath) ?: unlink($filePath);
    }

    /**
     * 返回文件路径
     * @return mixed
     */
    public function getFileName()
    {
        return $this->fileName;
    }
}
