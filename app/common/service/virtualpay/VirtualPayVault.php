<?php
declare(strict_types=1);
namespace app\common\service\virtualpay;

use app\common\service\wechat\WechatCredentialService;
use RuntimeException;
use think\facade\Db;

/** Random per-tenant vault keys use the existing channel credential store; old ciphertext stays readable. */
final class VirtualPayVault
{
    private const PREFIX = 'vp2:';
    private static function scope(int $tenant): array
    {
        if ($tenant <= 0) throw new RuntimeException('支付凭据租户无效');
        return ['tenant_id'=>$tenant, 'type'=>'virtual_pay_vault', 'name'=>'credential_key'];
    }
    public static function hasTenantKey(int $tenant): bool
    {
        return (bool)Db::name('tenant_config')->where(self::scope($tenant))->find();
    }
    private static function tenantKey(int $tenant): string
    {
        $rows=Db::name('tenant_config')->where(self::scope($tenant))->limit(2)->select()->toArray();
        if (!$rows) throw new RuntimeException('请先生成本租户的支付凭据加密密钥');
        if (count($rows)!==1) throw new RuntimeException('本租户的支付凭据密钥配置冲突，请联系管理员核对');
        $plain=WechatCredentialService::decrypt((string)$rows[0]['value']);
        $payload=json_decode($plain,true);
        if (!is_array($payload) || ($payload['tenant_id']??null)!==$tenant || !preg_match('/^[a-f0-9]{64}$/D',(string)($payload['key']??''))) {
            throw new RuntimeException('本租户的支付凭据密钥无法读取，请恢复原凭据备份');
        }
        return hash('sha256',$payload['key'],true);
    }
    public static function generate(int $tenant): void
    {
        $scope=self::scope($tenant);
        if (self::hasTenantKey($tenant)) { self::tenantKey($tenant); return; }
        $connection=Db::connect();
        $lock='vpay-vault:'.substr(hash('sha256',(string)$connection->getConfig('database').'|'.(string)$connection->getConfig('prefix').'|'.$tenant),0,48);
        if ((int)($connection->query('SELECT GET_LOCK(?,5) AS acquired',[$lock])[0]['acquired']??0)!==1) throw new RuntimeException('本租户正在生成支付凭据密钥，请稍后重试');
        try {
            // Callers initialize before credential transactions. Commit the key before releasing the shared lock.
            Db::transaction(function()use($tenant,$scope):void{
                $existing=Db::name('tenant_config')->where($scope)->lock(true)->find();
                if ($existing) { self::tenantKey($tenant); return; }
                $wrapped=WechatCredentialService::encrypt(json_encode(['tenant_id'=>$tenant,'key'=>bin2hex(random_bytes(32))],JSON_THROW_ON_ERROR));
                if ($wrapped==='') throw new RuntimeException('支付凭据密钥生成失败，请重试');
                Db::name('tenant_config')->insert($scope+['value'=>$wrapped,'create_time'=>time(),'update_time'=>time()]);
            });
        } finally { try { $connection->query('SELECT RELEASE_LOCK(?)',[$lock]); } catch (\Throwable $error) { } }
    }
    /** Read-only compatibility for historical installation-key ciphertext. Never used for new tenant writes. */
    public static function configuredKey(): string
    {
        $configured=(string)env('virtualpay.credential_key','');
        return $configured!==''?$configured:(string)(getenv('PHP_VIRTUALPAY_CREDENTIAL_KEY')?:'');
    }
    public static function requireKey(?int $tenant=null): void
    {
        if ($tenant!==null) { self::tenantKey($tenant); return; }
        if (strlen(self::configuredKey())<32) throw new RuntimeException('历史支付凭据加密密钥不可用，请恢复原加密配置');
    }
    private static function legacyKey(): string
    {
        self::requireKey();return hash('sha256',self::configuredKey(),true);
    }
    public static function encrypt(string $value,?int $tenant=null): string
    {
        if ($value==='') return '';
        $key=$tenant===null?self::legacyKey():self::tenantKey($tenant);
        $iv=random_bytes(12);$tag='';$aad=$tenant===null?'':'virtualpay|'.$tenant;
        $cipher=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$aad);
        if ($cipher===false) throw new RuntimeException('支付凭据加密失败');
        return ($tenant===null?'':self::PREFIX).base64_encode($iv.$tag.$cipher);
    }
    public static function decrypt(?string $value,?int $tenant=null): string
    {
        if (!$value) return '';
        $scoped=str_starts_with($value,self::PREFIX);
        if ($scoped && ($tenant===null || $tenant<=0)) return '';
        if ($scoped) {
            try { $key=self::tenantKey($tenant); } catch (RuntimeException $error) { return ''; }
            $value=substr($value,strlen(self::PREFIX));
        } else $key=self::legacyKey();
        $raw=base64_decode($value,true);if ($raw===false || strlen($raw)<28) return '';
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$scoped?'virtualpay|'.$tenant:'');
        return $plain===false?'':$plain;
    }
}
