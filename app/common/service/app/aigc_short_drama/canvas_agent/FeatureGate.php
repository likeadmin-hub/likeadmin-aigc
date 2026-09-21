<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;
use think\facade\Db;

/** Explicit tenant opt-in; absent configuration never enables Agent writes. */
final class FeatureGate
{
    public static function enabled(int $tenant): bool
    {
        if ($tenant<=0) return false;
        $row=Db::name('aigc_short_drama_config')->where('tenant_id',$tenant)->find();
        if (!$row || (int)$row['status']!==1) return false;
        $config=json_decode((string)$row['config_json'],true);
        return is_array($config) && in_array($config['canvas_agent']['enabled']??false,[true,1,'1'],true);
    }
    public static function assertEnabled(int $tenant): void
    {
        if (!self::enabled($tenant)) throw new RuntimeException('CANVAS_AGENT_DISABLED');
    }
}
