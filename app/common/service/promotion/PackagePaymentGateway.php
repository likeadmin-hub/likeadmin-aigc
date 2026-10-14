<?php
declare(strict_types=1);
namespace app\common\service\promotion;
use app\common\service\pay\WeChatPayService;
use app\common\service\pay\AliPayService;
/** Only external SDK I/O is injectable. No customer-accessible test switch. */
final class PackagePaymentGateway
{
    private ?\Closure $transport;private array $clients=[];
    public function __construct(?\Closure $transport=null){$this->transport=$transport;}
    public function call(string $action,int $channel,int $terminal,int $user,array $args=[]): array
    {
        if(!in_array($channel,[2,3],true))throw new \RuntimeException('该订单支付方式不支持自动核对');
        if($this->transport)return ($this->transport)($action,$channel,$terminal,$user,$args);
        $key=implode(':',[(int)request()->tenantId,$channel,$terminal,$user]);$sdk=$this->clients[$key]??=($channel===2?new WeChatPayService($terminal,$user):new AliPayService($terminal));
        if($action==='context')return $sdk->packageContext();
        if($action==='prepay'){$result=$sdk->pay($args['from'],$args['order']);if($result===false)throw new \RuntimeException('支付申请暂未完成，请稍后重试或联系管理员核对');return $result;}
        if($action==='close'){$sdk->closePay($args['pay_sn']);return [];}
        if($action!=='query')throw new \InvalidArgumentException('支付核对操作不正确');
        $r=$sdk->checkPay($args['pay_sn']);$context=$sdk->packageContext();
        if($channel===2)return ['state'=>$r['trade_state']??'UNKNOWN','order_sn'=>$r['out_trade_no']??'','transaction'=>$r['transaction_id']??'','amount_cents'=>(int)($r['amount']['total']??-1),'currency'=>$r['amount']['currency']??'','app_id'=>$r['appid']??'','merchant'=>$r['mchid']??''];
        if((string)($r->code??'')!=='10000')throw new \RuntimeException('支付渠道暂未确认订单，请稍后再查询');
        return ['state'=>match((string)($r->tradeStatus??'')){'TRADE_SUCCESS','TRADE_FINISHED'=>'SUCCESS','TRADE_CLOSED'=>'CLOSED','WAIT_BUYER_PAY'=>'NOTPAY',default=>'UNKNOWN'},'order_sn'=>$r->outTradeNo??'','transaction'=>$r->tradeNo??'','amount_cents'=>PackagePromotionPolicy::cents((string)($r->totalAmount??'-1')),'currency'=>'CNY','app_id'=>$context['app_id'],'merchant'=>$context['merchant']];
    }
}
