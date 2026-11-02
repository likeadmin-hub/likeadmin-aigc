<?php
// Explicit local opt-in. Dedicated disposable tables, native competing writers, no provider or wallet calls.
require dirname(__DIR__,2).'/vendor/autoload.php';
(new think\App())->initialize();
set_exception_handler(static function(\Throwable $error){fwrite(STDERR,get_class($error).': '.$error->getMessage()."\n");exit(1);});
use think\facade\Db;
use app\common\service\power\{PowerMarketService,TenantPowerMarketService,MarketPriceRuleService};
function priceConcurrentCheck($ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function usePriceFixturePrefix(string $namespace):void {
    $settings=config('database');$settings['connections']['mysql']['prefix']=$namespace.'_';
    config($settings,'database');Db::connect('mysql',true);
    priceConcurrentCheck(Db::name('power_market_sku')->getTable()===$namespace.'_power_market_sku','Fixture prefix was not applied');
}
priceConcurrentCheck(getenv('MARKET_PRICE_CONCURRENCY')==='local-existing','Explicit local concurrency opt-in required');
$config=config('database.connections.mysql');
priceConcurrentCheck($config['hostname']==='127.0.0.1','Local DB only');
if(($argv[1]??'')==='child'){
    $database=$argv[2]??'';$kind=$argv[3]??'';
    priceConcurrentCheck((bool)preg_match('/^codex_price_[a-f0-9]{12}$/D',$database),'Invalid isolated DB');
    usePriceFixturePrefix($database);
    if(in_array($kind,['deadlockA','deadlockB'],true)){
        $first=$kind==='deadlockA'?1:2;$second=3-$first;$attempts=0;
        $directory=sys_get_temp_dir().'/'.$database;$peer=$kind==='deadlockA'?'deadlockB':'deadlockA';
        $table=$database.'_deadlock_fixture';
        \app\common\service\power\MarketPriceWriteTransaction::run(function()use(&$attempts,$first,$second,$directory,$kind,$peer,$table){
            $attempts++;
            Db::query("SELECT id FROM `$table` WHERE id=? FOR UPDATE",[$first]);
            Db::execute("UPDATE `$table` SET writes=writes+1 WHERE id=?",[$first]);
            touch($directory.'/'.$kind.'.ready');$deadline=microtime(true)+5;
            while(!is_file($directory.'/'.$peer.'.ready')){if(microtime(true)>$deadline)throw new RuntimeException('Deadlock fixture peer not ready');usleep(2000);}
            Db::query("SELECT id FROM `$table` WHERE id=? FOR UPDATE",[$second]);
            Db::execute("UPDATE `$table` SET writes=writes+1 WHERE id=?",[$second]);
        });
        echo json_encode(['writer'=>$kind,'transaction_attempts'=>$attempts])."\n";exit;
    }
    $conflicts=0;
    for($i=1;$i<=12;$i++){
        if($kind==='platform')PowerMarketService::savePrices(1,[['sku_id'=>1,'sale_points'=>20+$i,'sale_status'=>1]],['context_window'=>32768],1);
        elseif($kind==='tenant')TenantPowerMarketService::savePrices(1,1,[['sku_id'=>1,'sale_points'=>1000+$i,'sale_status'=>1]]);
        elseif($kind==='rule'){
            $preview=MarketPriceRuleService::preview(1,777,['action'=>'markup','scope'=>'product','ids'=>[1],'mode'=>'add','value'=>5]);
            try {MarketPriceRuleService::apply(1,777,$preview['token']);}
            catch(RuntimeException $error){if(!str_contains($error->getMessage(),'发生变化'))throw $error;$conflicts++;}
        }else throw new RuntimeException('Unknown writer');
        usleep(1000);
    }
    echo json_encode(['writer'=>$kind,'attempted'=>12,'honest_preview_conflicts'=>$conflicts])."\n";exit;
}
$database='codex_price_'.bin2hex(random_bytes(6));$active=$config['database'];$prefix=$config['prefix'];
$tables=['power_market_product','power_market_sku','tenant_power_market_sku_price','power_market_price_rule'];
$product=Db::name('power_market_product')->where(['resource_type'=>'model','status'=>1])->find();
$sku=Db::name('power_market_sku')->where(['status'=>1,'sale_status'=>1])->find();
priceConcurrentCheck($product&&$sku,'Local catalogue fixture required');
$children=[];$created=[];$directory=sys_get_temp_dir().'/'.$database;mkdir($directory,0700);
try{
    foreach($tables as $name){$source=$prefix.$name;$table=$database.'_'.$name;Db::execute("CREATE TABLE `$active`.`$table` LIKE `$active`.`$source`");$created[]=$table;}
    usePriceFixturePrefix($database);
    unset($product['id'],$sku['id']);$product['id']=1;$product['status']=1;
    Db::name('power_market_product')->insert($product);
    $sku=array_replace($sku,['id'=>1,'product_id'=>1,'status'=>1,'sale_status'=>1,'upstream_price'=>1,'sale_points'=>1]);
    Db::name('power_market_sku')->insert($sku);
    $fixture=$database.'_deadlock_fixture';Db::execute("CREATE TABLE `$fixture` (id INT PRIMARY KEY,writes INT NOT NULL DEFAULT 0) ENGINE=InnoDB");$created[]=$fixture;
    Db::execute("INSERT INTO `$fixture` (id) VALUES (1),(2)");
    foreach(['platform','tenant','rule','deadlockA','deadlockB'] as $kind){
        $process=proc_open([PHP_BINARY,__FILE__,'child',$database,$kind],[0=>['pipe','r'],1=>['file',$directory.'/'.$kind.'.log','w'],2=>['file',$directory.'/'.$kind.'.err','w']],$pipes);
        priceConcurrentCheck(is_resource($process),'Cannot start isolated writer');fclose($pipes[0]);$children[$kind]=$process;
    }
    foreach($children as $kind=>$process){$code=proc_close($process);unset($children[$kind]);priceConcurrentCheck($code===0,'Writer '.$kind.' failed: '.substr((string)file_get_contents($directory.'/'.$kind.'.err'),0,500));echo file_get_contents($directory.'/'.$kind.'.log');}
    $writes=Db::query("SELECT writes FROM `$fixture` ORDER BY id");priceConcurrentCheck(array_map(static fn($r)=>(int)$r['writes'],$writes)===[2,2],'Deadlock rollback duplicated or lost writes');
    $attempts=0;foreach(['deadlockA','deadlockB']as$kind)$attempts+=(int)json_decode(file_get_contents($directory.'/'.$kind.'.log'),true)['transaction_attempts'];
    priceConcurrentCheck($attempts>=3,'Real MySQL deadlock was not exercised');
    $row=Db::name('power_market_sku')->where('id',1)->find();
    priceConcurrentCheck((float)MarketPriceRuleService::platform($row)['price']===32.0,'Concurrent platform price lost');
    priceConcurrentCheck(MarketPriceRuleService::tenant(1,$row)['valid'],'Concurrent tenant price fell below current platform price');
    echo "PASS native platform/tenant/rule concurrent writers; coherent prices and real1213 rollback/retry; isolated tables removed; no business data changed\n";
}finally{
    foreach($children as $process){proc_terminate($process);proc_close($process);}
    foreach(array_reverse($created) as $table)Db::execute("DROP TABLE `$active`.`$table`");
}
