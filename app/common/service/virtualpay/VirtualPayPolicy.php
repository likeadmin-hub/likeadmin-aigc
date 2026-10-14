<?php
declare(strict_types=1);
namespace app\common\service\virtualpay;
final class VirtualPayPolicy
{
    public static function environment(int $env): void
    {
        if (!in_array($env, [0,1], true)) throw new \InvalidArgumentException('支付环境不正确');
    }
    public static function client(string $platform, int $env, int $cents): void
    {
        self::environment($env);
        if (!in_array($platform,['ios','android'],true)) throw new \InvalidArgumentException('请在支持虚拟支付的微信小程序中购买');
        if ($cents<=0) throw new \InvalidArgumentException('免费套餐无需虚拟支付');
        if ($platform==='ios' && $env!==0) throw new \RuntimeException('苹果设备仅支持现网虚拟支付');
        if ($platform==='ios' && $cents<100) throw new \RuntimeException('苹果虚拟支付金额不能低于1元');
    }
    public static function sign(array $data,string $key,string $session): array
    {
        if ($key===''||$session==='') throw new \RuntimeException('支付凭据已失效，请重新登录');
        self::environment((int)($data['env']??-1));
        if (($data['currencyType']??'')!=='CNY'||($data['buyQuantity']??0)!==1||(int)($data['goodsPrice']??0)<=0||trim((string)($data['productId']??''))==='') throw new \InvalidArgumentException('虚拟支付商品配置不完整');
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,31}$/D',(string)($data['outTradeNo']??''))) throw new \InvalidArgumentException('渠道订单编号不正确');
        $raw=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return ['mode'=>'short_series_goods','signData'=>$raw,'paySig'=>hash_hmac('sha256','requestVirtualPayment&'.$raw,$key),'signature'=>hash_hmac('sha256',$raw,$session)];
    }
}
