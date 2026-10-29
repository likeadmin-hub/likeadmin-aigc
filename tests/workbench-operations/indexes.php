<?php
/** Disposable custom-prefix tables only; never alters the application's business tables. */
$root=dirname(__DIR__,2);
require $root.'/runtime-loader.php';
(new AigcRuntimeApp($root))->initialize();

use think\facade\Db;
use app\common\service\database\SqlMigrationExecutor;
use app\common\service\workbench\OperationsRange;

function indexCheck(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function operationIndexes(string $table): array {
    $indexes=[];
    foreach (Db::query('SHOW INDEX FROM `'.$table.'`') as $row) {
        if (str_starts_with($row['Key_name'],'idx_ops_')) $indexes[$row['Key_name']][(int)$row['Seq_in_index']]=$row['Column_name'];
    }
    foreach ($indexes as &$columns) {ksort($columns);$columns=array_values($columns);}unset($columns);
    ksort($indexes);return $indexes;
}
function operationProbe(string $sql): array {
    $plan=Db::query('EXPLAIN '.$sql)[0];
    $start=microtime(true);$result=Db::query($sql);
    return ['key'=>$plan['key'],'type'=>$plan['type'],'rows'=>(int)$plan['rows'],'ms'=>round((microtime(true)-$start)*1000,3),'result'=>$result];
}

$c=config('database.connections.mysql');
indexCheck(PHP_SAPI==='cli' && ($c['hostname']??'')==='127.0.0.1' && ($c['database']??'')==='z_cn' && Db::query('SELECT DATABASE() db')[0]['db']==='z_cn','Verified local z_cn database required');
$migration=$root.'/upgrade/20261029_workbench_operations_indexes.sql';
indexCheck(is_file($migration),'Dashboard date-range index migration is missing');
$sql=(string)file_get_contents($migration);
indexCheck($sql===file_get_contents($root.'/public/upgrade/20261029_workbench_operations_indexes.sql'),'Both upgrade surfaces must ship identical SQL');
$expected=[
    'ai_app_task'=>['idx_ops_parent_time'=>['parent_task_id','create_time'],'idx_ops_tenant_parent_time'=>['tenant_id','parent_task_id','create_time']],
    'ai_consumption_log'=>['idx_ops_billing_finish'=>['billing_status','finish_time'],'idx_ops_tenant_billing_finish'=>['tenant_id','billing_status','finish_time']],
];
$nonce=bin2hex(random_bytes(5));$fresh='opidx_f_'.$nonce.'_';$legacy='opidx_l_'.$nonce.'_';$created=[];
$originalMode=Db::query('SELECT @@SESSION.sql_mode mode')[0]['mode'];
try {
    Db::execute('SET SESSION sql_mode=?',[$originalMode.',ONLY_FULL_GROUP_BY']);
    $install=(string)file_get_contents($root.'/public/install/db/like.sql');
    foreach ($expected as $table=>$indexes) {
        indexCheck((bool)preg_match('/CREATE TABLE IF NOT EXISTS `la_'.$table.'`.*?;/s',$install,$match),'Missing install schema '.$table);
        $create=str_replace('`la_'.$table.'`','`'.$fresh.$table.'`',$match[0]);
        Db::execute($create);$created[]=$fresh.$table;
        indexCheck(operationIndexes($fresh.$table)===$indexes,'Fresh-install indexes differ from the independently expected column order: '.$table);
        Db::execute('CREATE TABLE `'.$legacy.$table.'` LIKE `'.$fresh.$table.'`');$created[]=$legacy.$table;
        foreach (array_keys($indexes) as $index) Db::execute('ALTER TABLE `'.$legacy.$table.'` DROP INDEX `'.$index.'`');
    }
    // Ten tenants, 365 daily cohorts and 100,000 records: recent tenant windows are selective.
    $range=OperationsRange::resolve(['range'=>'7d'],strtotime('2025-02-07 12:30:00 +0800'));
    $base=$range['start_at']-360*86400;
    $digits='(SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
    $n='(a.n+10*b.n+100*c.n+1000*d.n+10000*e.n)';
    $cross=" FROM {$digits} a CROSS JOIN {$digits} b CROSS JOIN {$digits} c CROSS JOIN {$digits} d CROSS JOIN {$digits} e";
    $createdAt="({$base}+MOD(FLOOR({$n}/10),365)*86400+3600)";
    $finishedAt="({$createdAt}+MOD({$n},3)*86400)";
    Db::execute("INSERT INTO `{$legacy}ai_app_task` (id,task_no,tenant_id,user_id,app_code,parent_task_id,status,idempotency_key,create_time) SELECT {$n}+1,CONCAT('task-',{$n}),1+MOD({$n},10),1+MOD({$n},300),'operations_fixture',IF(MOD({$n},5)=0,1,0),IF(MOD({$n},3)=0,'success','failed'),CONCAT('idem-',{$n}),{$createdAt}{$cross}");
    Db::execute("INSERT INTO `{$legacy}ai_consumption_log` (id,consume_no,tenant_id,user_id,billing_status,actual_tenant_cost,actual_user_price,create_time,finish_time) SELECT {$n}+1,CONCAT('consumption-',{$n}),1+MOD({$n},10),1+MOD({$n},300),IF(MOD({$n},4)=0,'reserved','settled'),1.25,2.50,{$createdAt},{$finishedAt}{$cross}");
    $from=$range['start_at'];$to=$range['end_at'];
    $probes=[];
    foreach (['tenant'=>7,'platform'=>0] as $scope=>$tenant) {
        $owner=$tenant?'tenant_id=7 AND ':'';
        $probes['tasks_'.$scope]="SELECT COUNT(*) total,SUM(status='success') success,COUNT(DISTINCT CONCAT(tenant_id,':',user_id)) active FROM `{$legacy}ai_app_task` WHERE {$owner}parent_task_id=0 AND create_time>={$from} AND create_time<{$to}";
        $probes['settlements_'.$scope]="SELECT COUNT(*) total,SUM(actual_tenant_cost) tenant_points,SUM(actual_user_price) user_points FROM `{$legacy}ai_consumption_log` WHERE {$owner}billing_status='settled' AND finish_time>={$from} AND finish_time<{$to}";
    }
    $expectedTotals=array_fill_keys(array_keys($probes),0);$expectedSuccess=['tasks_tenant'=>0,'tasks_platform'=>0];$active=['tasks_tenant'=>[],'tasks_platform'=>[]];
    for ($i=0;$i<100000;$i++) {
        $tenant=1+$i%10;$at=$base+intdiv($i,10)%365*86400+3600;$finish=$at+$i%3*86400;
        foreach (['platform'=>true,'tenant'=>$tenant===7] as $scope=>$include) {
            if (!$include) continue;
            if ($i%5!==0 && $at>=$from && $at<$to) {$key='tasks_'.$scope;$expectedTotals[$key]++;$expectedSuccess[$key]+=(int)($i%3===0);$active[$key][$tenant.':'.(1+$i%300)]=true;}
            if ($i%4!==0 && $finish>=$from && $finish<$to) $expectedTotals['settlements_'.$scope]++;
        }
    }
    foreach ($expected as $table=>$_) Db::query('ANALYZE TABLE `'.$legacy.$table.'`');
    $before=[];foreach ($probes as $key=>$query) $before[$key]=operationProbe($query);
    SqlMigrationExecutor::execute($sql,$legacy);
    SqlMigrationExecutor::execute($sql,$legacy);
    foreach ($expected as $table=>$indexes) {
        indexCheck(operationIndexes($legacy.$table)===$indexes,'Custom-prefix/repeated migration must match fresh install: '.$table);
        Db::query('ANALYZE TABLE `'.$legacy.$table.'`');
    }
    $keys=['tasks_tenant'=>'idx_ops_tenant_parent_time','tasks_platform'=>'idx_ops_parent_time','settlements_tenant'=>'idx_ops_tenant_billing_finish','settlements_platform'=>'idx_ops_billing_finish'];
    foreach ($probes as $key=>$query) {
        $after=operationProbe($query);$row=$after['result'][0];
        indexCheck($after['result']===$before[$key]['result'],'Adding indexes must not change aggregates: '.$key);
        indexCheck((int)$row['total']===$expectedTotals[$key],'Hand-derived tenant/history count mismatch: '.$key);
        if (isset($expectedSuccess[$key])) indexCheck((int)$row['success']===$expectedSuccess[$key] && (int)$row['active']===count($active[$key]),'Hand-derived success/distinct count mismatch: '.$key);
        else indexCheck(abs((float)$row['tenant_points']-$expectedTotals[$key]*1.25)<0.000001 && abs((float)$row['user_points']-$expectedTotals[$key]*2.5)<0.000001,'Only settled finish-time amounts belong in points: '.$key);
        indexCheck($after['key']===$keys[$key] && $after['type']==='range','Recent window should use the matching range index: '.$key);
        indexCheck($after['rows']<$before[$key]['rows']/10,'Index must bound scanned rows rather than only reduce query count: '.$key);
        echo json_encode(['probe'=>$key,'before'=>array_diff_key($before[$key],['result'=>true]),'after'=>array_diff_key($after,['result'=>true]),'matched_rows'=>$row['total']],JSON_UNESCAPED_UNICODE),PHP_EOL;
    }
    echo "PASS operations index fresh/custom/repeat parity, 100000-row tenant/history plans and unchanged aggregates\n";
} finally {
    foreach (array_reverse($created) as $table) Db::execute('DROP TABLE IF EXISTS `'.$table.'`');
    Db::execute('SET SESSION sql_mode=?',[$originalMode]);
}
foreach ($created as $table) indexCheck(Db::query('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])[0]['n']==0,'Disposable fixture table cleanup failed');
