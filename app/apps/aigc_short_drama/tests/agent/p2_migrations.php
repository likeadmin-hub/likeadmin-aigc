<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\database\SqlMigrationExecutor as Sql;

$prefixes=['ag2_fresh_','ag2_full_','ag2_upgrade_','ag2_root_'];
$suffixes=array_map(static fn($kind)=>'aigc_short_drama_canvas_agent_'.$kind,['thread','message','run','event','outbox','preference','safety_audit']);
$created=[];
foreach ($prefixes as $prefix) foreach ($suffixes as $suffix) {
    if (Db::query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$prefix.$suffix])) throw new RuntimeException('Existing migration fixture: '.$prefix.$suffix);
}
function conversationDdl(string $path): string {
    $found=[];
    foreach (Sql::split((string)file_get_contents($path)) as $sql) {
        if (preg_match('/^CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_agent_(thread|message|run|event|outbox|preference)`/',$sql)) $found[]=$sql.';';
    }
    if (count($found)!==6) throw new RuntimeException('Missing conversation schema: '.$path);
    return implode("\n",$found);
}
function safetyDdl(string $path): string {
    $found=[];
    foreach (Sql::split((string)file_get_contents($path)) as $sql) {
        if (preg_match('/^CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_agent_safety_audit`/',$sql)) $found[]=$sql.';';
    }
    if (count($found)!==1) throw new RuntimeException('Missing safety schema: '.$path);
    return $found[0];
}
function conversationSchema(string $table, bool $indexes=false): array {
    return $indexes
        ? Db::query('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',[$table])
        : Db::query('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY COLUMN_NAME',[$table]);
}
try {
    foreach ($prefixes as $prefix) foreach ($suffixes as $suffix) $created[]=$prefix.$suffix;
    $sources=[
        'ag2_fresh_'=>dirname(__DIR__,2).'/migrations/install.sql',
        'ag2_full_'=>root_path().'public/install/db/like.sql',
        'ag2_upgrade_'=>dirname(__DIR__,2).'/migrations/upgrade_20260921_canvas_agent_conversations.sql',
        'ag2_root_'=>root_path().'upgrade/20260921_short_drama_canvas_agent_conversations.sql',
    ];
    $safetySources=[
        'ag2_fresh_'=>dirname(__DIR__,2).'/migrations/upgrade_20260921_canvas_agent_safety.sql',
        'ag2_full_'=>root_path().'public/install/db/like.sql',
        'ag2_upgrade_'=>dirname(__DIR__,2).'/migrations/upgrade_20260921_canvas_agent_safety.sql',
        'ag2_root_'=>root_path().'upgrade/20260921_short_drama_canvas_agent_safety.sql',
    ];
    foreach ($sources as $prefix=>$path) Sql::execute(conversationDdl($path),$prefix,null,false);
    foreach ($safetySources as $prefix=>$path) Sql::execute(safetyDdl($path),$prefix,null,false);
    foreach ($suffixes as $suffix) foreach (['ag2_full_','ag2_upgrade_','ag2_root_'] as $prefix) {
        agentCheck(conversationSchema($prefix.$suffix)===conversationSchema('ag2_fresh_'.$suffix),'column parity '.$prefix.$suffix);
        agentCheck(conversationSchema($prefix.$suffix,true)===conversationSchema('ag2_fresh_'.$suffix,true),'index parity '.$prefix.$suffix);
    }
    $row=['tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>1,'request_key'=>'CaseKey','request_hash'=>str_repeat('a',64),'title'=>'保留会话','settings_json'=>'{"reasoning_model":"test"}','create_time'=>time(),'update_time'=>time()];
    Db::table('ag2_upgrade_aigc_short_drama_canvas_agent_thread')->insert($row);
    Sql::execute(conversationDdl($sources['ag2_upgrade_']),'ag2_upgrade_',null,false);
    Sql::execute(conversationDdl($sources['ag2_upgrade_']),'ag2_upgrade_',null,false);
    agentCheck(Db::table('ag2_upgrade_aigc_short_drama_canvas_agent_thread')->value('settings_json')===$row['settings_json'],'repeated migration retains conversation preferences');
    Db::table('ag2_upgrade_aigc_short_drama_canvas_agent_thread')->insert(array_replace($row,['request_key'=>'casekey']));
    agentCheck(Db::table('ag2_upgrade_aigc_short_drama_canvas_agent_thread')->count()===2,'conversation request keys are case-sensitive');
    $duplicate=false;
    try { Db::table('ag2_upgrade_aigc_short_drama_canvas_agent_thread')->insert($row); } catch (Throwable $error) { $duplicate=str_contains($error->getMessage(),'Duplicate entry'); }
    agentCheck($duplicate,'same scoped request key has database uniqueness');
    Db::table('ag2_upgrade_aigc_short_drama_canvas_agent_thread')->insert(array_replace($row,['tenant_id'=>91002]));
    agentCheck(Db::table('ag2_upgrade_aigc_short_drama_canvas_agent_thread')->count()===3,'independent tenant request keys do not collide');
    Db::table('ag2_upgrade_aigc_short_drama_canvas_agent_preference')->insert(['tenant_id'=>91001,'user_id'=>92001,'preferences_json'=>'{"reasoning_model":"1"}','revision'=>1,'create_time'=>time(),'update_time'=>time()]);
    $duplicate=false;
    try { Db::table('ag2_upgrade_aigc_short_drama_canvas_agent_preference')->insert(['tenant_id'=>91001,'user_id'=>92001,'preferences_json'=>'{}','revision'=>1,'create_time'=>time(),'update_time'=>time()]); } catch (Throwable $error) { $duplicate=str_contains($error->getMessage(),'Duplicate entry'); }
    agentCheck($duplicate,'account preference has one tenant/user row');
    Db::table('ag2_upgrade_aigc_short_drama_canvas_agent_safety_audit')->insert(['tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>1,'thread_id'=>1,'run_id'=>0,'app_code'=>'aigc_short_drama','request_key'=>'blocked','direction'=>'input','policy_version'=>'canvas-agent-default-v1','decision'=>'blocked','reason_code'=>'tenant_rule','content_sha256'=>str_repeat('b',64),'content_length'=>12,'provider_submitted'=>0,'expires_at'=>time()+86400,'create_time'=>time()]);
    $duplicate=false;
    try { Db::table('ag2_upgrade_aigc_short_drama_canvas_agent_safety_audit')->insert(['tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>1,'thread_id'=>1,'run_id'=>0,'app_code'=>'aigc_short_drama','request_key'=>'blocked','direction'=>'input','policy_version'=>'canvas-agent-default-v1','decision'=>'blocked','reason_code'=>'tenant_rule','content_sha256'=>str_repeat('b',64),'content_length'=>12,'provider_submitted'=>0,'expires_at'=>time()+86400,'create_time'=>time()]); } catch (Throwable $error) { $duplicate=str_contains($error->getMessage(),'Duplicate entry'); }
    agentCheck($duplicate,'safety audit deduplicates one scoped input decision without raw text');
} finally {
    foreach (array_reverse($created) as $table) Db::execute('DROP TABLE IF EXISTS `'.$table.'`');
}
echo "NOT_RUN full installer, business database migration, conversation dispatch and real Provider\n";
