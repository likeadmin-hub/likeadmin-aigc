<?php
declare(strict_types=1);
namespace app\common\service\virtualpay;
use think\facade\Db;
use EasyWeChat\Kernel\Encryptor;
final class VirtualPayNotifyService
{
    private VirtualPayService $pay;
    public function __construct(?VirtualPayService $pay=null){$this->pay=$pay??new VirtualPayService();}
    public static function parse(string $raw): array
    {
        if(strlen($raw)>262144)throw new \RuntimeException('回调报文过大');
        if(str_starts_with(ltrim($raw),'{')){ $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($data))throw new \RuntimeException('回调报文格式不正确');return $data; }
        if(str_contains(strtoupper($raw),'<!DOCTYPE')||str_contains(strtoupper($raw),'<!ENTITY'))throw new \RuntimeException('回调报文包含不支持的内容');
        $xml=@simplexml_load_string($raw,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);if(!$xml)throw new \RuntimeException('回调报文格式不正确');return json_decode(json_encode($xml,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR);
    }
    private static function verify(string $token,array $params,string $encrypted=''): void
    {
        $timestamp=(string)($params['timestamp']??'');$nonce=(string)($params['nonce']??'');$sig=(string)($params[$encrypted!==''?'msg_signature':'signature']??'');
        if($nonce===''||strlen($nonce)>128||!ctype_digit($timestamp)||abs(time()-(int)$timestamp)>300)throw new \RuntimeException('回调时间戳或校验参数已过期');
        $parts=[$token,$timestamp,$nonce];if($encrypted!=='')$parts[]=$encrypted;sort($parts,SORT_STRING);
        if($sig===''||!hash_equals(sha1(implode('',$parts)),$sig))throw new \RuntimeException('回调签名校验失败');
    }
    public function manual(int $tenant,string $app,string $raw,array $params,bool $get): string
    {
        $versions=Db::name('virtual_pay_config_version')->where(['tenant_id'=>$tenant,'app_id'=>$app,'authorizer_id'=>0])->order('id desc')->select()->toArray();
        if(!$versions)throw new \RuntimeException('回调配置不存在');
        $envelope=$get?[]:self::parse($raw);$encrypted=(string)($envelope['Encrypt']??$envelope['encrypt']??'');$message=null;$last=null;$seen=[];
        if (!$get && $encrypted==='' && $versions[0]['message_mode']==='safe') throw new \RuntimeException('当前回调要求加密报文');
        foreach($versions as $v){
            try{
                $token=VirtualPayConfigService::secret($v,'token');$key=VirtualPayConfigService::secret($v,'aes_key');$fingerprint=hash('sha256',$token.'|'.$key.'|'.$v['message_mode']);if(isset($seen[$fingerprint]))continue;$seen[$fingerprint]=true;
                self::verify($token,$params,$encrypted);
                if($get)return (string)($params['echostr']??'');
                if($encrypted==='') { if($v['message_mode']==='safe')throw new \RuntimeException('当前回调要求加密报文');$message=$envelope; }
                else { $plain=(new Encryptor($app,$token,$key,$app))->decrypt($encrypted,(string)$params['msg_signature'],(string)$params['nonce'],(string)$params['timestamp']);$message=self::parse($plain); }
                break;
            }catch(\Throwable $e){$last=$e;}
        }
        if(!$message)throw new \RuntimeException('回调签名或加密身份校验失败');
        $result=$this->event($tenant,$app,$message);
        if(str_starts_with(ltrim($raw),'{'))return json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $xml='<xml>';foreach($result as $k=>$value)$xml.='<'.$k.'>'.htmlspecialchars((string)$value,ENT_XML1|ENT_QUOTES,'UTF-8').'</'.$k.'>';return $xml.'</xml>';
    }
    /** Receives only a signature/decryption-verified message from one of the two callback dispatchers. */
    public static function normalized(array $message): array
    {
        foreach (['WxaVirtualPayIosRefundQueryNotifyEvent','IosRefundQuery'] as $field) {
            if (isset($message[$field]) && is_array($message[$field])) $message=$message[$field]+$message;
        }
        return $message;
    }
    public static function reference(array $message): string
    {
        $message=self::normalized($message);return (string)($message['OutTradeNo']??$message['MchOrderId']??$message['pay_order_id']??'');
    }
    public function event(int $tenant,string $app,array $message): array
    {
        $message=self::normalized($message);
        $event=(string)($message['Event']??'');if(!str_starts_with($event,'xpay_'))throw new \RuntimeException('不支持的虚拟支付事件');
        $sn=self::reference($message);
        $i=$sn!==''?Db::name('virtual_pay_intent')->where(['tenant_id'=>$tenant,'app_id'=>$app])->where(function($q)use($sn){$q->where('out_trade_no',$sn)->whereOr('wx_order_id',$sn);})->find():null;
        $raw=json_encode($message,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$key=hash('sha256',$event.'|'.$raw);
        $scope=['tenant_id'=>$tenant,'app_id'=>$app,'event_key'=>$key];Db::name('virtual_pay_event')->extra('IGNORE')->insert($scope+['intent_id'=>(int)($i['id']??0),'event'=>$event,'payload'=>$raw,'created_at'=>time(),'updated_at'=>time()]);
        try{
            if($event==='xpay_goods_deliver_notify'){
                if(!$i||(string)($message['OpenId']??'')!==$i['openid']||VirtualPayPolicy::integer($message['Env']??null)!==(int)$i['env'])throw new \RuntimeException('支付通知的用户或环境不匹配');
                if (isset($message['CurrencyType']) && $message['CurrencyType']!=='CNY') throw new \RuntimeException('支付通知币种不匹配');
                $g=$message['GoodsInfo']??[];
                if((string)($g['ProductId']??'')!==$i['product_id']||VirtualPayPolicy::integer($g['Quantity']??null)!==1||VirtualPayPolicy::integer($g['OrigPrice']??null)!==(int)$i['amount_cents']||VirtualPayPolicy::integer($g['ActualPrice']??null)!==(int)$i['amount_cents']||(string)($g['Attach']??'')!==$i['attach'])throw new \RuntimeException('支付通知的商品或金额不匹配');
                $expectedTransaction=(string)($message['WeChatPayInfo']['TransactionId']??'');
                if (isset($message['WeChatPayInfo']) && $expectedTransaction === '') throw new \RuntimeException('微信交易信息不完整');
                $q=$this->pay->reconcile($i,$expectedTransaction);if(!in_array((int)$q['status'],[2,3,4],true))throw new \RuntimeException('渠道付款状态尚未确认');
                $i=Db::name('virtual_pay_intent')->where('id',$i['id'])->find();if($i['delivery_status']!=='granted')throw new \RuntimeException('交付尚未完成，稍后重试发货通知');
                Db::name('virtual_pay_intent')->where('id',$i['id'])->update(['xpay_shipping_status'=>(int)$q['status']===4?'confirmed':'acknowledged','updated_at'=>time()]);$result=['ErrCode'=>0,'ErrMsg'=>''];
            }elseif(in_array($event,['xpay_refund_notify','xpay_subscribe_ios_refund_query_notify'],true)){
                $result=(new VirtualPayRefundService($this->pay))->event($tenant,$app,$message);
            }else{
                // Complaints/risk events are accepted and visible to operators, without changing funds or rights.
                Db::name('virtual_pay_event')->where($scope)->update(['status'=>'attention','message'=>'请在微信平台核对投诉或风控事项','updated_at'=>time()]);return ['ErrCode'=>0,'ErrMsg'=>''];
            }
            Db::name('virtual_pay_event')->where($scope)->update(['status'=>'done','message'=>'','updated_at'=>time()]);return $result;
        }catch(\Throwable $e){Db::name('virtual_pay_event')->where($scope)->update(['status'=>'failed','message'=>mb_substr($e->getMessage(),0,500),'updated_at'=>time()]);throw $e;}
    }
}
