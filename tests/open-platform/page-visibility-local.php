<?php
/** Reversible local browser fixture; automatically restore on input, timeout or termination. */
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
use app\common\service\open_platform\OpenStore as S;
$db=config('database.connections.mysql');if(PHP_SAPI!=='cli'||$db['hostname']!=='127.0.0.1'||$db['database']!=='z_cn')throw new RuntimeException('Local browser fixture only');
$lock=fopen(dirname(__DIR__,2).'/runtime/open-platform-acceptance/paid.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Acceptance lock busy');
$backup=S::table('setting')->where('tenant_id',1)->find();$restored=false;
$restore=function()use($backup,&$restored){if($restored)return;if($backup)S::table('setting')->where('tenant_id',1)->update(['enabled'=>$backup['enabled'],'config'=>$backup['config'],'update_time'=>time()]);else S::table('setting')->where('tenant_id',1)->delete();$restored=true;};register_shutdown_function($restore);
if(function_exists('pcntl_async_signals')){pcntl_async_signals(true);foreach([SIGTERM,SIGINT] as $signal)pcntl_signal($signal,function()use($restore){$restore();exit(0);});}
try{
 $config=S::settings(1);unset($config['enabled']);foreach(['keys','docs','catalog','playground'] as $id)$config['pages'][$id]=array_replace($config['pages'][$id]??[],['visible'=>false]);$values=['config'=>S::encode($config),'update_time'=>time()];if($backup)S::table('setting')->where('tenant_id',1)->update($values);else S::table('setting')->insert($values+['tenant_id'=>1,'enabled'=>0,'create_time'=>time()]);
 echo "READY hidden keys/docs/catalog/playground for local tenant 1; send Return to restore (automatic timeout 180 seconds)\n";
 $read=[STDIN];$write=$except=[];stream_select($read,$write,$except,180);
}finally{$restore();echo "RESTORED original tenant page settings\n";}
