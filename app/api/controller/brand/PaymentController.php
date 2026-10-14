<?php
declare(strict_types=1);
namespace app\api\controller\brand;
use app\api\controller\BaseApiController;
use app\common\service\brand\BrandChannelGateway;
use app\common\service\brand\BrandPaymentService;
use EasyWeChat\Pay\Message;
use RuntimeException;
use think\facade\Db;
use think\facade\Log;

/** Public callbacks identify the immutable intent, then verify the original merchant signature. */
class PaymentController extends BaseApiController
{
    public array $notNeedLogin=['notifyWechat','notifyAli'];

    public function notifyWechat()
    {
        try {
            $intent=$this->intent(2);
            $gateway=app()->make(BrandChannelGateway::class);
            $application=$gateway->wechat(json_decode($intent['context'],true,512,JSON_THROW_ON_ERROR));
            $gateway->verifyWechatNotification($application);
            $server=$application->getServer();
            $server->handlePaid(function(Message $message)use($gateway,$intent) {
                if ($message['trade_state']==='SUCCESS') BrandPaymentService::recordVerified($gateway->wechatReceipt($message->toArray(),$intent));
                return true;
            });
            return $server->serve();
        } catch (\Throwable $e) {
            Log::error('OEM WeChat notification: '.$e->getMessage());
            return json(['code'=>'FAIL','message'=>'付款通知需要核对'],500);
        }
    }

    public function notifyAli()
    {
        try {
            $intent=$this->intent(3);
            $data=$this->request->post();
            $receipt=(new BrandChannelGateway())->verifyAli($data,$intent);
            if ($receipt['status']==='success') BrandPaymentService::recordVerified($receipt);
            return response('success');
        } catch (\Throwable $e) {
            Log::error('OEM Alipay notification: '.$e->getMessage());
            return response('failure',400);
        }
    }

    private function intent(int $channel): array
    {
        $intent=Db::name('tenant_brand_payment_intent')->where(['id'=>(int)$this->request->get('intent_id',0),'channel'=>$channel])->find();
        if (!$intent) throw new RuntimeException('支付通知对应订单不存在');
        return $intent;
    }
}
