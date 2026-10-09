<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Copy owned recordings within their frozen byte budget, before running FFprobe. */
final class AudioMediaLoader
{
    public static function remote(string $url, string $target, int $expectedBytes): void
    {
        self::budget($expectedBytes);
        if (!preg_match('#^https?://#i', $url)) throw new RuntimeException('配音存储地址不可用', 409);
        if (!function_exists('curl_init')) throw new RuntimeException('配音远程读取组件暂不可用');
        $write = self::open($target); $bytes = 0; $oversized = false; $ch = curl_init($url);
        try {
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'LikeAdmin-AIGC-RecordingProbe/1.0',
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($write, $expectedBytes, &$bytes, &$oversized): int {
                    if ($bytes + strlen($chunk) > $expectedBytes) { $oversized = true; return 0; }
                    $count = self::write($write, $chunk); $bytes += $count;
                    return $count;
                },
            ]);
            $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($oversized) throw new RuntimeException('配音文件内容已变化，请重新上传', 409);
            if ($status === 404 || $status === 410) throw new RuntimeException('配音文件已不存在，请重新上传', 404);
            if ($ok !== true || $status < 200 || $status >= 300) throw new RuntimeException('配音文件暂时读取失败');
            if ($bytes !== $expectedBytes) throw new RuntimeException('配音文件内容已变化，请重新上传', 409);
        } catch (\Throwable $error) {
            fclose($write); $write = null; @unlink($target); throw $error;
        } finally {
            curl_close($ch); if (is_resource($write)) fclose($write);
        }
    }

    public static function local(string $source, string $target, int $expectedBytes): void
    {
        self::budget($expectedBytes);
        $read = @fopen($source, 'rb');
        if (!$read) throw new RuntimeException('配音本地文件无法读取', 404);
        $write = null; $bytes = 0;
        try {
            $write = self::open($target);
            while (!feof($read)) {
                $chunk = fread($read, 8192);
                if ($chunk === false || ($chunk === '' && !feof($read))) throw new RuntimeException('配音文件暂时读取失败');
                if ($bytes + strlen($chunk) > $expectedBytes) throw new RuntimeException('配音文件内容已变化，请重新上传', 409);
                $count = self::write($write, $chunk); $bytes += $count;
                if ($count !== strlen($chunk)) throw new RuntimeException('配音缓存暂时写入失败');
            }
            if ($bytes !== $expectedBytes) throw new RuntimeException('配音文件内容已变化，请重新上传', 409);
        } catch (\Throwable $error) {
            if (is_resource($write)) fclose($write); $write = null; @unlink($target); throw $error;
        } finally {
            fclose($read); if (is_resource($write)) fclose($write);
        }
    }

    private static function budget(int $bytes): void
    {
        if ($bytes <= 0 || $bytes > AudioMediaProbe::MAX_BYTES) throw new RuntimeException('配音登记大小无效，请重新上传', 413);
    }

    private static function open(string $path)
    {
        $file = @fopen($path, 'wb');
        if (!$file) throw new RuntimeException('配音缓存暂时无法创建');
        return $file;
    }

    private static function write($file, string $chunk): int
    {
        $written = 0; $size = strlen($chunk);
        while ($written < $size) {
            $count = fwrite($file, substr($chunk, $written));
            if ($count === false || $count === 0) break;
            $written += $count;
        }
        return $written;
    }
}
