<?php
/** External gateway emulator for the disposable HTTP server, never loaded by a production entry point. */
class OemAcceptanceGateway extends \app\common\service\brand\BrandChannelGateway
{
    public static function ledger(callable $callback): mixed {
        $dir=root_path().'runtime/oem-gateway'; if(!is_dir($dir)) mkdir($dir,0700,true);
        $file=fopen($dir.'/ledger.json','c+'); if(!$file || !flock($file,LOCK_EX)) throw new RuntimeException('Fixture ledger unavailable');
        try { $data=json_decode(stream_get_contents($file),true)?:['intents'=>[],'paid'=>[],'refunds'=>[]]; $result=$callback($data); rewind($file); ftruncate($file,0); fwrite($file,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); fflush($file); return $result; }
        finally { flock($file,LOCK_UN); fclose($file); }
    }
    public function prepay(array $intent,string $redirect): array {
        $this->assertOriginal(json_decode($intent['context'],true));
        self::ledger(function(&$data)use($intent) { $data['intents'][$intent['pay_sn']]=$intent; });
        $url=request()->domain().'/__gateway/pay?sn='.rawurlencode($intent['pay_sn']);
        return ['pay_way'=>(int)$intent['channel'],'config'=>(int)$intent['channel']===2?'weixin://wxpay/bizpayurl?pr=oem-disposable-'.$intent['id']:'<form method="get" action="'.htmlspecialchars($url,ENT_QUOTES).'" id="mock-alipay"><input name="sn" value="'.htmlspecialchars($intent['pay_sn'],ENT_QUOTES).'"></form><script>document.forms[0].submit();</script>'];
    }
    public function query(array $intent): ?array {
        $context=json_decode($intent['context'],true); $this->assertOriginal($context);
        $paid=self::ledger(fn(&$data)=>$data['paid'][$intent['pay_sn']]??null); if(!$paid) return null;
        return ['pay_sn'=>$intent['pay_sn'],'channel'=>(int)$intent['channel'],'merchant_id'=>$context['merchant_id'],'app_id'=>$context['app_id'],
            'transaction_id'=>$paid['transaction_id'],'amount_cents'=>(int)$intent['amount_cents'],'currency'=>'CNY','status'=>'success','paid_time'=>$paid['paid_time']];
    }
    public function refund(array $payment,array $refund): array {
        $this->assertOriginal(json_decode($payment['context'],true));
        self::ledger(function(&$data)use($payment,$refund) { if(!isset($data['refunds'][$refund['refund_sn']])) $data['refunds'][$refund['refund_sn']]=['payment'=>$payment,'refund'=>$refund,'status'=>'pending']; }); return ['status'=>'pending'];
    }
    public function queryRefund(array $payment,array $refund): ?array {
        $this->assertOriginal(json_decode($payment['context'],true));
        return self::ledger(function(&$data)use($payment,$refund) {
            $record=$data['refunds'][$refund['refund_sn']]??null; if(!$record) return null;
            return ['refund_sn'=>$refund['refund_sn'],'transaction_id'=>$payment['transaction_id'],'merchant_id'=>$payment['merchant_id'],'amount_cents'=>(int)$refund['amount_cents'],
                'currency'=>'CNY','channel_refund_id'=>'mock-'.$refund['refund_sn'],'status'=>$record['status']];
        });
    }
}
