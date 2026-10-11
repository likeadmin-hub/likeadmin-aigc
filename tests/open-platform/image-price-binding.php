<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\power\MarketNanoBananaAppRuntimeService as N;
$bind=new ReflectionMethod(N::class,'applyRetailBinding');
$market=['tenant_price'=>6,'sku'=>['usage_unit'=>'image','usage_unit_size'=>1,'sale_points'=>4,'locked_params'=>[]]];
$bound=$bind->invoke(null,$market,['user_charge_points'=>16,'tenant_cost_points'=>8],2);
if($bound['tenant_price']!==8.||$bound['sku']['sale_points']!==4)throw new RuntimeException('Retail binding changed platform cost or omitted native app price');
$plain=$bind->invoke(null,$market,[],2);if($plain!==$market)throw new RuntimeException('Normal image pricing changed');
$market['sku']['usage_unit']='token';$denied=false;try{$bind->invoke(null,$market,['user_charge_points'=>16],2);}catch(Exception){$denied=true;}if(!$denied)throw new RuntimeException('Fixed retail binding on actual token usage is unsupported');
$quote=['quantity'=>2,'tenant_unit_points'=>4.,'user_unit_points'=>6.,'tenant_cost_points'=>8.,'user_charge_points'=>12.,'settlement_mode'=>'reserved'];
$apply=new ReflectionMethod(app\common\service\power\MarketImageModelRuntimeService::class,'applyBillingOverride');$bound=$apply->invoke(null,$quote,['user_charge_points'=>16,'tenant_cost_points'=>8]);if($bound['user_charge_points']!==16.||$bound['tenant_cost_points']!==8.)throw new RuntimeException('Model channel ignored native app retail');
$denied=false;try{$apply->invoke(null,$quote,['tenant_cost_points'=>0]);}catch(Exception){$denied=true;}if(!$denied)throw new RuntimeException('Application lowered platform model cost');
echo "PASS app retail image binding preserves platform SKU cost and rejects ambiguous actual usage\n";
