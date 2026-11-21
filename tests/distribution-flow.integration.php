<?php
require __DIR__.'/Support/OemDisposable.php';
use app\common\service\distribution\DistributionService as D;
use app\api\logic\LoginLogic;
use app\common\logic\PayNotifyLogic;
use think\facade\Db;

function check(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException($name);
    echo "PASS $name\n";
}
function registration(int $tenant, string $name, string $code = ''): int {
    request()->tenantId = $tenant;
    if (!LoginLogic::register(['account'=>$name,'password'=>'AuditFixtureABC123','channel'=>4,'invite_code'=>$code])) {
        throw new RuntimeException('Fixture registration failed: '.LoginLogic::getError());
    }
    return (int)Db::name('user')->where(['tenant_id'=>$tenant,'account'=>$name])->value('id');
}
function relation(int $tenant, int $user): array {
    return Db::name('distribution_relation')->where(['tenant_id'=>$tenant,'user_id'=>$user])->find() ?: [];
}
function recharge(int $tenant, int $user, int $package, string $sn): array {
    $id = Db::name('recharge_order')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$user,'sn'=>$sn,'pay_status'=>0,'pay_way'=>2,'order_amount'=>'100.00','recharge_points'=>'300.00','package_id'=>$package,'create_time'=>time()]);
    return [$id, PayNotifyLogic::handle('recharge',$sn,['transaction_id'=>'tx-'.$sn])];
}
function referrers(int $tenant, string $order): array {
    return Db::name('distribution_commission')->where(['tenant_id'=>$tenant,'order_sn'=>$order])->order('level')->select()->toArray();
}
function amount(array $row, string $field): float {return (float)($row[$field] ?? 0);}
function withdrawAccount(int $tenant, int $user): int {
    return (int)D::saveWithdrawAccount($tenant,$user,['type'=>'alipay','real_name'=>'Audit Fixture','contact_mobile'=>'13000000000','alipay_account'=>'audit@example.invalid'])['id'];
}
function membership(int $tenant, int $user, int $plan, string $sn): bool {
    Db::name('membership_order')->insert(['tenant_id'=>$tenant,'user_id'=>$user,'order_sn'=>$sn,'plan_id'=>$plan,'plan_name'=>'Audit membership','duration_months'=>1,'pay_status'=>0,'pay_way'=>2,'order_amount'=>'60.00','create_time'=>time()]);
    return PayNotifyLogic::handle('membership',$sn,['transaction_id'=>'tx-'.$sn]) === true;
}

$tenant=(int)Db::name('tenant')->insertGetId(['sn'=>'dist-audit-a','name'=>'Distribution audit A','create_time'=>time()]);
$other=(int)Db::name('tenant')->insertGetId(['sn'=>'dist-audit-b','name'=>'Distribution audit B','create_time'=>time()]);
$config=['status'=>1,'level_count'=>2,'level1_rate'=>10,'level2_rate'=>5,'settle_days'=>0,'min_withdraw_amount'=>1];
D::saveConfig($tenant,$config); D::saveConfig($other,$config);
$parent=registration($tenant,'distAuditParent');
$code=D::ensurePromoter($tenant,$parent)['invite_code'];
$child=registration($tenant,'distAuditChild',strtolower($code));
$grandchild=registration($tenant,'distAuditGrandchild',relation($tenant,$child)['invite_code']);
check((int)relation($tenant,$child)['level1_user_id']===$parent,'registration binds explicit invite code to the direct referrer');
check(D::userOverview($tenant,$parent)['team']===['level1'=>1,'level2'=>1],'team summary immediately shows one direct and one indirect member');
request()->tenantId=$other;
check(LoginLogic::register(['account'=>'distAuditCross','password'=>'AuditFixtureABC123','channel'=>4,'invite_code'=>$code])===false
    && !Db::name('user')->where(['tenant_id'=>$other,'account'=>'distAuditCross'])->find(),'foreign tenant invite rejects registration without leaving a user');
