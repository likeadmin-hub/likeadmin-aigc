<?php
/** Real worker processes and killed connections; all data lives in disposable installer tables. */
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
use think\facade\Db;
use app\common\service\open_platform\OpenStore as S;
use app\common\service\open_platform\OpenVault as Vault;
use app\common\service\open_platform\OpenMarketBilling as Billing;
use app\common\command\OpenPlatformWorker;
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
function waitFor(callable $condition,string $why,int $seconds=10): void {
    $deadline=microtime(true)+$seconds;
    do{if($condition())return;usleep(20000);}while(microtime(true)<$deadline);
    throw new RuntimeException($why);
}
$original=config('database');$mysql=$original['connections']['mysql'];
check(PHP_SAPI==='cli'&&$mysql['hostname']==='127.0.0.1'&&$mysql['database']==='z_cn','Local process fixtures only');
function fixtureConfig(array $original,string $prefix): void {
    check(preg_match('/^opf_[a-f0-9]{24}_$/D',$prefix),'Unsafe fixture prefix');
    $test=$original;$test['default']='open_platform_process';
    $test['connections']['open_platform_process']=array_replace($original['connections']['mysql'],['prefix'=>$prefix,'break_reconnect'=>false]);
    $test['connections']['mysql']=$test['connections']['open_platform_process'];
    app()->config->set($test,'database');$config=new think\Config();$config->set($test,'database');Db::setConfig($config);
}
if(in_array($argv[1]??'',['--worker','--upstream'],true)){
    $mode=$argv[1];$prefix=(string)($argv[2]??'');$dir=(string)($argv[3]??'');
    check(preg_match('#^/tmp/opf_worker_([a-f0-9]{24})$#D',$dir,$match)&&$prefix==='opf_'.$match[1].'_'&&is_file($dir.'/owner'),'Not an owned process fixture');
    if($mode==='--upstream'){
        $server=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);check(is_resource($server),'Loopback receipt fixture unavailable');
        file_put_contents($dir.'/upstream-ready',stream_socket_get_name($server,false));
        $number=0;
        while($client=@stream_socket_accept($server,20)){
            stream_set_timeout($client,5);$headers='';
            while(($line=fgets($client))!==false){$headers.=$line;if(trim($line)==='')break;}
            check(str_starts_with($headers,"GET /api/v1/tasks/fixture "),'Recovery resubmitted instead of querying');
            $number++;file_put_contents($dir.'/query-'.$number,'GET');
            waitFor(fn()=>is_file($dir.'/release-'.$number),'Fixture response barrier timed out',30);
            $body='{"data":{"status":"completed"}}';
            @fwrite($client,"HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);
            fclose($client);
        }
        fclose($server);exit(0);
    }
    fixtureConfig($original,$prefix);
    $role=(string)($argv[4]??'');check(preg_match('/^[a-z0-9-]+$/D',$role),'Invalid child role');
    check(S::table('request')->where('request_id','req_worker_fixture')->count()===1,'Missing owned disposable request');
    $connection=(int)Db::query('SELECT CONNECTION_ID() AS connection_id')[0]['connection_id'];
    file_put_contents($dir.'/ready-'.$role,(string)$connection);
    $command=new OpenPlatformWorker();$output=new think\console\Output('buffer');
    $exit=$command->run(new think\console\Input(($argv[5]??'')==='once'?['--once']:[]),$output);
    echo $output->fetch();exit($exit);
}
check(function_exists('proc_open')&&function_exists('posix_kill'),'Independent worker processes required');
$tag=bin2hex(random_bytes(12));$prefix='opf_'.$tag.'_';$dir='/tmp/opf_worker_'.$tag;
check(mkdir($dir,0700)&&file_put_contents($dir.'/owner',$tag)!==false,'Cannot create owned fixture directory');
$pdo=new PDO('mysql:host='.$mysql['hostname'].';port='.($mysql['hostport']?:3306).';dbname=z_cn;charset=utf8mb4',$mysql['username'],$mysql['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
$tables=[];$children=[];$report=['provider_calls'=>0,'live_point_mutations'=>0,'scenarios'=>[]];
$spawn=static function(string $mode,string $role='',bool $once=false)use(&$children,$prefix,$dir){
    $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,$mode,$prefix,$dir,$role,$once?'once':'loop'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    check(is_resource($process),'Cannot start fixture process');fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    $id=count($children);$children[$id]=['process'=>$process,'pipes'=>$pipes,'stdout'=>'','stderr'=>'','exit'=>null];return $id;
};
$finish=static function(int $id,int $expected)use(&$children){
    waitFor(function()use(&$children,$id){$child=&$children[$id];$child['stdout'].=stream_get_contents($child['pipes'][1]);$child['stderr'].=stream_get_contents($child['pipes'][2]);$status=proc_get_status($child['process']);if(!$status['running']){$child['exit']=$status['exitcode'];return true;}return false;},'Worker did not exit');
    $child=&$children[$id];$child['stdout'].=stream_get_contents($child['pipes'][1]);$child['stderr'].=stream_get_contents($child['pipes'][2]);
    foreach($child['pipes'] as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($child['process']);$child['process']=null;
    check($child['exit']===$expected&&$child['stderr']===''&&!preg_match('/Fatal error|Exception trace:/',$child['stdout']),'Unexpected worker exit: '.S::encode(['exit'=>$child['exit'],'stdout'=>$child['stdout'],'stderr'=>$child['stderr']]));return $child['stdout'];
};
try{
    $sql=str_replace('la_',$prefix,file_get_contents(dirname(__DIR__,2).'/public/install/db/like.sql'));
    check(!preg_match('/^\s*(?:USE\b|(?:DROP|CREATE)\s+DATABASE\b)/im',$sql),'Installer escaped selected schema');
    preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`([^`]+)`/i',$sql,$matches);$tables=array_values(array_unique($matches[1]));
    foreach($tables as $table)check(str_starts_with($table,$prefix)&&preg_match('/^[a-z0-9_]+$/D',$table),'Unsafe installer table');$pdo->exec($sql);
    fixtureConfig($original,$prefix);check(config('database.connections.mysql.prefix')===$prefix,'Not isolated');
    $tenant=123456;$user=123456;
    Db::name('tenant')->insert(['id'=>$tenant,'sn'=>'opf_worker','name'=>'worker fixture','disable'=>0,'tactics'=>0,'point_balance'=>100,'delete_time'=>null]+S::timestamp());
    Db::name('user')->insert(['id'=>$user,'tenant_id'=>$tenant,'sn'=>12345678,'account'=>'opf_worker','nickname'=>'fixture','user_money'=>100,'delete_time'=>null]+S::timestamp());
    $upstream=$spawn('--upstream');waitFor(fn()=>is_file($dir.'/upstream-ready'),'No loopback fixture address');
    $source=['base_url'=>'http://'.file_get_contents($dir.'/upstream-ready'),'api_key'=>'fixture-only','timeout'=>10,'ssl_verify'=>false];
    $snapshot=['_execution_version'=>2,'dispatch_started'=>true,'product_id'=>1,'source_blob'=>Vault::seal(S::encode($source)),
        'contract'=>['executor'=>'http','query_path'=>'/api/v1/tasks/{task_id}','status_path'=>'data.status'],
        'rules'=>[['sku_id'=>1,'basis'=>'input','units'=>1,'max_units'=>1,'unit_size'=>1,'prices'=>['user'=>5,'tenant'=>1]]]];
    $request=['request_id'=>'req_worker_fixture','tenant_id'=>$tenant,'user_id'=>$user,'key_id'=>0,'kind'=>'market','method'=>'POST','path'=>'/api/v1/tasks','upstream_task_id'=>'fixture','status'=>'running','billing_status'=>'pending','active'=>1,'consumption_ids'=>'[]','payload_hash'=>hash('sha256',$tag),'snapshot'=>S::encode($snapshot),'response_blob'=>'','budget_user'=>5,'budget_tenant'=>1,'next_run_time'=>time()]+S::timestamp();
    $request['id']=S::table('request')->insertGetId($request);Billing::reserve($request,['user'=>5,'tenant'=>1,'snapshot'=>$snapshot]);
    // Kill during an actual GET after the worker durably owns the lease.
    $killed=$spawn('--worker','killed');waitFor(fn()=>is_file($dir.'/query-1'),'Worker did not start receipt query');
    $leased=S::table('request')->where('id',$request['id'])->find();check($leased['lease_token']!==''&&(int)$leased['lease_expire']>time(),'Query ran without durable lease');
    $pid=proc_get_status($children[$killed]['process'])['pid'];check(posix_kill($pid,SIGKILL),'Cannot kill owned child');
    waitFor(fn()=>!proc_get_status($children[$killed]['process'])['running'],'Killed child still running');
    foreach($children[$killed]['pipes'] as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($children[$killed]['process']);$children[$killed]['process']=null;touch($dir.'/release-1');
    $early=$spawn('--worker','early',true);check(str_contains($finish($early,0),'Processed 0'),'Unexpired lease stolen');
    check(!is_file($dir.'/query-2')&&S::table('request')->where('id',$request['id'])->value('lease_token')===$leased['lease_token'],'Restart resubmitted or erased live lease');
    S::table('request')->where('id',$request['id'])->update(['lease_expire'=>time()-1]);touch($dir.'/release-2');
    $restart=$spawn('--worker','restart',true);check(str_contains($finish($restart,0),'Processed 1'),'Expired lease not recovered');
    $done=S::table('request')->where('id',$request['id'])->find();check($done['status']==='success'&&$done['billing_status']==='settled'&&(int)$done['active']===0&&(float)$done['actual_user']===5.&&$done['lease_token']==='','Recovered receipt did not settle');
    $repeat=$spawn('--worker','repeat',true);check(str_contains($finish($repeat,0),'Processed 0')&&!is_file($dir.'/query-3'),'Completed request was queried or settled again');
    check((float)Db::name('user')->where('id',$user)->value('user_money')===95.&&(float)Db::name('tenant')->where('id',$tenant)->value('point_balance')===99.,'Recovery repeated a fixture wallet charge');
    check(Db::name('user_account_log')->where('user_id',$user)->count()===1&&Db::name('tenant_point_log')->where('tenant_id',$tenant)->count()===1,'Recovery duplicated independent ledgers');
    $report['scenarios'][]='SIGKILL during GET, lease retained, expired takeover, single settlement';
    // Kill only the connection ID advertised by our isolated child, while idle.
    $disconnect=$spawn('--worker','disconnect');waitFor(fn()=>is_file($dir.'/ready-disconnect'),'No child database ID');usleep(150000);
    $connection=(int)file_get_contents($dir.'/ready-disconnect');check($connection>0,'Invalid child connection');$pdo->exec('KILL CONNECTION '.$connection);
    check(str_contains($finish($disconnect,1),'Database connection lost; worker stopped for supervisor restart.'),'Disconnect did not retire worker');
    $after=$spawn('--worker','after-disconnect',true);check(str_contains($finish($after,0),'Processed 0'),'Fresh worker did not recover after disconnect');
    check((float)Db::name('user')->where('id',$user)->value('user_money')===95.&&Db::name('user_account_log')->where('user_id',$user)->count()===1,'Disconnected process replayed billing');
    $report['scenarios'][]='KILL owned idle database connection, exit 1, fresh connection restart';
    $signal=$spawn('--worker','signal');waitFor(fn()=>is_file($dir.'/ready-signal'),'No signal child');usleep(150000);
    check(posix_kill(proc_get_status($children[$signal]['process'])['pid'],SIGTERM),'Cannot terminate owned worker');$finish($signal,0);
    $report['scenarios'][]='SIGTERM graceful exit 0';$report['result']='passed';
    echo "PASS actual worker SIGKILL during receipt query, lease takeover and single fixture settlement; real MySQL disconnect retirement/restart; SIGTERM shutdown; no paid provider or live wallet changes\n";
}finally{
    foreach($children as $child){if(!is_resource($child['process']))continue;proc_terminate($child['process'],SIGKILL);foreach($child['pipes'] as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($child['process']);}
    app()->config->set($original,'database');$restore=new think\Config();$restore->set($original,'database');Db::setConfig($restore);
    foreach(array_reverse($tables) as $table)$pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');
    foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);$report['fixture_removed']=true;
    file_put_contents(dirname(__DIR__,2).'/runtime/open-platform-acceptance/worker-process.json',S::encode($report));
}
