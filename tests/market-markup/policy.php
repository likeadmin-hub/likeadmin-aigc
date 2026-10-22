<?php
require dirname(__DIR__,2).'/app/common/service/power/MarketPricePolicy.php';
use app\common\service\power\MarketPricePolicy as P;
$n=0;function eq($a,$b,$label){global $n;if($a!==$b)throw new RuntimeException($label.': '.json_encode([$a,$b]));$n++;}
$percent=[['scope_type'=>'global','scope_id'=>0,'mode'=>'percent','value'=>'20']];
eq(P::resolve(10,10,$percent,1,1)['price'],'12.000000','platform percent');
eq(P::resolve(12,12,[['scope_type'=>'global','scope_id'=>0,'mode'=>'percent','value'=>'25']],1,1)['price'],'15.000000','tenant percent compounds');
eq(P::resolve(10,10,[['scope_type'=>'global','scope_id'=>0,'mode'=>'add','value'=>'2']],1,1)['price'],'12.000000','add per billing unit');
$rules=array_merge($percent,[['scope_type'=>'product','scope_id'=>1,'mode'=>'add','value'=>3],['scope_type'=>'sku','scope_id'=>1,'mode'=>'add','value'=>0]]);
eq(P::resolve(10,10,$rules,1,1)['price'],'10.000000','sku restore overrides product and global');
eq(P::resolve(10,10,$rules,1,2)['price'],'13.000000','product priority');
eq(P::resolve(10,10,$rules,2,3)['price'],'12.000000','global priority');
eq(P::resolve(10,15,[],1,1)['price'],'15.000000','legacy manual preserved');
eq(P::resolve(16,15,[],1,1)['valid'],false,'below purchase invalid');
eq(P::resolve(0.000001,0.000001,$percent,1,1)['price'],'0.000001','six decimal precision');
foreach([-1,INF,NAN,'invalid','1e100'] as $v){$bad=false;try{P::normalize('percent',$v);}catch(InvalidArgumentException $e){$bad=true;}eq($bad,true,'invalid input rejected');}
eq(P::resolve(0,0,$percent,1,1)['price'],'0.000000','free base percentage');
echo "PASS $n market price policy assertions\n";