request()->tenantId=$tenant;
$package=(int)Db::name('recharge_package')->insertGetId(['tenant_id'=>$tenant,'name'=>'Audit pack','amount'=>'100.00','status'=>1,'create_time'=>time()]);
$plan=(int)Db::name('membership_plan')->insertGetId(['tenant_id'=>$tenant,'name'=>'Audit membership','monthly_price'=>'60.00','status'=>1,'create_time'=>time()]);
[$rechargeId,$paid]=recharge($tenant,$grandchild,$package,'audit-grandchild-recharge');
$rows=referrers($tenant,'audit-grandchild-recharge');
check($paid===true && count($rows)===2 && amount($rows[0],'commission_amount')===10.0 && amount($rows[1],'commission_amount')===5.0,'recharge credits two commission levels from cash paid, not 300 points');
check(PayNotifyLogic::handle('recharge','audit-grandchild-recharge',['transaction_id'=>'tx-audit-grandchild-recharge'])===true && count(referrers($tenant,'audit-grandchild-recharge'))===2,'duplicate recharge callback does not add rows');
check(membership($tenant,$child,$plan,'audit-child-membership') && amount(referrers($tenant,'audit-child-membership')[0],'commission_amount')===6.0,'membership payment credits direct commission');
D::savePackageRule($tenant,'recharge',$package,['rule_mode'=>'disabled']);
[,$paid]=recharge($tenant,$child,$package,'audit-disabled-package');
check($paid===true && referrers($tenant,'audit-disabled-package')===[],'disabled recharge SKU grants purchase but no commission');
D::savePackageRule($tenant,'recharge',$package,['rule_mode'=>'inherit']);
D::saveConfig($tenant,array_replace($config,['settle_days'=>1]));
[,$paid]=recharge($tenant,$child,$package,'audit-pending');
check($paid===true && referrers($tenant,'audit-pending')[0]['status']==='pending' && D::settleDue()===0,'pending commission is visible and does not settle before due');
Db::name('distribution_commission')->where('order_sn','audit-pending')->update(['settle_time'=>time()-1]);
check(D::settleDue()===1 && D::settleDue()===0,'due settlement moves the commission once');
D::saveConfig($tenant,$config);
$firstAccount=withdrawAccount($tenant,$parent);
$before=D::account($tenant,$parent);
$withdrawal=D::applyWithdrawal($tenant,$parent,$firstAccount,5);
D::reviewWithdrawal($tenant,(int)$withdrawal['id'],1,'reject','fixture audit');
$after=D::account($tenant,$parent);
check(amount($before,'available_amount')===amount($after,'available_amount'),'withdrawal rejection restores the original available balance');
check(amount($after,'total_income')===amount($before,'total_income'),'rejected withdrawal does not inflate lifetime income');
$withdrawal=D::applyWithdrawal($tenant,$parent,$firstAccount,amount($after,'available_amount'));
D::reviewWithdrawal($tenant,(int)$withdrawal['id'],1,'approve');
D::reviewWithdrawal($tenant,(int)$withdrawal['id'],1,'paid','fixture only',['payment_sn'=>'not-a-real-payment']);
D::reverseRechargeByOrderId($rechargeId);
$before=D::account($tenant,$parent);
check(amount($before,'negative_amount')===5.0,'refund after completed withdrawal records debt');
D::reverseRechargeByOrderId($rechargeId);
check(D::account($tenant,$parent)===$before,'duplicate reversal does not add debt twice');
[$order,$paid]=recharge($tenant,$child,$package,'audit-after-refund-debt');
$paidAccount=D::account($tenant,$parent);
check($paid===true && (int)Db::name('recharge_order')->where('id',$order)->value('pay_status')===1 && amount($paidAccount,'negative_amount')===0.0 && amount($paidAccount,'available_amount')===5.0 && amount($paidAccount,'total_income')===amount($before,'total_income')+10,'new commission repays debt and credits the remainder without rolling back payment');
check(Db::name('distribution_account_log')->where(['tenant_id'=>$tenant,'user_id'=>$parent,'source_sn'=>'recharge:audit-after-refund-debt:1'])->count()===2,'debt offset and balance credit have separate auditable records');
$previousParent=registration($tenant,'distAuditNewParent');
D::adjustRelation($tenant,$child,$previousParent,1,'fixture adjust child');
check((int)relation($tenant,$grandchild)['level2_user_id']===$previousParent,'moving the direct referrer updates the downstream second level');
$root=registration($tenant,'distAuditCycleRoot');
$one=registration($tenant,'distAuditCycleOne',D::ensurePromoter($tenant,$root)['invite_code']);
$two=registration($tenant,'distAuditCycleTwo',relation($tenant,$one)['invite_code']);
$three=registration($tenant,'distAuditCycleThree',relation($tenant,$two)['invite_code']);
try { D::adjustRelation($tenant,$root,$three,1,'fixture create four-node cycle'); } catch (RuntimeException) {}
check((int)(relation($tenant,$root)['level1_user_id'] ?? 0)===0,'admin cannot create a cycle longer than the cached two-level chain');
$membershipAgain=PayNotifyLogic::handle('membership','audit-child-membership',['transaction_id'=>'tx-audit-child-membership']);
$membershipRows=referrers($tenant,'audit-child-membership');
check($membershipAgain===true && count($membershipRows)===1 && (int)$membershipRows[0]['beneficiary_user_id']===$parent,'duplicate membership callback after relation edit keeps the original beneficiary');
$latestBalance=D::account($tenant,$child);
$dashboard=D::dashboard($tenant,$child);
check(amount($latestBalance,'available_amount')===0.0 && amount($dashboard,'commission_total')===0.0,'overview excludes reversed commission amounts');
$unattributed=registration($tenant,'distAuditMissingInvite');
[,$unattributedPaid]=recharge($tenant,$unattributed,$package,'audit-missing-invite-recharge');
check($unattributedPaid===true && (int)(relation($tenant,$unattributed)['level1_user_id'] ?? 0)===0 && referrers($tenant,'audit-missing-invite-recharge')===[],'a successful registration without invite in its request can pay normally with no team member or commission');

