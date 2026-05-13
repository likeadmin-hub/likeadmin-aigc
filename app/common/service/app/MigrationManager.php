<?php

namespace app\common\service\app;

use app\common\model\app\AppMigration;
use Closure;
use Throwable;

class MigrationManager
{
    public static function run(string $scope, string $migrationKey, Closure $callback, string $appCode = '', int $tenantId = 0, string $version = ''): bool
    {
        $where = [
            'scope' => $scope,
            'migration_key' => $migrationKey,
            'app_code' => $appCode,
            'tenant_id' => $tenantId,
        ];
        $exists = AppMigration::where($where)->where('status', 'success')->findOrEmpty();
        if (!$exists->isEmpty()) {
            return true;
        }
        $migration = AppMigration::create($where + [
            'version' => $version,
            'batch' => date('YmdHis'),
            'status' => 'running',
            'error' => '',
            'create_time' => time(),
            'update_time' => time(),
        ]);
        try {
            $callback();
            $migration->status = 'success';
            $migration->error = '';
            $migration->update_time = time();
            $migration->save();
            return true;
        } catch (Throwable $e) {
            $migration->status = 'failed';
            $migration->error = $e->getMessage();
            $migration->update_time = time();
            $migration->save();
            return false;
        }
    }
}
