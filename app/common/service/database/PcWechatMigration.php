<?php

namespace app\common\service\database;

use think\facade\Db;

/** Explicit migration: never called by a normal login/configuration request. */
class PcWechatMigration
{
    public static function run(string $prefix, $connection = null, bool $dryRun = false): array
    {
        $bootstrapPath=''; $bootstrapMarker=false;
        foreach(debug_backtrace(0,24) as $frame){
            if(($frame['class']??'')===SqlMigrationExecutor::class && ($frame['function']??'')==='execute') $bootstrapMarker=preg_match('/^-- likeadmin:system-runtime-bootstrap-v1\\s*$/m',(string)($frame['args'][0]??''))===1;
            if(($frame['class']??'')==='app\\common\\service\\update\\SystemPackageUpdateService' && ($frame['function']??'')==='applySqlGroup') $bootstrapPath=(string)($frame['args'][0]??'');
        }
        if($bootstrapMarker){
            if(version_compare(PHP_VERSION,'8.2.0','<'))throw new \RuntimeException('本次完整更新要求先将网站及本项目任务进程切换到 PHP 8.2，并平滑停止旧任务进程后重试');
            $signature=json_decode((string)@file_get_contents($bootstrapPath.'/signature.json'),true);
            foreach(['app/common/service/update/ComposerRuntimeUpdateService.php','app/common/service/update/SystemRuntimeBootstrapService.php','scripts/composer-runtime-runner.php'] as $helper){
                $relative='bootstrap-payload/files/'.$helper;$expected=$signature['sha256'][$relative]??'';
                if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)||!is_file($bootstrapPath.'/'.$relative)||is_link($bootstrapPath.'/'.$relative)||!hash_equals($expected,(string)hash_file('sha256',$bootstrapPath.'/'.$relative)))throw new \RuntimeException('签名自举 helper 校验失败');
            }
            if($bootstrapPath==='' || !is_file($bootstrapPath.'/bootstrap-payload/files/app/common/service/update/SystemRuntimeBootstrapService.php'))throw new \RuntimeException('签名运行时自举入口缺失');
            require_once $bootstrapPath.'/bootstrap-payload/files/app/common/service/update/ComposerRuntimeUpdateService.php';
            require_once $bootstrapPath.'/bootstrap-payload/files/app/common/service/update/SystemRuntimeBootstrapService.php';
            \app\common\service\update\SystemRuntimeBootstrapService::handoffFromMigration($bootstrapPath);
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $prefix)) throw new \RuntimeException('Invalid database prefix');
        $db = $connection ?: Db::connect();
        $rows = $db->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $plans = [];
        $matched = 0;
        foreach ($rows as $row) {
            $table = $row['TABLE_NAME'];
            if (!preg_match('/^' . preg_quote($prefix . 'user_auth', '/') . '(?:_[a-zA-Z0-9]+)?$/D', $table)) continue;
            $matched++;
            $columns = $db->query("SHOW COLUMNS FROM `{$table}`");
            $hasAppid = in_array('appid', array_column($columns, 'Field'), true);
            $appidExpr = $hasAppid ? '`appid`' : "''";
            $duplicates = $db->query("SELECT 1 FROM `{$table}` GROUP BY tenant_id, terminal, {$appidExpr}, openid HAVING COUNT(*) > 1 LIMIT 1");
            if ($duplicates) throw new \RuntimeException('微信身份存在冲突，迁移停止：' . $table);
            $indexes = [];
            foreach ($db->query("SHOW INDEX FROM `{$table}`") as $idx) $indexes[$idx['Key_name']][] = $idx;
            $alter = [];
            if (!$hasAppid) $alter[] = "ADD COLUMN `appid` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' COMMENT 'PC网站应用AppID，历史记录待重新授权'";
            $expected = ['tenant_id', 'terminal', 'appid', 'openid'];
            if (isset($indexes['uk_wechat_identity'])) {
                $parts = $indexes['uk_wechat_identity'];
                usort($parts, fn($a, $b) => $a['Seq_in_index'] <=> $b['Seq_in_index']);
                if (array_column($parts, 'Column_name') !== $expected || (int)$parts[0]['Non_unique'] !== 0) throw new \RuntimeException('微信身份索引定义冲突：' . $table);
            } else {
                $alter[] = 'ADD UNIQUE INDEX `uk_wechat_identity` (`tenant_id`,`terminal`,`appid`,`openid`)';
            }
            foreach ($indexes as $name => $parts) {
                if (count($parts) === 1 && (int)$parts[0]['Non_unique'] === 0 && $parts[0]['Column_name'] === 'openid') {
                    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new \RuntimeException('Unexpected index name');
                    $alter[] = "DROP INDEX `{$name}`";
                }
            }
            if ($alter) $plans[] = "ALTER TABLE `{$table}` " . implode(', ', $alter);
        }
        if (!$matched) throw new \RuntimeException('未找到微信身份表');
        // Preflight every table before performing any DDL. Each table changes atomically.
        if (!$dryRun) foreach ($plans as $sql) $db->execute($sql);
        return $plans;
    }
}
