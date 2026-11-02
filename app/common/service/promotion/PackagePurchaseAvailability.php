<?php
declare(strict_types=1);
namespace app\common\service\promotion;

/** New purchase/prepay only; confirmed payments keep their frozen delivery contract. */
final class PackagePurchaseAvailability
{
    public static function assertOrder(string $from,array $order,bool $lock=false):void
    {
        if(!in_array($from,['membership','recharge'],true))return;
        $package=(int)($order[$from==='membership'?'plan_id':'package_id']??0);
        // Independent custom-amount recharge is not a catalog package.
        if($from==='recharge'&&$package===0)return;
        PackagePricingService::catalog((int)$order['tenant_id'],$from,$package,(string)($order['cycle']??'package'),true,$lock);
    }
}
