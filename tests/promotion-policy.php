<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/common/service/promotion/PackagePromotionPolicy.php';
use app\common\service\promotion\PackagePromotionPolicy as P;
function check(bool $ok,string $name):void {if(!$ok)throw new RuntimeException($name);}
function reject(callable $fn,string $name):void {try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException($name);}
check(P::cents('9.90')===990&&P::amount(1900)==='19.00','integer amount');
foreach(['1.001','-1','1e2','',null,[],true] as $v)reject(fn()=>P::cents($v),'invalid money');
$promo=['enabled'=>1,'rule_new'=>1,'rule_all'=>1,'hours'=>48,'discount_cents'=>990,'all_start'=>1000,'all_end'=>2000];
$q=P::quote($promo,1900,1500,1900,'');check($q['payable_cents']===990&&$q['eligible_end_at']===1500+48*3600&&count($q['matched_rules'])===2,'OR windows use last active expiry');
check(P::quote($promo,1900,1,2000,'')['payable_cents']===990,'global expired but new qualifies');
check(P::quote($promo,1900,1,1+48*3600,'')['payable_cents']===1900,'exact end excludes');
check(P::quote($promo,1900,0,2000,'')['payable_cents']===1900,'unknown registration never new');
check(P::quote($promo,1900,3000,2000,'')['payable_cents']===1900,'future registration rejected');
foreach(['used','reserved'] as $status)check(P::quote($promo,1900,1500,1900,$status)['payable_cents']===1900,'one-use claim overrides OR');
check(P::quote(array_replace($promo,['enabled'=>0]),1900,1500,1900,'')['payable_cents']===1900,'default disabled');
reject(fn()=>P::validate(1900,1900,48,1,1),'equal price');reject(fn()=>P::validate(1900,0,48,1,0),'zero price');reject(fn()=>P::validate(1900,990,0,1,0),'invalid duration');reject(fn()=>P::validate(1900,990,48,0,0),'no rules');
$window=P::window(['all_start'=>1000,'all_end'=>2000],1500,48,false);check($window===['all_start'=>1000,'all_end'=>2000],'ordinary save does not restart');
check(P::window([],1000,48,false)['all_end']===1000+48*3600,'first enable');check(P::window($window,1600,48,true)['all_start']===1600,'explicit restart');
echo "PASS promotion cents, OR/expiry, once-only eligibility and activity lifecycle\n";
