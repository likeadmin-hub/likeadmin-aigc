<?php
// Explicit local-only fixture bootstrap. No migration is performed here.
$root=dirname(__DIR__,2);
$GLOBALS['AIGC_RUNTIME_GENERATION_OVERRIDE']=$root;
require $root.'/runtime-loader.php';(new AigcRuntimeApp($root))->initialize();
$c=config('database.connections.mysql');
if(($c['hostname']??'')!=='127.0.0.1'||($c['database']??'')!=='z_cn')throw new RuntimeException('Only the local z_cn development database is allowed');
function redeemCheck(bool $condition,string $message):void { if(!$condition)throw new RuntimeException($message); $GLOBALS['redeem_checks']=($GLOBALS['redeem_checks']??0)+1; }
function redeemReject(callable $action,string $code):void {
    try{$action();}catch(\app\common\service\redeem\RedeemCardException $e){redeemCheck($e->errorCode===$code,'Expected '.$code.', got '.$e->errorCode);return;}
    throw new RuntimeException('Expected failure '.$code);
}
function redeemFixtureUser(int $tenant=1):int {
    return (int)\think\facade\Db::name('user')->insertGetId(['tenant_id'=>$tenant,'sn'=>random_int(10000000,99999999),'nickname'=>'redeem rollback fixture','account'=>'rc_'.bin2hex(random_bytes(6)),'user_money'=>'0.00','create_time'=>time(),'update_time'=>time(),'delete_time'=>null]);
}
function redeemFixturePlan(int $tenant=1,string $name='卡密测试套餐'):int {
    return (int)\think\facade\Db::name('membership_plan')->insertGetId(['tenant_id'=>$tenant,'name'=>$name,'duration_months'=>1,'monthly_price'=>'10.00','yearly_price'=>'100.00','monthly_bonus_points'=>'5.00','features'=>'["fixture"]','status'=>1,'create_time'=>time(),'update_time'=>time(),'delete_time'=>null]);
}
