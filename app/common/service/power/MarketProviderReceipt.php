<?php
namespace app\common\service\power;
use app\common\model\ai\AiConsumptionEvent;
use app\common\service\open_platform\OpenVault;

/** Retain operator diagnostics without exposing raw upstream responses to users. */
final class MarketProviderReceipt
{
    public static function record(int $consumptionId,int $httpStatus,array $response):void
    {
        if($consumptionId<=0)return;
        try{
            $sealed=OpenVault::seal(json_encode(['consumption_id'=>$consumptionId,'response'=>$response],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            AiConsumptionEvent::create(['consumption_id'=>$consumptionId,'event_type'=>'provider_rejection','event_status'=>'received','attempt_no'=>1,
                'payload_summary'=>['response_sha256'=>hash('sha256',json_encode($response,JSON_THROW_ON_ERROR))],
                'payload_ciphertext'=>$sealed,'http_status'=>$httpStatus,'elapsed_ms'=>0,'create_time'=>time()]);
        }catch(\Throwable){/* Diagnostics must never trigger a second paid submission. */}
    }
}
