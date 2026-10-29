<?php
require dirname(__DIR__,2).'/vendor/autoload.php';$app=new think\App();$app->initialize();
$config=config('database.connections.mysql');if(!in_array($config['hostname'],['127.0.0.1','localhost'],true))throw new RuntimeException('Local database required');
echo 'Local target: '.$config['hostname'].'/'.$config['database'].PHP_EOL;
app\common\service\app\aigc_copywriting\CopywritingBootstrap::migrate();echo "copywriting migration and tenant bootstrap PASS\n";
