<?php
(new think\App())->initialize();
use think\facade\Db;
use think\facade\Config;
use app\common\service\power\MarketPriceRuleService as S;
$n=0;function ok($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
$c=config('database.connections.mysql');if($c['hostname']!=='127.0.0.1'||$c['database']!=='z_cn')throw new RuntimeException('Local fixture DB only');
$prefix='markup_fixture_'.bin2hex(random_bytes(5)).'_';$created=[];
try {
 foreach(['power_market_product','power_market_sku','tenant_power_market_sku_price','aigc_action_transfer_config'] as $name){Db::execute("CREATE TABLE `$prefix$name` LIKE `la_$name`");$created[]=$prefix.$name;}
 $sql=file_get_contents(dirname(__DIR__,2).'/upgrade/20261022_market_price_rules.sql');$ddl=substr($sql,0,strpos($sql,';')+1);Db::execute(str_replace('la_power_market_price_rule',$prefix.'power_market_price_rule',$ddl));$created[]=$prefix.'power_market_price_rule';
 $settings=config('database');$settings['connections']['markup_fixture']=$c;$settings['connections']['markup_fixture']['prefix']=$prefix;$settings['default']='markup_fixture';Config::set($settings,'database');
 $p=Db::name('power_market_product')->insertGetId(['name'=>'验收图片模型','product_code'=>'fixture','resource_type'=>'model','model_type'=>'image','status'=>1]);
 $sku=Db::name('power_market_sku')->insertGetId(['product_id'=>$p,'sku_key'=>'one','title'=>'按张','upstream_price'=>10,'sale_points'=>10,'sale_status'=>1,'status'=>1,'usage_unit'=>'image','usage_unit_size'=>1]);
 $row=Db::name('power_market_sku')->where('id',$sku)->find();
 $preview=S::preview(0,11,['mode'=>'percent','value'=>20]);ok($preview['rows'][0]['after']==='12.000000','real DB platform preview');S::apply(0,11,$preview['token']);
 $preview=S::preview(77,22,['mode'=>'percent','value'=>25]);ok($preview['rows'][0]['after']==='15.000000','tenant compound preview');S::apply(77,22,$preview['token']);
 ok(S::tenantPrice(77,$row)===15.0,'runtime quote 15');ok(S::tenantPrice(78,$row)===12.0,'tenant isolation');
 $stale=S::preview(77,22,['mode'=>'add','value'=>2]);Db::name('power_market_sku')->where('id',$sku)->update(['upstream_price'=>11]);
 $bad=false;try{S::apply(77,22,$stale['token']);}catch(RuntimeException $e){$bad=str_contains($e->getMessage(),'重新预览');}ok($bad,'upstream sync invalidates preview');
 $newRow=Db::name('power_market_sku')->where('id',$sku)->find();ok(S::tenantPrice(77,$newRow)===16.5,'upstream change follows both rules');
 $other=S::preview(77,22,['action'=>'restore','scope'=>'sku','ids'=>[$sku]]);$bad=false;try{S::apply(78,22,$other['token']);}catch(RuntimeException $e){$bad=true;}ok($bad,'foreign tenant cannot apply preview');
 $bad=false;try{S::apply(77,23,$other['token']);}catch(RuntimeException $e){$bad=true;}ok($bad,'foreign admin cannot apply preview');
 S::apply(77,22,$other['token']);ok(S::tenantPrice(77,$newRow)===13.2,'restore zero overrides tenant global but keeps platform premium');
 $platformRestore=S::preview(0,11,['action'=>'restore']);S::apply(0,11,$platformRestore['token']);ok(S::tenantPrice(77,$newRow)===11.0,'platform restore uses current upstream');
 $bad=false;try{S::apply(0,11,$platformRestore['token']);}catch(RuntimeException $e){$bad=true;}ok($bad,'preview consumed exactly once');
 $sku2=Db::name('power_market_sku')->insertGetId(['product_id'=>$p,'sku_key'=>'two','title'=>'按秒','upstream_price'=>5,'sale_points'=>5,'sale_status'=>1,'status'=>1,'usage_unit'=>'second','usage_unit_size'=>1]);
 $global=S::preview(77,22,['mode'=>'add','value'=>3,'replace_descendants'=>true]);S::apply(77,22,$global['token']);
 ok(S::tenantPrice(77,Db::name('power_market_sku')->where('id',$sku2)->find())===8.0,'new SKU inherits rules');
 $restore=S::preview(77,22,['action'=>'restore','scope'=>'product','ids'=>[$p]]);S::apply(77,22,$restore['token']);ok(S::tenantPrice(77,$newRow)===11.0,'product restore clears descendant rules');
 Db::transaction(fn()=>S::manual(77,$p,$sku,20));ok(S::tenantPrice(77,$newRow)===20.0,'manual SKU priority');
 $base=S::preview(77,22,['action'=>'restore']);S::apply(77,22,$base['token']);ok(S::tenantPrice(77,$newRow)===11.0,'global restore clears manual SKU');
 Db::transaction(fn()=>S::manual(77,$p,$sku2,30));Db::name('power_market_sku')->where('id',$sku2)->update(['status'=>0]);
 $hidden=S::preview(77,22,['action'=>'restore','scope'=>'product','ids'=>[$p]]);S::apply(77,22,$hidden['token']);Db::name('power_market_sku')->where('id',$sku2)->update(['status'=>1]);
 ok(S::tenantPrice(77,Db::name('power_market_sku')->where('id',$sku2)->find())===5.0,'restore also clears hidden descendants');
 Db::name('power_market_sku')->where('id',$sku2)->update(['upstream_price'=>6]);ok(S::tenantPrice(78,Db::name('power_market_sku')->where('id',$sku2)->find())===6.0,'unconfigured default follows upstream increase');
 Db::transaction(fn()=>S::manual(77,$p,$sku2,4));
 $catalog=app\common\service\power\MarketCatalogSkuReader::forProducts(77,[$p]);
 ok(count($catalog[$p]['available'])===1,'one invalid SKU does not hide healthy catalogue entries');
 $race=S::preview(77,22,['mode'=>'percent','value'=>25,'replace_descendants'=>true]);$children=[];
 foreach([1,2] as $i){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/race-worker.php',$prefix,dirname(__DIR__,2),$race['token']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,getcwd());foreach($pipes as $pipe)stream_set_timeout($pipe,15);$children[]=[$process,$pipes];}
 foreach($children as [$process,$pipes])ok(trim(fgets($pipes[1]))==='READY','concurrent SQL worker ready');
 foreach($children as [$process,$pipes]){fwrite($pipes[0],"go\n");fclose($pipes[0]);}
 $results=[];foreach($children as [$process,$pipes]){$results[]=trim(stream_get_contents($pipes[1]));$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);ok(proc_close($process)===0,'concurrent worker exited: '.$stderr);}
 ok(count(array_filter($results,fn($r)=>$r==='applied'))===1&&count(array_filter($results,fn($r)=>str_starts_with($r,'rejected:')))===1,'exactly one concurrent apply commits');
 $beforeMigration=[S::rules(0),S::rules(77)];
 $migration=str_replace('la_',$prefix,file_get_contents(dirname(__DIR__,2).'/upgrade/20261022_market_price_rules.sql'));
 foreach([1,2] as $repeat)foreach(explode(';',$migration) as $statement)if(trim($statement)!=='')Db::execute($statement);
 ok($beforeMigration===[S::rules(0),S::rules(77)],'repeated upgrade cannot resurrect restored legacy manual prices');
 Db::name('aigc_action_transfer_config')->insert(['tenant_id'=>77,'price_matrix'=>json_encode(['fast'=>99,'standard'=>0])]);
 $inventory=S::preview(77,22,['mode'=>'percent','value'=>20,'follow_apps'=>true]);
 ok(count($inventory['application_overrides'])===1&&$inventory['application_overrides'][0]['count']===2&&$inventory['application_overrides'][0]['free_count']===1,'preview lists legacy independent and free app prices without exposing configs');
 $p0=S::preview(0,11,['mode'=>'percent','value'=>20]);S::apply(0,11,$p0['token']);
 ok(S::tenantPrice(0,Db::name('power_market_sku')->where('id',$sku)->find())===13.2,'platform operator has no second tenant markup layer');
 $qp=Db::name('power_market_product')->insertGetId(['name'=>'query fixture','product_code'=>'query_fixture','upstream_resource_key'=>'query_fixture','resource_type'=>'app_api','upstream_api_code'=>'query','status'=>1]);
 $qs=Db::name('power_market_sku')->insertGetId(['product_id'=>$qp,'sku_key'=>'query','title'=>'查询','upstream_price'=>0,'sale_points'=>0,'sale_status'=>1,'status'=>1,'usage_unit'=>'per_call','usage_unit_size'=>1]);
 $p0=S::preview(0,11,['mode'=>'add','value'=>2]);S::apply(0,11,$p0['token']);$p77=S::preview(77,22,['mode'=>'add','value'=>3,'replace_descendants'=>true]);S::apply(77,22,$p77['token']);
 ok(S::tenantPrice(77,Db::name('power_market_sku')->where('id',$qs)->find())===0.0,'free lifecycle queries do not acquire per-poll surcharges');
 echo "PASS $n real SQL rule / preview / restore / isolation assertions\n";
} finally {foreach(array_reverse($created) as $table)Db::execute("DROP TABLE IF EXISTS `$table`");}
