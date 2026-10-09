<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Private local write-ahead receipts, outside public storage. No provider call without a writable spool. */
final class ReceiptBuffer
{
    private string $directory;
    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/');
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) throw new RuntimeException('模型收据缓冲不可用', 503);
        if (!is_writable($this->directory)) throw new RuntimeException('模型收据缓冲不可写', 503);
    }
    public function key(array $scope, string $signature): string { return ContentDocument::hash([$scope, $signature]); }
    public function read(string $key): ?array
    {
        $path = $this->path($key);
        if (!is_file($path)) return null;
        $raw = file_get_contents($path);
        $envelope = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!hash_equals($envelope['hash'] ?? '', ContentDocument::hash($envelope['receipt'] ?? []))) throw new RuntimeException('收据缓冲校验失败', 409);
        return $envelope['receipt'];
    }
    public function write(string $key, array $receipt): void
    {
        $existing = $this->read($key);
        if ($existing !== null) {
            if (ContentDocument::hash($existing) !== ContentDocument::hash($receipt)) throw new RuntimeException('同一请求存在冲突收据', 409);
            return;
        }
        $temporary = tempnam($this->directory, '.receipt-');
        if (!$temporary) throw new RuntimeException('无法保存模型收据', 503);
        try {
            chmod($temporary, 0600);
            $json = json_encode(['hash' => ContentDocument::hash($receipt), 'receipt' => $receipt], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $handle = fopen($temporary, 'wb');
            if (!$handle) throw new RuntimeException('无法保存模型收据', 503);
            try {
                if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) throw new RuntimeException('模型收据写入未完成', 503);
                if (function_exists('fsync') && !fsync($handle)) throw new RuntimeException('模型收据未同步到磁盘', 503);
            } finally { fclose($handle); }
            // Hard-link creation is atomic and never replaces an earlier worker's receipt.
            if (!@link($temporary, $this->path($key))) {
                $saved = $this->read($key);
                if ($saved === null || ContentDocument::hash($saved) !== ContentDocument::hash($receipt)) throw new RuntimeException('无法提交模型收据缓冲', 503);
            }
        } finally { if (is_file($temporary)) unlink($temporary); }
    }
    private function path(string $key): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) throw new RuntimeException('收据标识无效', 422);
        return $this->directory . '/' . $key . '.json';
    }
}
