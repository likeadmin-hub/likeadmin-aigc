<?php
declare(strict_types=1);
namespace app\common\service\update;

use RuntimeException;
use think\facade\Db;

/** Explicit operator recovery for the original 411->412 completed-canvas-file failure only. */
final class System412RecoveryService
{
    public const ARCHIVE = 'a2443c989f79bd3409302c641bda86f8323d015cb1ebc193f99c136be82eff5c';
    private const BASELINE = '7902d94897c70f3e4354311c3672c68e043d112af6a428183bfb35929ba16797';
    private const RETIRED = [
        'zz_20260727_canvas_legacy_schema_repair.sql'=>'38ae0dc313c6948d6252dccd68e76ee8262906a2482740ee7d12de957ed02497',
        'zz_20260727_canvas_skill_release_status.sql'=>'2a9a8f3928a3bd7961b0aaf0ce1f1d0bccc78f118963ae9da18696a7607a2465',
    ];

    public static function inspect(int $packageId, int $failedTaskId): array
    {
        if (PHP_SAPI!=='cli') throw new RuntimeException('412恢复仅允许管理员在命令行执行');
        if ((string)config('project.version')!=='1.0.411' || (json_decode((string)file_get_contents(root_path().'upgrade/version.json'),true)['version']??'')!=='1.0.411') throw new RuntimeException('412恢复仅适用于仍处于1.0.411的失败站点');
        if (is_file(root_path().'runtime/direct-update-active.json') || is_file(root_path().'.composer-runtime/active.json')) throw new RuntimeException('存在尚未完成的更新，禁止恢复');
        $package=Db::name('update_package')->where('id',$packageId)->find();
        $task=Db::name('update_task')->where('id',$failedTaskId)->find();
        if (!$package || $package['type']!=='system' || $package['version']!=='1.0.412' || $package['sha256']!==self::ARCHIVE || !is_file($package['local_path']) || hash_file('sha256',$package['local_path'])!==self::ARCHIVE) throw new RuntimeException('不是已确认的原始412归档');
        if (!$task || (int)$task['package_id']!==$packageId || $task['action']!=='apply' || $task['type']!=='system' || $task['status']!=='failed' || trim($task['error'])!=='站点更新子进程失败: 新迁移不支持会话变量或动态 SQL，请使用静态迁移') throw new RuntimeException('不是已确认的412首错，禁止恢复');
        $zip=new \ZipArchive();
        if ($zip->open($package['local_path'])!==true) throw new RuntimeException('原始412归档不可读');
        try {
            $manifest=json_decode((string)$zip->getFromName('update.json'),true,512,JSON_THROW_ON_ERROR);
            $signature=(string)$zip->getFromName('signature.json');
            $sql=(string)$zip->getFromName('sql/structure/20261022_market_price_rules.sql');
            if (($manifest['base_version']??'')!=='1.0.411' || ($manifest['target_version']??'')!=='1.0.412' || ($manifest['sql_order']??[])!==['sql/structure/20261022_market_price_rules.sql']) throw new RuntimeException('原始412迁移范围不一致');
            $digest=hash('sha256',$signature.'|1.0.412');
            $receipt=root_path().'runtime/update_receipts/'.$digest.'.json';
            $old=self::json($receipt);
            if (($old['status']??'')!=='rolled_back' || ($old['version']??'')!=='1.0.412' || !in_array($old['sql_state']??'', ['running','done'],true)) throw new RuntimeException('412失败回执不符合恢复条件');
            $directory=root_path().'runtime/update_recovery/412-'.$digest;
            if (($old['sql_state']??'')==='done' && !is_file($directory.'/proof.json')) throw new RuntimeException('缺少412恢复证明');
            if (hash_file('sha256',root_path().'config/runtime_migration_baseline.php')!==self::BASELINE) throw new RuntimeException('历史迁移基线不一致');
            $baseline=require root_path().'config/runtime_migration_baseline.php';
            $completed=[];
            foreach (self::RETIRED as $key=>$hash) {
                $relative='app/apps/aigc_canvas/migrations/'.$key;
                if ($zip->locateName('files/'.$relative)!==false) throw new RuntimeException('待隔离旧文件仍属于目标版本');
                $file=is_file(root_path().$relative)?root_path().$relative:$directory.'/retired/'.$key;
                $row=Db::name('app_migration')->where(['scope'=>'platform','app_code'=>'aigc_canvas','tenant_id'=>0,'migration_key'=>$key])->find();
                if (!is_file($file) || is_link($file) || hash_file('sha256',$file)!==$hash || !$row || $row['status']!=='success' || (int)$row['update_time']>=(int)$task['create_time']) throw new RuntimeException('旧迁移文件或历史成功记录不符合恢复条件: '.$key);
                $completed[$key]=['sha256'=>$hash,'record'=>$row];
            }
            // Original prepareBootstrapSql completes system SQL before it enters
            // canvas preparation. The exact failure occurs while building the
            // all-files plan, before any application DDL. Require every other
            // non-baseline app migration to have succeeded before this attempt.
            $apps=[];
            for ($i=0;$i<$zip->numFiles;$i++) {
                $name=$zip->getNameIndex($i);
                if (preg_match('#^files/app/apps/([^/]+)/manifest\.json$#D',$name,$m)) $apps[$m[1]]=true;
            }
            foreach (array_keys($apps) as $code) {
                $files=[];
                foreach (glob(base_path().'apps/'.$code.'/migrations/*.sql')?:[] as $file) $files[basename($file)]=(string)file_get_contents($file);
                for ($i=0;$i<$zip->numFiles;$i++) {
                    $name=$zip->getNameIndex($i);$prefix='files/app/apps/'.$code.'/migrations/';
                    if (str_starts_with($name,$prefix) && preg_match('/^[^\/]+\.sql$/D',substr($name,strlen($prefix)))) $files[basename($name)]=(string)$zip->getFromIndex($i);
                }
                foreach ($files as $key=>$bytes) {
                    $relative='app/apps/'.$code.'/migrations/'.$key;
                    if (isset($baseline[$relative])) {if (!hash_equals($baseline[$relative],hash('sha256',$bytes))) throw new RuntimeException('历史迁移字节不一致: '.$relative);continue;}
                    $row=Db::name('app_migration')->where(['scope'=>'platform','app_code'=>$code,'tenant_id'=>0,'migration_key'=>$key])->find();
                    if (!$row || $row['status']!=='success' || (int)$row['update_time']>=(int)$task['create_time']) throw new RuntimeException('存在未完成的新迁移，禁止跳过准备: '.$relative);
                }
            }
            self::assertPriceSchema();
            return ['schema'=>1,'package_id'=>$packageId,'failed_task_id'=>$failedTaskId,'archive_sha256'=>self::ARCHIVE,'receipt'=>$receipt,'receipt_sha256'=>hash_file('sha256',$receipt),'digest'=>$digest,'directory'=>$directory,'sql_sha256'=>hash('sha256',$sql),'completed'=>$completed,'system_sql_complete'=>true,'app_sql_pending'=>false];
        } finally {$zip->close();}
    }

