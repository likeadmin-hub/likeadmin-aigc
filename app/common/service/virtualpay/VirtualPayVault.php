<?php
declare(strict_types=1);
namespace app\common\service\virtualpay;
/** A separate installation key avoids changing encryption for existing WeChat credentials. */
final class VirtualPayVault
{
    public static function requireKey(): void
    {
        if(strlen((string)env('virtualpay.credential_key',''))<32)throw new \RuntimeException('请联系管理员配置至少32位的虚拟支付凭据加密密钥，再启用虚拟支付');
    }
    private static function key(): string {self::requireKey();return hash('sha256',(string)env('virtualpay.credential_key'),true);}
    public static function encrypt(string $value): string
    {
        if($value==='')return '';$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,$iv,$tag);
        if($cipher===false)throw new \RuntimeException('支付凭据加密失败');return base64_encode($iv.$tag.$cipher);
    }
    public static function decrypt(?string $value): string
    {
        if(!$value)return '';$raw=base64_decode($value,true);if($raw===false||strlen($raw)<28)return '';
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',self::key(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));return $plain===false?'':$plain;
    }
}
