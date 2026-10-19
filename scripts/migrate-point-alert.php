<?php
require dirname(__DIR__).'/runtime-loader.php';
(new \AigcRuntimeApp(dirname(__DIR__)))->initialize();
$db=\think\facade\Db::connect();$config=$db->getConfig();
if(!in_array($config['hostname'],['127.0.0.1','localhost'],true)||$config['database']!=='z_cn')throw new RuntimeException('Migration runner restricted to local z_cn');
$prefix=$config['prefix'];if(!preg_match('/^\w+$/D',$prefix))throw new RuntimeException('Invalid prefix');
$sql=str_replace('`la_','`'.$prefix,file_get_contents(dirname(__DIR__).'/upgrade/20261019_point_alert.sql'));
echo 'Local target: '.$config['hostname'].'/'.$config['database'].PHP_EOL;
if(!in_array('--apply',$argv,true)){echo $sql;exit;}
foreach(explode(';',$sql) as $statement)if(trim($statement))$db->execute($statement);
echo "Point alert schema ready\n";
