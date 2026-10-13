<?php
declare(strict_types=1);
namespace app\common\service\update;

use RuntimeException;
use think\facade\Db;

/** Explicit operator recovery for the published392 missing-history failure only. */
final class System392RecoveryService
{
    private const ORIGINAL_ARCHIVE = 'b38382e8185875f60cb3a50f98389db27892c86dc626b53fd9d9b8cd02bf509e';
    private const BASELINE_HASH = '7902d94897c70f3e4354311c3672c68e043d112af6a428183bfb35929ba16797';
    private const SQL = [
        'sql/structure/20261012_workspace_asset_labels.sql'=>'8729d20d9f98403fbd95fab5991cbadee30e1969969e7d4a0910c07fc1fdacdb',
        'sql/structure/20261012_workspace_asset_state.sql'=>'c8251d225267d98135bb565105b897edab411eb907b63b017d9c898691e598c4',
        'sql/structure/20261012_workspace_local_uploads.sql'=>'285cdf55eec7729590c94c8d2e043f067c5b466dc4f5f5b9d13eb0247f661b85',
    ];

    public static function authorize(int $packageId, string $originalDigest, bool $write = false): array
    {
        self::requireCli();
        if (is_file(root_path().'.composer-runtime/active.json')) throw new RuntimeException('恢复授权必须在更新器空闲时核对');
        $package=Db::name('update_package')->where('id',$packageId)->find();
        if (!$package || $package['status']!=='preflight_success') throw new RuntimeException('请先通过修正版392预检与授权校验');
        (new SystemPackageGrantService())->ensure($package);
        $proof=self::inspect($package,$originalDigest);
        if ($write) {
            $path=self::path();
            if (is_file($path)) throw new RuntimeException('已有392恢复授权，请保留原记录后再操作');
            if (!is_dir(dirname($path)) && !mkdir(dirname($path),0700,true)) throw new RuntimeException('无法创建恢复记录目录');
            $temporary=$path.'.'.bin2hex(random_bytes(6));
            if (file_put_contents($temporary,json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false
                || !chmod($temporary,0600) || !rename($temporary,$path)) throw new RuntimeException('无法保存392恢复授权');
        }
        return $proof;
    }

    /** Called under the inherited writer lease in the signed candidate, before any SQL. */
    public static function shouldSkipCompletedSystemSql(string $extractPath): bool
    {
        $path=self::path();
        if (!is_file($path)) return false;
        self::requireCli();
        if (empty($GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE'])) throw new RuntimeException('392恢复只能在隔离候选进程执行');
        $proof=self::json($path);
        $active=self::json(root_path().'.composer-runtime/active.json');
        $journal=self::json(root_path().'.composer-runtime/journals/'.self::digest((string)($active['digest']??'')).'.json');
        $binding=$journal['bootstrap_context']??[];
        if ((int)($binding['package_id']??0)!==(int)($proof['package_id']??0)) return false;
        if (($binding['kind']??'')!=='system' || !hash_equals((string)$proof['archive_sha256'],(string)($binding['archive_sha256']??''))
            || basename((string)$GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE'])!==($journal['generation']??'')) throw new RuntimeException('392恢复候选与授权绑定不一致');
        $package=Db::name('update_package')->where('id',(int)$proof['package_id'])->find();
        if (!$package || rtrim((string)$package['extract_path'],'/')!==rtrim($extractPath,'/')) throw new RuntimeException('392恢复包目录绑定不一致');
        $verified=self::inspect($package,(string)$proof['original_digest']);
        if ($verified!==$proof) throw new RuntimeException('392恢复证据已变化，禁止执行');
        return true;
    }

    private static function inspect(array $package, string $originalDigest): array
    {
        $originalDigest=self::digest($originalDigest);
        if ($package['type']!=='system' || $package['version']!=='1.0.392' || $package['sha256']===self::ORIGINAL_ARCHIVE
            || !is_file($package['local_path']) || !hash_equals($package['sha256'],(string)hash_file('sha256',$package['local_path']))) throw new RuntimeException('恢复必须使用已验证的修正版392归档');
        $journalPath=root_path().'.composer-runtime/journals/'.$originalDigest.'.json';
        $old=self::json($journalPath);$binding=$old['bootstrap_context']??[];
        $pointer=self::json(root_path().'.composer-runtime/current.json');
        if (($old['digest']??'')!==$originalDigest || ($old['status']??'')!=='rolled_back' || ($old['sql_state']??'')!=='running'
            || ($binding['kind']??'')!=='system' || ($binding['archive_sha256']??'')!==self::ORIGINAL_ARCHIVE
            || ($old['previous_pointer']??null)!==$pointer || ($pointer['version']??'')!=='1.0.391') throw new RuntimeException('原392失败日志或当前运行代不符合定向恢复条件');
        $task=Db::name('update_task')->where('id',(int)($binding['task_id']??0))->find();
        if (!$task || $task['type']!=='system' || $task['action']!=='apply' || $task['status']!=='failed'
            || (int)$task['package_id']!==(int)($binding['package_id']??0)
            || trim($task['error'])!=='PHP CLI 运行时验证失败: 新迁移不支持会话变量或动态 SQL，请使用静态迁移') throw new RuntimeException('首次错误不是已确认的历史迁移分类失败，禁止恢复');
        if (Db::name('app_migration')->whereIn('app_code',['aigc_image','aigc_video','aigc_digital_human','aigc_canvas','aigc_llm','aigc_music','aigc_music_cover','open_platform','image_human','system_default'])->where('status','<>','success')->count()) throw new RuntimeException('仍有不确定的应用迁移，禁止恢复');
        $extract=rtrim((string)$package['extract_path'],'/');
        $manifest=self::json($extract.'/update.json');$coverage=self::json($extract.'/release-change-manifest.json');
        if (($manifest['version']??'')!=='1.0.392' || ($manifest['base_version']??'')!=='1.0.391'
            || !hash_equals(self::BASELINE_HASH,(string)hash_file('sha256',$extract.'/files/config/runtime_migration_baseline.php'))) throw new RuntimeException('修正版392的历史基线不可信');
        $system=[];
        foreach ($coverage['migrations']??[] as $row) if (($row['execution_owner']??'')==='system') $system[$row['package_path']]=$row['sha256'];
        ksort($system);$expected=self::SQL;ksort($expected);
        if ($system!==$expected) throw new RuntimeException('392系统迁移范围已变化，禁止跳过');
        foreach ($expected as $relative=>$hash) if (!in_array($relative,$manifest['sql_order']??[],true)
            || !hash_equals($hash,(string)hash_file('sha256',$extract.'/'.$relative))) throw new RuntimeException('392系统迁移字节已变化');
        self::assertSystemEffects();
        return ['schema'=>1,'site_root'=>root_path(),'database'=>(string)config('database.connections.mysql.database'),
            'prefix'=>(string)config('database.connections.mysql.prefix'),'package_id'=>(int)$package['id'],'archive_sha256'=>$package['sha256'],
            'original_digest'=>$originalDigest,'original_journal_sha256'=>hash_file('sha256',$journalPath),
            'previous_pointer'=>$pointer,'system_sql'=>$expected];
    }

    private static function assertSystemEffects(): void
    {
        $prefix=(string)config('database.connections.mysql.prefix');
        if (!preg_match('/^[a-z0-9_]+$/iD',$prefix)) throw new RuntimeException('数据库前缀不安全');
        $required=[
            'user_asset_labels'=>['tenant_id'=>'int unsigned','user_id'=>'int unsigned','labels'=>'text','update_time'=>'int unsigned'],
            'user_asset_state'=>['id'=>'bigint unsigned','tenant_id'=>'int unsigned','user_id'=>'int unsigned','asset_ref'=>'varchar(100)',
                'favorite'=>'tinyint unsigned','label'=>'varchar(20)','trashed_at'=>'int unsigned','hidden_at'=>'int unsigned','create_time'=>'int unsigned','update_time'=>'int unsigned'],
            'tenant_file'=>['upload_origin'=>'varchar(20)'],
        ];
        foreach ($required as $table=>$columns) {
            $engine=Db::query('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$prefix.$table]);
            if (count($engine)!==1 || strcasecmp((string)$engine[0]['engine'],'InnoDB')!==0) throw new RuntimeException('392已执行结构缺失或引擎不安全: '.$table);
            $observed=Db::query('SHOW COLUMNS FROM `'.$prefix.$table.'`');$byName=array_column($observed,null,'Field');
            foreach ($columns as $name=>$type) {
                $column=$byName[$name]??[];$actual=preg_replace('/\b(bigint|int|tinyint)\(\d+\)/','$1',strtolower((string)($column['Type']??'')));
                if ($actual!==$type || ($column['Null']??'')!==($name==='favorite'?'YES':'NO')) throw new RuntimeException('392已执行字段不完整: '.$table.'.'.$name);
            }
            if ($table==='tenant_file' && ($byName['upload_origin']['Default']??null)!=='') throw new RuntimeException('上传来源默认值不符合原迁移');
            if ($table!=='tenant_file') {
                $indexes=Db::query('SHOW INDEX FROM `'.$prefix.$table.'`');$expected=$table==='user_asset_labels'?['PRIMARY'=>['tenant_id','user_id']]:['PRIMARY'=>['id'],'uk_owner_asset'=>['tenant_id','user_id','asset_ref']];
                foreach ($expected as $key=>$names) {
                    $rows=array_values(array_filter($indexes,fn($row)=>$row['Key_name']===$key));usort($rows,fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
                    if (array_column($rows,'Column_name')!==$names || array_filter($rows,fn($row)=>(int)$row['Non_unique']!==0 || $row['Sub_part']!==null)) throw new RuntimeException('392已执行唯一索引不完整');
                }
            }
        }
        $rows=Db::query("SELECT COUNT(*) AS n FROM `{$prefix}tenant_file` WHERE upload_origin='' AND uri REGEXP '^/?uploads/(images|video|file|appearance|feedback)/[0-9]{8}/'");
        if ((int)$rows[0]['n']!==0) throw new RuntimeException('本地上传来源尚未完成，禁止跳过原SQL');
        $music=Db::query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$prefix.'aigc_music_asset']);
        if ((int)$music[0]['n']>0) {
            $rows=Db::query("SELECT COUNT(*) AS n FROM `{$prefix}tenant_file` f INNER JOIN `{$prefix}aigc_music_asset` a ON a.tenant_id=f.tenant_id AND a.user_id=f.source_id AND CAST(a.uri AS BINARY)=CAST(f.uri AS BINARY) WHERE f.upload_origin='' AND f.source=1 AND a.source_action='upload_audio'");
            if ((int)$rows[0]['n']!==0) throw new RuntimeException('音频上传来源尚未完成，禁止跳过原SQL');
        }
    }

    private static function path(): string { return root_path().'runtime/system-update-recovery/392.json'; }
    private static function requireCli(): void { if (PHP_SAPI!=='cli') throw new RuntimeException('恢复授权只允许站点管理员CLI操作'); }
    private static function digest(string $value): string { if (!preg_match('/^[a-f0-9]{64}$/D',$value)) throw new RuntimeException('恢复日志摘要无效');return $value; }
    private static function json(string $path): array { if (is_link($path) || !is_file($path)) throw new RuntimeException('恢复记录不存在或不安全');$value=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if (!is_array($value)) throw new RuntimeException('恢复记录无效');return $value; }
}
