<?php
// Run manually only after owner approval. DDL is not rollbackable in MySQL.
if(!in_array('--apply',$argv,true)){fwrite(STDERR,"Refusing migration without --apply\n");exit(1);}
require __DIR__.'/bootstrap.php';
\app\common\service\database\SqlMigrationExecutor::execute(file_get_contents($root.'/upgrade/20261021_redeem_card.sql'),(string)config('database.connections.mysql.prefix'));
// Shared menus are seeded by the SQL. Existing split tables synchronize on the
// next tenant MenuLogic load; do not seed or change unrelated pricing/plans here.
echo "Redeem card local schema/shared menus complete; reload tenant menus for split tables\n";
