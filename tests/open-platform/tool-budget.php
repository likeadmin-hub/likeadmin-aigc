<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\open_platform\OpenToolAdapter as A;
use app\common\service\open_platform\OpenException;
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
check(A::estimateBudget(['user_charge_points'=>0,'tenant_cost_points'=>0])===null,'Incomplete zero estimate became an unrestricted budget');
check(A::estimateBudget(['user_charge_points'=>10])===null,'Missing tenant cost became a budget');
$free=A::estimateBudget(['user_charge_points'=>'0.00','tenant_cost_points'=>'30.00']);check($free['user']===0.&&$free['tenant']===30.,'Existing free retail qualification changed tenant cost');
$paid=A::estimateBudget(['display_points'=>'9.00','cost_points'=>'4.00']);check($paid['user']===9.&&$paid['tenant']===4.,'Native retail quote changed');
foreach([-1,INF,NAN,[],true,1000000000000.] as $amount){$blocked=false;try{A::estimateBudget(['user_charge_points'=>$amount,'tenant_cost_points'=>1]);}catch(OpenException $e){$blocked=$e->errorType==='pricing_unavailable';}check($blocked,'Unreliable estimate amount accepted');}
echo "PASS native free retail keeps tenant cost; zero probes and invalid amounts cannot disable spending bounds\n";
