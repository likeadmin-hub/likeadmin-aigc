<?php
use think\facade\Db;
use app\common\service\virtualpay\VirtualPayConfigService as VirtualConfig;
function virtualFixture(): array {
 $t=Db::name('tenant')->insertGetId(['sn'=>'vp-fixture-'.random_int(100000,999999),'name'=>'Virtual fixture','create_time'=>time()]);request()->tenantId=$t;request()->source=app\common\enum\AdminTerminalEnum::PLATFORM;
 foreach(['app_id'=>'wxfixture'.$t,'app_secret'=>'fixture-secret'] as $name=>$value)Db::name('tenant_config')->insert(['tenant_id'=>$t,'type'=>'mnp_setting','name'=>$name,'value'=>$value]);
 $u=Db::name('user')->insertGetId(['tenant_id'=>$t,'sn'=>random_int(100000,999999),'account'=>'vp-fixture-'.$t,'create_time'=>time()-10]);Db::name('user_auth')->insert(['tenant_id'=>$t,'user_id'=>$u,'terminal'=>1,'openid'=>'openid-'.$t,'create_time'=>time()]);
 VirtualConfig::save($t,0,['enabled'=>1,'offer_id'=>'12345','app_key'=>'fixture-private-appkey','token'=>'fixture-token','message_mode'=>'plain']);
 $p=app\common\service\recharge\RechargePackageService::save($t,['name'=>'Virtual points','amount'=>'19.00','points'=>'100']);app\common\service\promotion\PackagePromotionService::save($t,'recharge',$p->id,['enabled'=>1,'rule_new'=>1,'hours'=>48,'discount_prices'=>['package'=>'9.90']]);
 foreach(['regular'=>'19.00','discount'=>'9.90'] as $variant=>$price)VirtualConfig::saveProduct($t,0,['from'=>'recharge','package_id'=>$p->id,'cycle'=>'package','variant'=>$variant,'product_id'=>'product-'.$variant,'price'=>$price]);
 $o=app\common\service\promotion\PackagePricingService::create($t,$u,1,'recharge',$p->id);
 return ['tenant'=>$t,'user'=>$u,'package'=>$p->id,'app'=>'wxfixture'.$t,'openid'=>'openid-'.$t,'order'=>$o];
}
function virtualQuery(array $intent,int $status=2,bool $apple=false): array {
 return ['errcode'=>0,'order'=>['order_id'=>$intent['out_trade_no'],'env_type'=>(int)$intent['env']+1,'status'=>$status,'order_fee'=>(int)$intent['amount_cents'],'paid_fee'=>in_array($status,[2,3,4,5,8],true)?(int)$intent['amount_cents']:0,'order_type'=>$apple?7:0,'wx_order_id'=>'wx-internal-'.$intent['out_trade_no'],'wxpay_order_id'=>$apple?'':'wx-pay-'.$intent['out_trade_no'],'left_fee'=>(int)$intent['amount_cents'],'biz_meta'=>$intent['attach']]];
}
function virtualNotice(array $intent): array {
 return ['Event'=>'xpay_goods_deliver_notify','OpenId'=>$intent['openid'],'OutTradeNo'=>$intent['out_trade_no'],'Env'=>(int)$intent['env'],'GoodsInfo'=>['ProductId'=>$intent['product_id'],'Quantity'=>1,'OrigPrice'=>(int)$intent['amount_cents'],'ActualPrice'=>(int)$intent['amount_cents'],'Attach'=>$intent['attach']]];
}
