<?php
declare(strict_types=1);
require __DIR__.'/../app/common/service/promotion/PackagePromotionPolicy.php';
require __DIR__.'/../app/common/service/promotion/PackageCheckoutSummary.php';
use app\common\service\promotion\PackageCheckoutSummary as Summary;
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$order=['plan_name'=>'高级会员','duration_months'=>1,'bonus_points'=>'300.00','order_amount'=>'39.90'];
$snapshot=['regular_cents'=>4990,'payable_cents'=>3990,'is_discount'=>1,'rights'=>json_encode([
    'duration_months'=>1,'bonus_points'=>'300.00','description'=>'适合高频创作用户',
    'features'=>['开通赠送300算力','会员有效期1个月','适合高频图文与视频创作'],
    'app_names'=>['AIGC生图','AIGC视频'],
])];
$summary=Summary::fromOrder('membership',$order,$snapshot);
check($summary['title']==='高级会员' && $summary['duration_months']===1, 'display the purchased membership and duration');
check($summary['bonus_points']==='300.00' && $summary['app_names']===['AIGC生图','AIGC视频'], 'display purchased rights and apps');
check($summary['features'][2]==='适合高频图文与视频创作', 'retain configured benefit copy');
check($summary['regular_price']==='49.90' && $summary['discount_amount']==='10.00', 'discount uses frozen order prices, not market price');
$order['duration_months']=12;$order['bonus_points']='999.00';
$again=Summary::fromOrder('membership',$order,$snapshot);
check($again['duration_months']===1 && $again['bonus_points']==='300.00', 'snapshot rights take precedence over mutable values');
$legacy=Summary::fromOrder('membership',$order,null);
check($legacy['title']==='高级会员' && $legacy['features']===[], 'old orders use recorded values without inventing current plan rights');
$recharge=Summary::fromOrder('recharge',['package_name'=>'创作算力包','recharge_points'=>'600.00','order_amount'=>'19.90'],null);
check($recharge['title']==='创作算力包' && $recharge['recharge_points']==='600.00', 'recharge uses its purchased amount');
check(Summary::fromOrder('brand',[],null)===null, 'unrelated checkout keeps its existing view');
echo "PASS purchased membership/recharge summary, snapshot precedence, discount and legacy fallback\n";
