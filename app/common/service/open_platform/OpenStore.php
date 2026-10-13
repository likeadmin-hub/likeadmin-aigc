<?php
namespace app\common\service\open_platform;
use think\facade\Db;
final class OpenStore
{
    public static function table(string $name) { return Db::name('open_platform_' . $name); }
    public static function json(mixed $value): array { if (is_array($value)) return $value; $v = json_decode((string)$value, true); return is_array($v) ? $v : []; }
    public static function encode(array|object $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    public static function defaults(): array { return ['rpm'=>60,'concurrency'=>2,'user_concurrency'=>4,'tenant_concurrency'=>20,'apps'=>[],'models'=>[],'pages'=>[], 'features'=>['keys'=>true,'playground'=>true,'exports'=>true,'webhooks'=>true], 'body_limit'=>16777216]; }
    public static function settings(int $tenantId): array
    {
        $row = self::table('setting')->where('tenant_id', $tenantId)->find();
        $config=array_replace_recursive(self::defaults(),self::json($row['config']??[]));
        if($tenantId>0){
            // Existing per-tenant settings are the initial grant ceiling, not global defaults.
            $config['grant_limits']=$config['grant_limits']??array_intersect_key($config,array_flip(['rpm','concurrency','user_concurrency','tenant_concurrency']));
            $config['grant_features']=$config['grant_features']??$config['features'];
            $config['grant_apps']=$config['grant_apps']??$config['apps'];
        }
        $authorized=(bool)($row['enabled']??($tenantId===0?1:0));
        $tenantEnabled=(bool)($config['tenant_enabled']??true);
        return ['enabled'=>$authorized&&($tenantId===0||$tenantEnabled),'authorized'=>$authorized,'tenant_enabled'=>$tenantEnabled]+$config;
    }
    public static function limits(int $tenantId): array
    {
        $config=self::settings($tenantId);$global=self::settings(0);foreach(['rpm','concurrency','user_concurrency','tenant_concurrency','body_limit'] as $field)$config[$field]=min($config[$field],$global[$field]);return $config;
    }
    public static function requireEnabled(int $tenantId): array
    {
        if (!self::settings(0)['enabled']) throw new OpenException('platform_disabled','开放平台暂不可用',503);
        $config=self::limits($tenantId); if (!$config['enabled']) throw new OpenException('tenant_disabled','当前租户未开通 API',403);
        return $config;
    }
    public static function owner(string $table, int $tenantId, int $userId) { return self::table($table)->where(['tenant_id'=>$tenantId,'user_id'=>$userId]); }
    public static function timestamp(): array { return ['create_time'=>time(),'update_time'=>time()]; }
}