    public static function recover(int $packageId, int $failedTaskId): array
    {
        self::inspect($packageId,$failedTaskId);
        ProjectUpdateProcessGate::assertDrained(root_path());
        DirectSiteUpdateService::releaseRequestReader();
        $lock=fopen(root_path().'runtime/site-update.lock','c+');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('站点仍有请求，稍后重试恢复');
        try {
            $proof=self::inspect($packageId,$failedTaskId);$directory=$proof['directory'];
            $old=self::json($proof['receipt']);
            if (($old['sql_state']??'')==='done') return ['recovered'=>true,'already_authorized'=>true,'proof'=>$proof];
            if (!is_dir($directory.'/retired') && !mkdir($directory.'/retired',0700,true)) throw new RuntimeException('无法创建412恢复备份');
            if (!is_file($directory.'/original-receipt.json') && !copy($proof['receipt'],$directory.'/original-receipt.json')) throw new RuntimeException('无法保存原始412回执');
            $proofBytes=json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if (file_put_contents($directory.'/proof.json',$proofBytes,LOCK_EX)!==strlen($proofBytes)) throw new RuntimeException('无法保存412恢复证明');
            foreach (self::RETIRED as $key=>$hash) {
                $source=base_path().'apps/aigc_canvas/migrations/'.$key;
                if (is_file($source) && !rename($source,$directory.'/retired/'.$key)) throw new RuntimeException('无法备份已完成旧迁移');
            }
            // No SQL executes here. Preserve the original failed task and receipt;
            // only authorize their independently proven completed preparation.
            $old['sql_state']='done';
            $old['recovery']=['kind'=>'completed-system412-preparation','proof_sha256'=>hash('sha256',$proofBytes)];
            $temporary=$proof['receipt'].'.recovery-'.bin2hex(random_bytes(4));
            $stat=stat($proof['receipt']);
            $bytes=json_encode($old,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary,$bytes,LOCK_EX)!==strlen($bytes)) throw new RuntimeException('无法保存412恢复授权');
            if (!chmod($temporary,$stat['mode']&0777)) throw new RuntimeException('无法保留412回执权限');
            // Some customer PHP CLIs deliberately disable chown/chgrp. The
            // recovery remains atomic there; the operator applies the known
            // existing receipt owner immediately after the CLI returns.
            if (function_exists('posix_geteuid') && function_exists('chown') && function_exists('chgrp') && posix_geteuid()===0 && (!chown($temporary,$stat['uid']) || !chgrp($temporary,$stat['gid']))) throw new RuntimeException('无法保留412回执所有者');
            if (!rename($temporary,$proof['receipt'])) throw new RuntimeException('无法写入412恢复授权');
            return ['recovered'=>true,'sql_replayed'=>false,'original_evidence'=>$directory,'proof'=>$proof];
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }

