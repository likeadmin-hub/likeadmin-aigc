<?php
namespace app\common\service\open_platform;
/** Replay bodies and webhook secrets are encrypted at rest, never returned in logs. */
final class OpenVault
{
    private static function key(): string
    {
        $key = (string)env('OPEN_PLATFORM_ENCRYPTION_KEY','');
        if ($key !== '') return hash('sha256', $key, true);
        $dir = root_path().'runtime/open_platform'; if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new \RuntimeException('无法创建开放平台安全目录');
        $path=$dir.'/master.key'; if (is_link($path)) throw new \RuntimeException('安全密钥文件不可为符号链接');
        $fp=fopen($path,'c+b'); if (!$fp || !flock($fp,LOCK_EX)) throw new \RuntimeException('无法读取开放平台加密密钥');
        chmod($path,0600); $data=stream_get_contents($fp); if ($data==='') { $data=random_bytes(32); rewind($fp); if (fwrite($fp,$data)!==32) throw new \RuntimeException('无法保存加密密钥'); fflush($fp); } flock($fp,LOCK_UN); fclose($fp);
        if (strlen($data)!==32) throw new \RuntimeException('开放平台加密密钥无效'); return $data;
    }
    public static function seal(string $value): string { $iv=random_bytes(12); $cipher=openssl_encrypt($value,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag); if ($cipher===false) throw new \RuntimeException('加密失败'); return base64_encode($iv.$tag.$cipher); }
    public static function open(string $value): string { if ($value==='') return ''; $raw=base64_decode($value,true); if ($raw===false || strlen($raw)<28) throw new \RuntimeException('密文无效'); $result=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16)); if ($result===false) throw new \RuntimeException('密文验证失败'); return $result; }
}
