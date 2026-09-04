<?php

namespace app\common\service\wechat;

/** Small authenticated encryption wrapper for credentials persisted by the channel module. */
class WechatCredentialService
{
    private static function key(): string
    {
        $configured = (string)env('wechat.credential_key', '');
        return hash('sha256', $configured !== '' ? $configured : (string)config('project.unique_identification', 'likeadmin'), true);
    }

    public static function encrypt(string $value): string
    {
        if ($value === '') return '';
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $cipher === false ? '' : base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $value): string
    {
        if (!$value) return '';
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 28) return '';
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    public static function mask(?string $value): string
    {
        $value = (string)$value;
        if ($value === '') return '';
        if (strlen($value) <= 8) return str_repeat('*', strlen($value));
        return substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);
    }
}
