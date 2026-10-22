<?php
declare(strict_types=1);
namespace app\common\service\virtualpay;

use RuntimeException;
use think\facade\Db;

/** Explicit installation/CLI provisioning only; request handlers must never rotate a vault key. */
final class VirtualPayKeySetup
{
    public static function readKey(array $ini): string
    {
        $flat = [];
        foreach ($ini as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $field => $item) $flat[strtoupper($name.'_'.$field)] = $item;
            } else $flat[strtoupper($name)] = $value;
        }
        return (string)($flat['VIRTUALPAY_CREDENTIAL_KEY'] ?? '');
    }

    public static function installationKey(string $envFile, string $processKey = ''): string
    {
        $existing = is_file($envFile) ? self::readKey(self::parse((string)file_get_contents($envFile))) : '';
        $key = $existing !== '' ? $existing : $processKey;
        if ($key !== '' && strlen($key) < 32) throw new RuntimeException('已有支付凭据加密密钥不足32字节，请核对原配置；未覆盖已有密钥');
        return $key !== '' ? $key : bin2hex(random_bytes(32));
    }

    public static function hasEncryptedRecords(): bool
    {
        // A missing schema/failed DB connection is never evidence of an empty installation.
        try {
            return (bool)Db::name('virtual_pay_config_version')->whereRaw("app_key <> '' OR token <> '' OR aes_key <> '' OR mini_secret <> ''")->find()
                || (bool)Db::name('virtual_pay_session')->where('session_key', '<>', '')->find();
        } catch (\Throwable $error) {
            throw new RuntimeException('无法确认支付历史记录，请先完成数据库升级并检查连接；未生成密钥', 0, $error);
        }
    }

    public static function initialize(string $envFile, string $effectiveKey, callable $hasEncryptedRecords): array
    {
        if ($effectiveKey !== '') {
            if (strlen($effectiveKey) < 32) throw new RuntimeException('已有支付凭据加密密钥不足32字节，请核对原配置；未覆盖已有密钥');
            return ['created'=>false];
        }
        if (!is_file($envFile) || is_link($envFile)) throw new RuntimeException('请在已安装站点的真实 .env 配置文件上初始化；挂载或链接配置请由管理员配置独立密钥');
        if (is_link(dirname($envFile).'/.virtualpay-key.lock')) throw new RuntimeException('支付配置锁文件不可为符号链接');
        $lock = @fopen(dirname($envFile).'/.virtualpay-key.lock', 'c');
        if (!$lock) throw new RuntimeException('无法锁定站点配置，请核对目录权限');
        @chmod(dirname($envFile).'/.virtualpay-key.lock', 0600);
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('无法锁定站点配置');
            $original = file_get_contents($envFile);
            if ($original === false) throw new RuntimeException('无法读取站点配置');
            $ini = self::parse($original);$existing = self::readKey($ini);
            if ($existing !== '') {
                if (strlen($existing) < 32) throw new RuntimeException('已有支付凭据加密密钥不足32字节，请核对原配置；未覆盖已有密钥');
                return ['created'=>false];
            }
            if ($hasEncryptedRecords()) throw new RuntimeException('已存在加密支付凭据或登录会话，请从原部署备份恢复原密钥；禁止重新生成');
            $key = bin2hex(random_bytes(32));
            $updated = self::appendKey($original, $ini, $key);
            if (self::readKey(self::parse($updated)) !== $key) throw new RuntimeException('支付密钥配置校验失败，未修改站点配置');
            $backup = $envFile.'.virtualpay-backup-'.date('YmdHis').'-'.bin2hex(random_bytes(4));
            self::privateFile($backup, $original);
            $temporary = $envFile.'.virtualpay-tmp-'.bin2hex(random_bytes(8));
            self::privateFile($temporary, $updated);
            $stat = stat($envFile);
            if (!$stat || !chmod($temporary, $stat['mode'] & 0777)
                || (fileowner($temporary) !== $stat['uid'] && !chown($temporary, $stat['uid']))
                || (filegroup($temporary) !== $stat['gid'] && !chgrp($temporary, $stat['gid']))) {
                throw new RuntimeException('无法保留配置文件权限，请由站点文件所有者执行初始化');
            }
            if (!rename($temporary, $envFile)) throw new RuntimeException('站点配置写入失败，原配置未变更');
            $temporary = null;
            return ['created'=>true, 'backup'=>$backup];
        } finally {
            if ($temporary && is_file($temporary)) @unlink($temporary);
            flock($lock, LOCK_UN);fclose($lock);
        }
    }

    private static function parse(string $content): array
    {
        $parsed = @parse_ini_string($content, true, INI_SCANNER_RAW);
        if ($parsed === false) throw new RuntimeException('站点配置格式无效，请先修复 .env；未修改任何配置');
        return $parsed;
    }

    private static function privateFile(string $path, string $content): void
    {
        $previousMask = umask(0077);
        try { $handle = @fopen($path, 'x'); } finally { umask($previousMask); }
        if (!$handle) throw new RuntimeException('无法创建支付配置备份或临时文件');
        try {
            if (!chmod($path, 0600) || fwrite($handle, $content) !== strlen($content) || !fflush($handle)) {
                throw new RuntimeException('支付配置文件写入不完整，未替换站点配置');
            }
        } finally { fclose($handle); }
    }

    private static function appendKey(string $content, array $ini, string $key): string
    {
        if (array_key_exists('VIRTUALPAY_CREDENTIAL_KEY', array_change_key_case($ini, CASE_UPPER))) {
            return preg_replace('/^([\t ]*VIRTUALPAY_CREDENTIAL_KEY[\t ]*=[\t ]*)[^\r\n]*/mi', '${1}"'.$key.'"', $content, 1);
        }
        // Update inside the existing section so neighboring settings/comments remain byte-for-byte intact.
        if (preg_match('/^[\t ]*\[VIRTUALPAY\][^\r\n]*(?:\r?\n|$)/mi', $content, $match, PREG_OFFSET_CAPTURE)) {
            $start = $match[0][1] + strlen($match[0][0]);
            preg_match('/^[\t ]*\[/m', $content, $next, PREG_OFFSET_CAPTURE, $start);
            $end = $next ? $next[0][1] : strlen($content);
            $section = substr($content, $start, $end - $start);
            if (preg_match('/^[\t ]*CREDENTIAL_KEY[\t ]*=/mi', $section)) {
                $section = preg_replace('/^([\t ]*CREDENTIAL_KEY[\t ]*=[\t ]*)[^\r\n]*/mi', '${1}"'.$key.'"', $section, 1);
            } else $section = 'CREDENTIAL_KEY = "'.$key.'"'."\n".$section;
            $separator = str_ends_with($match[0][0], "\n") ? '' : "\n";
            return substr($content, 0, $start).$separator.$section.substr($content, $end);
        }
        return $content."\n[VIRTUALPAY]\nCREDENTIAL_KEY = \"".$key."\"\n";
    }
}
