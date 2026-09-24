<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\database\SqlMigrationExecutor as Sql;

// Run real migration statements against exact disposable test table names.
// No business table is dropped or altered, even inside the isolated database.
$prefixes=['agt_fresh_','agt_upgrade_','agt_full_','agt_root_'];
$suffixes=['aigc_short_drama_canvas','aigc_short_drama_canvas_mutation_receipt','aigc_short_drama_canvas_generation_intent'];
$created=[];
foreach ($prefixes as $prefix) foreach ($suffixes as $suffix) {
    $table=$prefix.$suffix;
    if (Db::query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table])) throw new RuntimeException('Existing migration fixture must be reviewed: '.$table);
}
function graphDdl(string $path): string {
    $found=[];
    foreach (Sql::split((string)file_get_contents($path)) as $sql) {
        if (preg_match('/^CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas(?:_mutation_receipt|_generation_intent)?`/',$sql)) $found[]=$sql.';';
    }
    if (count($found)!==3) throw new RuntimeException('Missing graph schema source: '.$path);
    return implode("\n",$found);
}
function graphColumns(string $prefix,string $suffix): array {
    return Db::query('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY COLUMN_NAME',[$prefix.$suffix]);
}
function graphIndexes(string $prefix,string $suffix): array {
    return Db::query('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',[$prefix.$suffix]);
}
try {
    // Names were checked absent above; track before DDL for partial-failure cleanup.
    foreach ($prefixes as $prefix) foreach ($suffixes as $suffix) $created[]=$prefix.$suffix;
    $migration=(string)file_get_contents(dirname(__DIR__,2).'/migrations/upgrade_20260921_canvas_graph_revision.sql');
    Sql::execute(graphDdl(dirname(__DIR__,2).'/migrations/install.sql'),'agt_fresh_',null,false);
    Sql::execute(graphDdl(root_path().'public/install/db/like.sql'),'agt_full_',null,false);
    // Old schema without graph columns; pre-existing JSON must survive upgrade.
    foreach (Sql::split((string)file_get_contents(dirname(__DIR__,2).'/migrations/upgrade_20260918_short_drama_canvas.sql')) as $statement) {
        if (str_starts_with($statement,'CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas`')) Sql::execute($statement.';','agt_upgrade_',null,false);
    }
    Sql::execute((string)file_get_contents(dirname(__DIR__,2).'/migrations/upgrade_20260919_canvas_removed_nodes.sql'),'agt_upgrade_',null,false);
    // Early draft receipt table used a case-insensitive database default.
    foreach (Sql::split(graphDdl(dirname(__DIR__,2).'/migrations/install.sql')) as $statement) {
        if (str_starts_with($statement,'CREATE TABLE IF NOT EXISTS `la_aigc_short_drama_canvas_mutation_receipt`')) Sql::execute(str_replace('CHARACTER SET ascii COLLATE ascii_bin','',$statement).';','agt_upgrade_',null,false);
    }
    $receipt=['tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>1,'request_hash'=>str_repeat('a',64),'base_revision'=>0,'result_revision'=>1,'result_json'=>'{}','create_time'=>time()];
    Db::table('agt_upgrade_aigc_short_drama_canvas_mutation_receipt')->insert($receipt+['request_key'=>'LegacyKey']);
    Db::table('agt_upgrade_aigc_short_drama_canvas')->insert(['tenant_id'=>91001,'user_id'=>92001,'nodes_json'=>'[{"id":1,"type":"text","x":7}]','edges_json'=>'[]','viewport_json'=>'{"k":1}','removed_node_ids_json'=>'[9]']);
    Sql::execute($migration,'agt_upgrade_',null,false);
    Sql::execute($migration,'agt_upgrade_',null,false);
    Sql::execute($migration,'agt_fresh_',null,false);
    agentCheck(Db::table('agt_upgrade_aigc_short_drama_canvas')->value('nodes_json')==='[{"id":1,"type":"text","x":7}]','repeated upgrade preserves old graph JSON');
    agentCheck(Db::table('agt_upgrade_aigc_short_drama_canvas')->value('removed_node_ids_json')==='[9]','repeated upgrade preserves deletion tombstones');
    agentCheck(Db::table('agt_upgrade_aigc_short_drama_canvas_mutation_receipt')->value('request_key')==='LegacyKey','repeated upgrade preserves existing mutation receipt');
    Sql::execute((string)file_get_contents(root_path().'upgrade/20260921_short_drama_canvas_graph_revision.sql'),'agt_root_',null,false);
    Sql::execute((string)file_get_contents(root_path().'upgrade/20260921_short_drama_canvas_graph_revision.sql'),'agt_root_',null,false);
    $intentMigration=(string)file_get_contents(dirname(__DIR__,2).'/migrations/upgrade_20260921_canvas_generation_intent.sql');
    Sql::execute($intentMigration,'agt_upgrade_',null,false);
    $intent=['tenant_id'=>91001,'user_id'=>92001,'canvas_id'=>1,'request_key'=>'RetainedIntent','request_hash'=>str_repeat('b',64),'canvas_run_id'=>1,'node_id'=>'1','snapshot_json'=>'{"input":{"prompt":"preserved"}}','state'=>'prepared','create_time'=>time(),'update_time'=>time()];
    Db::table('agt_upgrade_aigc_short_drama_canvas_generation_intent')->insert($intent);
    Sql::execute($intentMigration,'agt_upgrade_',null,false);
    Sql::execute($intentMigration,'agt_fresh_',null,false);
    Sql::execute((string)file_get_contents(root_path().'upgrade/20260921_short_drama_canvas_generation_intent.sql'),'agt_root_',null,false);
    Sql::execute((string)file_get_contents(root_path().'upgrade/20260921_short_drama_canvas_generation_intent.sql'),'agt_root_',null,false);
    agentCheck(Db::table('agt_upgrade_aigc_short_drama_canvas_generation_intent')->value('snapshot_json')===$intent['snapshot_json'],'repeated intent migration preserves frozen submission snapshot');
    foreach ($suffixes as $suffix) {
        $expected=graphColumns('agt_fresh_',$suffix);
        foreach (['agt_upgrade_','agt_full_','agt_root_'] as $prefix) agentCheck(graphColumns($prefix,$suffix)===$expected,'schema parity '.$prefix.$suffix);
        foreach (['agt_upgrade_','agt_full_','agt_root_'] as $prefix) agentCheck(graphIndexes($prefix,$suffix)===graphIndexes('agt_fresh_',$suffix),'index parity '.$prefix.$suffix);
    }
    Db::table('agt_fresh_aigc_short_drama_canvas_mutation_receipt')->insert($receipt+['request_key'=>'CaseKey']);
    Db::table('agt_fresh_aigc_short_drama_canvas_mutation_receipt')->insert($receipt+['request_key'=>'casekey']);
    agentCheck(Db::table('agt_fresh_aigc_short_drama_canvas_mutation_receipt')->count()===2,'request keys retain case-sensitive identity');
} finally {
    foreach (array_reverse($created) as $table) Db::execute('DROP TABLE IF EXISTS `'.$table.'`');
}
echo "NOT_RUN full installer/app registry/new-tenant lifecycle; real SQL and schema parity only\n";
