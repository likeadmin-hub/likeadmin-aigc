<?php
// Real parallel connections need committed disposable rows; always cleanup in finally.
if(!in_array('--allow-committed-fixtures',$argv,true)){fwrite(STDERR,"Use --allow-committed-fixtures on local z_cn only; creates and removes test rows\n");exit(1);}
require __DIR__.'/bootstrap.php';
use think\facade\Db;
use app\common\service\redeem\RedeemCardService as Cards;
$users=[];$batches=[];$plans=[];
function parallelRedeem(array $jobs):array{
 $children=[];
 foreach($jobs as $job){$pipes=[];$p=proc_open([PHP_BINARY,__DIR__.'/child.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Cannot spawn child');fwrite($pipes[0],json_encode($job));fclose($pipes[0]);$children[]=[$p,$pipes];}
 $results=[];foreach($children as [$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);if($exit!==0)throw new RuntimeException('Child failed (no request data logged)');$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}return $results;
}
try{
 $users[]=$a=redeemFixtureUser();$users[]=$b=redeemFixtureUser();
 $make=function($extra=[])use(&$batches){$g=Cards::generate(1,1,array_replace(['name'=>'concurrency fixture','type'=>'points','quantity'=>1,'points'=>'10.00'],$extra));$batches[]=$g['batch']['id'];return $g;};
 $g=$make();$jobs=[['user_id'=>$a,'code'=>$g['codes'][0]['code']],['user_id'=>$b,'code'=>$g['codes'][0]['code']]];$r=parallelRedeem($jobs);$states=array_column($r,'status');sort($states);redeemCheck($states===['success','used'],'same card has exactly one winner');
 redeemCheck(Db::name('redeem_card_record')->where('card_id',$g['codes'][0]['id'])->count()===1,'single record under race');
 $before=(float)Db::name('user')->where('id',$a)->value('user_money');$g1=$make();$g2=$make();$r=parallelRedeem([['user_id'=>$a,'code'=>$g1['codes'][0]['code']],['user_id'=>$a,'code'=>$g2['codes'][0]['code']]]);
 redeemCheck(array_column($r,'status')===['success','success'],'different cards same account serialize without deadlock');redeemCheck((float)Db::name('user')->where('id',$a)->value('user_money')===$before+20.0,'no lost balance update');
 $plans[]=$p1=redeemFixturePlan(1,'concurrent plan A');$plans[]=$p2=redeemFixturePlan(1,'concurrent plan B');$m1=$make(['type'=>'membership','plan_id'=>$p1,'duration_months'=>1]);$m2=$make(['type'=>'membership','plan_id'=>$p2,'duration_months'=>1]);
 $r=parallelRedeem([['user_id'=>$b,'code'=>$m1['codes'][0]['code']],['user_id'=>$b,'code'=>$m2['codes'][0]['code']]]);$states=array_column($r,'status');sort($states);redeemCheck($states===['membership_conflict','success'],'different active plans cannot overwrite under race');
 redeemCheck(Db::name('redeem_card')->whereIn('batch_id',[$m1['batch']['id'],$m2['batch']['id']])->where('status','unused')->count()===1,'conflicting card remains usable');
 redeemCheck(Db::name('user_account_log')->where(['tenant_id'=>1,'user_id'=>$b,'change_type'=>202])->count()===1,'concurrent membership gift awarded once');
 // Same winner retries concurrently after success must return the same original receipt.
 $owner=(int)Db::name('redeem_card')->where('id',$g['codes'][0]['id'])->value('redeemed_user_id');$r=parallelRedeem([['user_id'=>$owner,'code'=>$g['codes'][0]['code']],['user_id'=>$owner,'code'=>$g['codes'][0]['code']]]);
 redeemCheck($r[0]['status']==='success'&&$r[0]['record_id']===$r[1]['record_id'],'concurrent replay returns original receipt');
 echo 'PASS redeem-card concurrency: '.($GLOBALS['redeem_checks']??0)." checks\n";
}finally{
 // Only generated IDs are eligible for removal; existing accounts/rights are untouched.
 if($batches){Db::name('redeem_card_record')->whereIn('batch_id',$batches)->delete();Db::name('redeem_card')->whereIn('batch_id',$batches)->delete();Db::name('redeem_card_batch')->whereIn('id',$batches)->delete();}
 if($users){Db::name('user_account_log')->whereIn('user_id',$users)->delete();Db::name('user_membership')->whereIn('user_id',$users)->delete();Db::name('point_alert_dirty')->whereIn('user_id',$users)->where('tenant_id',1)->delete();Db::name('user')->whereIn('id',$users)->delete();}
 if($plans){Db::name('membership_plan_app')->whereIn('plan_id',$plans)->delete();Db::name('membership_plan')->whereIn('id',$plans)->delete();}
}
