<?php
declare(strict_types=1);
namespace app\common\service\power;

/** App-scoped admission for motion control; the generic video catalogue stays unchanged. */
final class MarketMotionSyncRuntimeService
{
    public const APP = 'aigc_motion_sync';
    public const MODELS = ['kling-2.6-motion-control', 'kling-3.0-motion-control'];

    public static function modelKey(array $product): string
    {
        $source = MarketGenerationSchema::array($product['source_payload'] ?? []);
        return (string)($source['market_metadata']['upstream_metadata']['model_code'] ?? '');
    }

    public static function supportsProduct(array $product): bool
    {
        return ($product['resource_type'] ?? '') === PowerMarketService::TYPE_MODEL
            && ($product['model_type'] ?? '') === 'other'
            && ($product['upstream_channel_code'] ?? '') === 'official'
            && in_array(self::modelKey($product), self::MODELS, true);
    }

    public static function options(int $tenantId): array
    {
        $rows = MarketVideoRuntimeService::motionSyncOptions($tenantId);
        foreach ($rows as &$row) {
            $product = \app\common\model\power\PowerMarketProduct::find($row['market_product_id']);
            $row['model_key'] = self::modelKey($product ? $product->toArray() : []);
            $row['orientation_options'] = [['value'=>'video','label'=>'跟随动作视频','max_seconds'=>30], ['value'=>'image','label'=>'保留图片朝向','max_seconds'=>10]];
            $row['min_seconds'] = 3;
            $row['default_quality'] = (string)($row['params_schema']['mode']['default'] ?? '720p');
            $row['tool_material_limits'] = ['image_types'=>['image/jpeg','image/png'],'image_max_mb'=>10,'video_types'=>['video/mp4','video/quicktime'],'video_max_mb'=>100];
            $row['specs'] = array_map(static function(array $spec): array {
                $spec['quality'] = (string)($spec['provider_params_json']['mode'] ?? $spec['quality'] ?? '');
                return $spec;
            }, (array)($row['specs'] ?? []));
        }
        unset($row);
        return $rows;
    }

    public static function quote(int $tenantId, array $selection): array
    {
        return MarketVideoRuntimeService::quote($tenantId, $selection, self::APP);
    }
}
