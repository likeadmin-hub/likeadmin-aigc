<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\power\MarketNanoBananaAppRuntimeService as N;
$bind=new ReflectionMethod(N::class,'applyRetailBinding');
$market=['tenant_price'=>6,'sku'=>['usage_unit'=>'image','usage_unit_size'=>1,'sale_points'=>4,'locked_params'=>[]]];
$bound=$bind->invoke(null,$market,['user_charge_points'=>16,'tenant_cost_points'=>0],2);
if($bound['tenant_price']!==8.||$bound['sku']['sale_points']!==4)throw new RuntimeException('Retail binding changed platform cost or omitted native app price');
$plain=$bind->invoke(null,$market,[],2);if($plain!==$market)throw new RuntimeException('Normal image pricing changed');
$market['sku']['usage_unit']='token';$denied=false;try{$bind->invoke(null,$market,['user_charge_points'=>16],2);}catch(Exception){$denied=true;}if(!$denied)throw new RuntimeException('Fixed retail binding on actual token usage is unsupported');
echo "PASS app retail image binding preserves platform SKU cost and rejects ambiguous actual usage\n";
