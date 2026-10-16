<?php
// Old schemas in isolated local tables: no tenant business writes or provider calls.
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\database\{RuntimeMigrationPlan, SqlMigrationExecutor, CanvasConversationSchema};
function upgradeCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$db=config('database.connections.mysql');
upgradeCheck(PHP_SAPI==='cli' && $db['hostname']==='127.0.0.1' && in_array($db['database'],['x_cn','z_cn'],true),'Local isolated tables only');
$root=dirname(__DIR__,2); $prefix='auditupgrade_'.getmypid().'_';
$tables=['aigc_llm_session','aigc_llm_agent_preference','aigc_canvas_project'];
$prepare=file_get_contents($root.'/app/apps/aigc_canvas/migrations/zz_20261017_00_conversation_preferences.sql');
$snapshot=file_get_contents($root.'/app/apps/aigc_canvas/migrations/zz_20261017_project_agent_preferences.sql');
$legacy=file_get_contents($root.'/app/apps/aigc_llm/migrations/zz_20261016_canvas_conversation_scope.sql');
upgradeCheck($prepare===file_get_contents($root.'/upgrade/20261017_canvas_00_conversation_preferences.sql'),'Schema upgrade entry points differ');
upgradeCheck($legacy===file_get_contents($root.'/upgrade/zz_20261016_canvas_conversation_scope.sql'),'Legacy bridge entry points differ');
$execute=static function(string $sql)use($prefix):void {
    $plan=RuntimeMigrationPlan::build($sql);
    SqlMigrationExecutor::execute($plan['prepare'],$prefix);
    SqlMigrationExecutor::execute($plan['complete'],$prefix);
};
try {
    Db::execute("CREATE TABLE `{$prefix}aigc_llm_session` (id bigint unsigned PRIMARY KEY,tenant_id int unsigned,user_id int unsigned,title varchar(255)) ENGINE=InnoDB");
    Db::execute("CREATE TABLE `{$prefix}aigc_llm_agent_preference` (id bigint unsigned PRIMARY KEY AUTO_INCREMENT,tenant_id int unsigned NOT NULL,user_id int unsigned NOT NULL,preferences mediumtext NOT NULL,create_time int unsigned NOT NULL,update_time int unsigned NOT NULL,UNIQUE KEY owner (tenant_id,user_id)) ENGINE=InnoDB");
    Db::execute("CREATE TABLE `{$prefix}aigc_canvas_project` (id bigint unsigned PRIMARY KEY,tenant_id int unsigned,user_id int unsigned,delete_time int unsigned) ENGINE=InnoDB");
    Db::execute("INSERT INTO `{$prefix}aigc_llm_session` VALUES (1,101,201,'historical home'),(2,102,201,'other tenant home')");
    Db::execute("INSERT INTO `{$prefix}aigc_llm_agent_preference` VALUES (1,101,201,'{\"mode\":\"auto\",\"models\":{}}',11,12),(2,102,201,'{\"mode\":\"ask\",\"models\":{}}',21,22)");
    Db::execute("INSERT INTO `{$prefix}aigc_canvas_project` VALUES (1,101,201,0),(2,101,201,0),(3,102,201,0),(4,101,202,0),(5,102,201,123)");
    // Worst order: canvas completes before llm has migrated its old schema.
    $execute($prepare); $execute($snapshot); $execute($legacy);
    $home=Db::query("SELECT * FROM `{$prefix}aigc_llm_agent_preference` WHERE workspace_key='aigc_llm' ORDER BY id");
    upgradeCheck(count($home)===2 && $home[0]['create_time']===11 && $home[0]['update_time']===12 && json_decode($home[0]['preferences'],true)['mode']==='auto','Home preferences rewritten');
    upgradeCheck(Db::query("SELECT COUNT(*) AS n FROM `{$prefix}aigc_llm_session` WHERE workspace_key='home'")[0]['n']===2,'Historical sessions lost home scope');
    $projects=Db::query("SELECT tenant_id,user_id,workspace_key,preferences FROM `{$prefix}aigc_llm_agent_preference` WHERE workspace_key LIKE 'canvas:%' ORDER BY workspace_key");
    upgradeCheck(count($projects)===4 && $projects[2]['tenant_id']===102 && $projects[3]['user_id']===202,'Tenant/user/deleted project boundaries changed');
    foreach($projects as $row)upgradeCheck(json_decode($row['preferences'],true)['mode']==='ask','Old home preference leaked into canvas');
    echo "PASS oldest schema, canvas-first order, home preservation and multiple tenant/user projects\n";
    // Intermediate release already has a shared canvas preference but old project snapshots are absent.
    Db::execute("INSERT INTO `{$prefix}aigc_llm_agent_preference` (tenant_id,user_id,workspace_key,preferences,create_time,update_time) VALUES (103,201,'aigc_canvas','{\"mode\":\"auto\",\"models\":{\"image\":\"historical\"}}',31,32)");
    Db::execute("INSERT INTO `{$prefix}aigc_canvas_project` VALUES (6,103,201,0),(7,103,201,0)");
    $execute($prepare);$execute($snapshot);
    $copied=Db::query("SELECT preferences FROM `{$prefix}aigc_llm_agent_preference` WHERE workspace_key IN ('canvas:6','canvas:7')");
    upgradeCheck(count($copied)===2 && json_decode($copied[0]['preferences'],true)['models']['image']==='historical','Intermediate canvas preference lost');
    Db::execute("UPDATE `{$prefix}aigc_llm_agent_preference` SET preferences='{\"mode\":\"ask\",\"models\":{\"image\":\"edited\"}}' WHERE workspace_key='canvas:6'");
    $before=Db::query("SELECT * FROM `{$prefix}aigc_llm_agent_preference` ORDER BY id");
    $execute($legacy);$execute($prepare);$execute($snapshot);
    upgradeCheck($before===Db::query("SELECT * FROM `{$prefix}aigc_llm_agent_preference` ORDER BY id"),'Repeated upgrade rewrote existing settings');
    echo "PASS intermediate schema and idempotent retries preserve edited project preferences\n";
    // Unknown dynamic SQL and unknown destructive DDL must stay forbidden.
    foreach([$legacy."\nSELECT 2;",str_replace('agent_scope_stmt','unknown_stmt',$legacy),'ALTER TABLE `la_aigc_llm_agent_preference` DROP INDEX `owner`'] as $bad){
        $rejected=false;try{RuntimeMigrationPlan::build($bad);}catch(RuntimeException $e){$rejected=true;}
        upgradeCheck($rejected,'Unreviewed SQL passed the runtime planner');
    }
    Db::execute("ALTER TABLE `{$prefix}aigc_llm_agent_preference` DROP INDEX owner, ADD KEY owner (tenant_id,user_id)");
    $rejected=false;try{CanvasConversationSchema::ensureOwner($prefix);}catch(RuntimeException $e){$rejected=true;}
    upgradeCheck($rejected && $before===Db::query("SELECT * FROM `{$prefix}aigc_llm_agent_preference` ORDER BY id"),'Unknown index mutation was not rejected');
    echo "PASS unknown SQL/schema rejected without rewriting data\n";
    // Standalone canvas install with no shared preference table at all.
    Db::execute("DROP TABLE `{$prefix}aigc_llm_agent_preference`");
    $execute($prepare);$execute($snapshot);$execute($prepare);$execute($snapshot);
    upgradeCheck(Db::query("SELECT COUNT(*) AS n FROM `{$prefix}aigc_llm_agent_preference`")[0]['n']===6,'Standalone fresh install failed');
    echo "PASS standalone canvas schema creation and repeated install\n";
    $baseline=config('runtime_migration_baseline') ?: [];
    foreach(['aigc_canvas','aigc_llm'] as $app)foreach(glob($root.'/app/apps/'.$app.'/migrations/*.sql') as $file){
        $relative='app/apps/'.$app.'/migrations/'.basename($file);
        if(!isset($baseline[$relative]))RuntimeMigrationPlan::build(file_get_contents($file));
    }
    echo "PASS all non-baseline canvas/conversation migrations classified by runtime updater\n";
} finally {foreach(array_reverse($tables) as $table)Db::execute("DROP TABLE IF EXISTS `{$prefix}{$table}`");}
