<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use think\facade\Db;

/** Explicit tenant opt-in; absent configuration never enables Agent writes. */
final class FeatureGate
{
    private static function truthy(mixed $value): bool
    {
        return in_array($value,[true,1,'1','true'],true);
    }

    private static function config(int $tenant): array
    {
        if ($tenant<=0) return [];
        $row=Db::name('aigc_short_drama_config')->where('tenant_id',$tenant)->find();
        if (!$row || (int)$row['status']!==1) return [];
        $config=json_decode((string)$row['config_json'],true);
        return is_array($config) ? $config : [];
    }

    public static function enabled(int $tenant): bool
    {
        return self::truthy(self::config($tenant)['canvas_agent']['enabled']??false);
    }

    /** A separate, explicit opt-in is required before a Worker can spend
     * tenant/user credits by invoking the configured text model. */
    public static function executionEnabled(int $tenant): bool
    {
        $agent=(array)(self::config($tenant)['canvas_agent']??[]);
        return self::truthy($agent['enabled']??false)
            && self::truthy($agent['execution_enabled']??false);
    }
    public static function assertEnabled(int $tenant): void
    {
        if (!self::enabled($tenant)) throw new RuntimeException('CANVAS_AGENT_DISABLED');
    }
}
