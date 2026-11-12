<?php

namespace app\common\service\app;

use think\facade\Db;

/** Existing access choices belong to administrators, never to package seed SQL. */
class AppAccessStateService
{
    /** MySQL connection locks survive DDL commits and serialize explicit switch writers. */
    public static function withSwitchLock(callable $operation): mixed
    {
        $database = (string)config('database.connections.mysql.database');
        $prefix = (string)config('database.connections.mysql.prefix');
        $key = 'app-access:'.substr(hash('sha256', $database.':'.$prefix), 0, 40);
        if ((int)(Db::query('SELECT GET_LOCK(?,0) AS acquired', [$key])[0]['acquired'] ?? 0) !== 1) {
            throw new AppAccessBusyException('应用状态正在更新，请稍后重试');
        }
        try {
            return $operation();
        } finally {
            Db::query('SELECT RELEASE_LOCK(?)', [$key]);
        }
    }

    public static function preserveExisting(callable $operation, ?string $appCode = null): mixed
    {
        return self::withSwitchLock(fn() => self::preserveState($operation, $appCode));
    }

    private static function preserveState(callable $operation, ?string $appCode): mixed
    {
        $prefix = (string)config('database.connections.mysql.prefix');
        $tables = Db::query('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?)', [$prefix.'app', $prefix.'tenant_app']);
        $tables = array_column($tables, 'name');
        $apps = [];
        $tenantApps = [];
        if (in_array($prefix.'app', $tables, true)) {
            $query = Db::name('app')->where('status', AppRegistryService::STATUS_DISABLED);
            if ($appCode !== null) $query->where('code', $appCode);
            $apps = $query->lock(true)->column('code');
        }
        if (in_array($prefix.'tenant_app', $tables, true)) {
            $query = Db::name('tenant_app');
            if ($appCode !== null) $query->where('app_code', $appCode);
            $tenantApps = $query->field('tenant_id,app_code,buy_status,shelf_status,enable_status,expire_time')->lock(true)->select()->toArray();
        }
        try {
            return $operation();
        } finally {
            $apps=array_values(array_diff($apps,RetiredAppService::CODES));
            if ($apps) Db::name('app')->whereIn('code', $apps)->update(['status' => AppRegistryService::STATUS_DISABLED]);
            foreach ($tenantApps as $row) {
                if (RetiredAppService::contains((string)$row['app_code'])) continue;
                $where = ['tenant_id' => $row['tenant_id'], 'app_code' => $row['app_code']];
                unset($row['tenant_id'], $row['app_code']);
                Db::name('tenant_app')->where($where)->update($row);
            }
        }
    }
}
