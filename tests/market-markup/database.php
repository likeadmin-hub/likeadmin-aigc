<?php
(new think\App())->initialize();
use think\facade\Db;
use think\facade\Config;
use app\common\service\power\MarketPriceRuleService as S;
$n=0;function ok($v,$m){global $n;if(!$v)throw new RuntimeException($m);$n++;}
$c=config('database.connections.mysql');if($c['hostname']!=='127.0.0.1'||$c['database']!=='z_cn')throw new RuntimeException('Local fixture DB only');
$prefix='markup_fixture_'.bin2hex(random_bytes(5)).'_';$created=[];
try {
 foreach(['power_market_product','power_market_sku','tenant_power_market_sku_price'] as $name){Db::execute("CREATE TABLE `$prefix$name` LIKE `la_$name`");$created[]=$prefix.$name;}
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
 echo "PASS $n real SQL rule / preview / restore / isolation assertions\n";
} finally {foreach(array_reverse($created) as $table)Db::execute("DROP TABLE IF EXISTS `$table`");}