// Member withdrawal validation is enforced by the server, independently of the UI.
$second=D::applyWithdrawal($tenant,$parent,$firstAccount,2);
try { D::applyWithdrawal($tenant,$parent,$firstAccount,2); throw new LogicException('Second withdrawal was accepted'); }
catch (RuntimeException $e) {check(str_contains($e->getMessage(),'未处理'),'duplicate withdrawal submission is rejected while the first is open');}
D::reviewWithdrawal($tenant,(int)$second['id'],1,'approve');
try { D::reviewWithdrawal($tenant,(int)$second['id'],1,'paid'); throw new LogicException('Missing payment reference was accepted'); }
catch (RuntimeException $e) {check(str_contains($e->getMessage(),'流水号'),'confirmed payout requires a payment reference');}
D::reviewWithdrawal($tenant,(int)$second['id'],1,'failed','fixture bank refusal');
check(amount(D::account($tenant,$parent),'available_amount')===5.0,'failed payout releases the exact frozen amount');

$shadow=registration($tenant,'distAuditWechatShadow',$code);
$verified=registration($tenant,'distAuditVerified');
Db::transaction(fn()=>D::mergeRegistrationInvite($tenant,$shadow,$verified));
check((int)relation($tenant,$verified)['level1_user_id']===$parent && (int)relation($tenant,$shadow)['level1_user_id']===0,'verified WeChat account merge preserves the referral without counting two members');
$secondShadow=registration($tenant,'distAuditSecondShadow',relation($tenant,$previousParent)['invite_code']);
Db::transaction(fn()=>D::mergeRegistrationInvite($tenant,$secondShadow,$verified));
check((int)relation($tenant,$verified)['level1_user_id']===$parent && (int)relation($tenant,$secondShadow)['level1_user_id']===0,'verified target keeps its established referrer');

$refunded = \app\common\enum\RefundEnum::REFUND_SUCCESS;
$processing = \app\common\enum\RefundEnum::REFUND_ING;
$memberId=(int)Db::name('membership_order')->where('order_sn','audit-child-membership')->value('id');
$recordId=(int)Db::name('refund_record')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$child,'sn'=>'refund-membership-fixture','order_id'=>$memberId,'order_sn'=>'audit-child-membership','order_type'=>'membership','refund_status'=>$processing]);
$logId=(int)Db::name('refund_log')->insertGetId(['tenant_id'=>$tenant,'record_id'=>$recordId,'sn'=>'refund-membership-log','refund_status'=>$processing]);
$refundWorker=new \app\common\command\QueryRefund();
$refundWorker->updateRefundSuccess($logId,$recordId);
$refundWorker->updateRefundSuccess($logId,$recordId);
check((int)Db::name('refund_record')->where('id',$recordId)->value('refund_status')===$refunded && referrers($tenant,'audit-child-membership')[0]['status']==='reversed','confirmed member refund and idempotent reversal commit together');

// Fail the second ledger update to prove an interrupted refund remains retryable.
[$retryId,$paid]=recharge($tenant,$grandchild,$package,'audit-refund-retry');
check($paid===true,'refund-retry fixture recharge completed');
$retryRecord=(int)Db::name('refund_record')->insertGetId(['tenant_id'=>$tenant,'user_id'=>$grandchild,'sn'=>'refund-retry-fixture','order_id'=>$retryId,'order_sn'=>'audit-refund-retry','order_type'=>'recharge','refund_status'=>$processing]);
$retryLog=(int)Db::name('refund_log')->insertGetId(['tenant_id'=>$tenant,'record_id'=>$retryRecord,'sn'=>'refund-retry-log','refund_status'=>$processing]);
Db::execute("CREATE TRIGGER audit_reject_reverse BEFORE INSERT ON la_distribution_account_log FOR EACH ROW BEGIN IF NEW.change_type = 'commission_reverse' AND NEW.user_id = $previousParent THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture reverse fault'; END IF; END");
try {
    try { $refundWorker->updateRefundSuccess($retryLog,$retryRecord); throw new LogicException('Refund completed despite injected ledger failure'); }
    catch (\think\db\exception\PDOException $e) {check(str_contains($e->getMessage(),'fixture reverse fault'),'refund reversal fault reached');}
    check((int)Db::name('refund_record')->where('id',$retryRecord)->value('refund_status')===$processing
        && (int)Db::name('refund_log')->where('id',$retryLog)->value('refund_status')===$processing
        && array_column(referrers($tenant,'audit-refund-retry'),'status')===['available','available'],'failed reversal rolls back the refund status and both commission rows');
} finally {Db::execute('DROP TRIGGER audit_reject_reverse');}
$refundWorker->updateRefundSuccess($retryLog,$retryRecord);
check((int)Db::name('refund_log')->where('id',$retryLog)->value('refund_status')===$refunded
    && array_column(referrers($tenant,'audit-refund-retry'),'status')===['reversed','reversed'],'retry commits refund and both reversals once');
