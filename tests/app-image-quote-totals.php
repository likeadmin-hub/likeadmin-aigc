<?php
// Pure contract regressions. No database or provider requests.
require dirname(__DIR__).'/vendor/autoload.php';
use app\common\service\app\AppSalePriceService as Sale;
use app\common\service\power\MarketImageModelRuntimeService as Native;
$n=0;function imageCheck(bool $v,string $m):void{global $n;if(!$v)throw new RuntimeException($m);$n++;}
$prepared=['unit_price'=>null,'width'=>1024,'height'=>1024,'size_key'=>'1:1','source_images'=>['a','b'],'modules'=>['a','b'],'module_codes'=>['a','b'],'views'=>['a','b'],'view_codes'=>['a','b'],'clothes_images'=>['a','b'],'model_image'=>'m','try_on_mode'=>'individual','options'=>[],'option_codes'=>[],'price_package'=>['code'=>'test','name'=>'test'],'image_payload'=>['quantity'=>2],'mode'=>'single'];
foreach(explode(' ','product_image local_redraw one_click_cleanup product_suite product_multi_angle fashion_lookbook fitting photo_restore model_wear style_transfer background_removal image_translate outpaint') as $app){
 $class='app\\common\\service\\app\\aigc_'.$app.'\\Aigc'.str_replace(' ','',ucwords(str_replace('_',' ',$app))).'Service';
 $method=new ReflectionMethod($class,$app==='fitting'?'buildFittingEstimate':'buildEstimate');$method->setAccessible(true);
 $quantity=in_array($app,['one_click_cleanup','product_suite','product_multi_angle','fashion_lookbook','fitting'],true)?2:1;
 foreach([[null,false,7.765432],[0,false,0],[8.5,false,8.5],[8.5,true,7.765432],[null,true,7.765432]] as [$configured,$follow,$expected]){
  $q=['quantity'=>1,'platform_unit_cost'=>2.0,'tenant_unit_price'=>5.0,'tenant_cost_points'=>3.123456,'user_charge_points'=>7.765432,'settlement_mode'=>'reserved','follow_market_prices'=>$follow];
  $p=$prepared;$p['unit_price']=$configured;$result=$method->invoke(null,$p,$q);
  imageCheck(abs($result['tenant_cost_points']-round(3.123456*$quantity,6))<0.000001,$app.' keeps native reference surcharge and precision');
  imageCheck(abs($result['user_charge_points']-round($expected*$quantity,6))<0.000001,$app.' preserves inherited, custom and free user prices');
  imageCheck(abs($result['platform_unit_cost']-3.123456)<0.000001,$app.' per-call handoff must match native cost');
  $check=new ReflectionMethod(Native::class,'applyBillingOverride');$check->setAccessible(true);
  $native=$check->invoke(null,['quantity'=>1,'tenant_unit_points'=>2,'user_unit_points'=>5,'tenant_cost_points'=>3.123456,'user_charge_points'=>7.765432,'market_snapshot'=>[]],['tenant_cost_points'=>$result['platform_unit_cost'],'user_charge_points'=>$result['tenant_unit_price']]);
  imageCheck(abs($native['tenant_cost_points']-3.123456)<0.000001,$app.' native admission accepts the complete cost');
 }
}
imageCheck(Sale::singleImageCost(['quantity'=>1,'tenant_cost_points'=>0])===0.0,'Explicit free cost');
foreach([['tenant_cost_points'=>null],['tenant_cost_points'=>-1],['tenant_cost_points'=>' '],['tenant_cost_points'=>INF],['quantity'=>2,'tenant_cost_points'=>10],['settlement_mode'=>'actual_usage','tenant_cost_points'=>0]] as $invalid){$rejected=false;try{Sale::singleImageCost($invalid);}catch(InvalidArgumentException){$rejected=true;}imageCheck($rejected,'Unknown totals and non-single recipes must not be fabricated');}
imageCheck(Sale::singleImageSalePrice(0,['platform_unit_cost'=>10])===0.0,'An explicit application free price does not need an inherited sale price');
imageCheck(Sale::singleImageCost(['quantity'=>1,'settlement_mode'=>'actual_usage','tenant_cost_points'=>12.5,'platform_unit_cost'=>2])===12.5,'Known conservative actual-usage quotes remain available');
imageCheck(Sale::singleImageCost(['quantity'=>1,'settlement_mode'=>'actual_usage','tenant_cost_points'=>0,'platform_unit_cost'=>0])===0.0,'Explicitly free actual-usage cost remains free');
echo 'PASS '.$n.' image price, native surcharge, precision and batch handoff checks across 13 tools'.PHP_EOL;