    private static function assertPriceSchema(): void
    {
        $prefix=(string)config('database.connections.mysql.prefix');
        if (!preg_match('/^[a-z0-9_]+$/iD',$prefix)) throw new RuntimeException('数据库前缀无效');
        $table=$prefix.'power_market_price_rule';
        $rows=Db::query('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
        if (count($rows)!==1 || strcasecmp($rows[0]['engine'],'InnoDB')!==0) throw new RuntimeException('价格规则表尚未安全创建');
        $columns=Db::query('SHOW COLUMNS FROM `'.$table.'`');
        $expected=['id'=>'bigint unsigned','owner_id'=>'int unsigned','scope_type'=>'varchar(12)','scope_id'=>'int unsigned','mode'=>'varchar(12)','value'=>'decimal(16,6)','revision'=>'bigint unsigned','follow_apps'=>'tinyint unsigned','create_time'=>'int unsigned','update_time'=>'int unsigned'];
        if (count($columns)!==count($expected)) throw new RuntimeException('价格规则结构不一致');
        foreach ($columns as $column) {
            $type=preg_replace('/\b(bigint|int|tinyint)\(\d+\)/','$1',strtolower($column['Type']));
            if (($expected[$column['Field']]??null)!==$type || $column['Null']!=='NO') throw new RuntimeException('价格规则字段不一致: '.$column['Field']);
        }
        $index=Db::query('SHOW INDEX FROM `'.$table.'` WHERE Key_name=?',['uk_owner_scope']);
        usort($index,static fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        if (array_column($index,'Column_name')!==['owner_id','scope_type','scope_id'] || array_filter($index,static fn($x)=>(int)$x['Non_unique']!==0)) throw new RuntimeException('价格规则唯一索引不一致');
        $global=Db::name('power_market_price_rule')->where(['owner_id'=>0,'scope_type'=>'global','scope_id'=>0])->find();
        if (!$global) throw new RuntimeException('价格规则系统初始化未完成');
        $missing=Db::query('SELECT DISTINCT r.owner_id FROM `'.$table.'` r LEFT JOIN `'.$table.'` g ON g.owner_id=r.owner_id AND g.scope_type=\'global\' AND g.scope_id=0 WHERE r.owner_id>0 AND g.id IS NULL');
        if ($missing) throw new RuntimeException('价格规则租户初始化未完成');
    }

    private static function json(string $path): array
    {
        if (!is_file($path) || is_link($path)) throw new RuntimeException('412恢复证据缺失或路径无效');
        return json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    }
}
