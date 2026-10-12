<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
$quote=new ReflectionMethod(app\common\service\power\MarketTextModelRuntimeService::class,'quote');
$model=['input'=>['tenant_price'=>1.4,'platform_price'=>1.0,'usage_unit_size'=>1000],
        'output'=>['tenant_price'=>2.8,'platform_price'=>2.0,'usage_unit_size'=>1000]];
$actual=$quote->invoke(null,$model,7,9);
if($actual['user_charge_points']!==0.04||$actual['tenant_cost_points']!==0.03)throw new RuntimeException('Final text charge must agree with wallet precision');
if($model['input']['tenant_price']!==1.4)throw new RuntimeException('Unit price precision changed');
echo "PASS native text combined final charge precision\n";
