<?php
namespace app\common\service\app\aigc_digital_human;

use app\common\service\power\MarketLipsyncAppRuntimeService as Market;

final class DigitalHumanMarketService
{
    public static function channels(int $tenantId): array
    {
        return array_map([self::class, 'channel'], Market::options($tenantId));
    }

    public static function channel(array $option): array
    {
        $spec = ['value'=>'adaptive','ratio'=>'adaptive','quality'=>'source','quality_label'=>'原视频',
            'width'=>0,'height'=>0,'provider_params_json'=>[]];
        return array_merge($option, ['code'=>$option['value'], 'provider'=>'xhadmin',
            'model'=>$option['locked_params']['model'], 'name'=>$option['label'],
            'tenant_unit_price'=>(float)$option['tenant_unit_price'] / max(1,(float)$option['usage_unit_size']),
            'billing_unit'=>'second', 'quantity_options'=>[1], 'max_reference_images'=>1,
            'config_json'=>['lipsync_model'=>$option['locked_params']['model']],
            'specs'=>[$spec], 'qualities'=>[['value'=>'source','label'=>'原视频','ratios'=>[$spec]]]]);
    }

    public static function selection(int $tenantId, array $params): array
    {
        $channels = self::channels($tenantId);
        $code = (string)($params['channel'] ?? ($channels[0]['code'] ?? ''));
        foreach ($channels as $channel) {
            if ($code === $channel['code']) return ['channel'=>$channel,'spec'=>$channel['specs'][0]];
        }
        throw new \Exception('所选对口型市场规格不可用，请重新选择');
    }

    public static function estimate(int $tenantId, array $params): array
    {
        $selected = self::selection($tenantId,$params);
        $duration = max(1,(int)($params['duration'] ?? $params['audio_duration'] ?? 1));
        $quote = Market::quote($tenantId,$selected['channel'],$duration);
        return array_merge($quote,[
            'channel'=>$selected['channel']['code'],'channel_name'=>$selected['channel']['name'],
            'quality'=>'source','quality_label'=>'原视频','ratio'=>'adaptive','width'=>0,'height'=>0,
            'quantity'=>1,'billable_quantity'=>$duration,'duration'=>$duration,
            'platform_unit_cost'=>(float)$quote['tenant_unit_points']/max(1,(float)$quote['billing_unit_size']),
            'tenant_unit_price'=>(float)$quote['user_unit_points']/max(1,(float)$quote['billing_unit_size']),
        ]);
    }
}
