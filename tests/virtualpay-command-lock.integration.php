<?php
require __DIR__.'/Support/OemDisposable.php';require __DIR__.'/Support/OemFixtures.php';
use think\facade\Db;
if(in_array('--child',$argv,true)){echo \think\facade\Console::call('virtualpay:maintain')->fetch();exit;}
$db=(string)Db::query('SELECT DATABASE() AS db')[0]['db'];$key='vp-maintain:'.substr(hash('sha256',$db),0,40);
oemCheck((int)Db::query('SELECT GET_LOCK(?,0) AS n',[$key])[0]['n']===1,'hold actual maintenance command lock');
$children=[];
try{
 for($n=0;$n<2;$n++){$pipes=[];$process=proc_open([PHP_BINARY,__FILE__,'--child'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);oemCheck(is_resource($process),'child created');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$children[]=[$process,$pipes];}
 foreach($children as [$process,$pipes]){$deadline=microtime(true)+8;do{$state=proc_get_status($process);if(!$state['running'])break;usleep(20000);}while(microtime(true)<$deadline);oemCheck(!$state['running'],'duplicate scheduler exits without waiting');$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);oemCheck($state['exitcode']===0&&$out===''&&$err==='','cron and unified entry contenders skip work under the same database lock');}
 oemCheck((int)Db::query('SELECT IS_USED_LOCK(?) AS owner',[$key])[0]['owner']===(int)Db::query('SELECT CONNECTION_ID() AS id')[0]['id'],'contenders do not release active owner lock');
 echo "PASS real MySQL named lock prevents duplicate cron/unified reconciliation processes\n";
}finally{foreach($children as [$process,$pipes]){if(proc_get_status($process)['running'])proc_terminate($process);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);}Db::query('SELECT RELEASE_LOCK(?)',[$key]);}
