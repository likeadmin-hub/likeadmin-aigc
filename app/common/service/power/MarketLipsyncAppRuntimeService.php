<?php
namespace app\common\service\power;

/** Video + audio lipsync shares the market reservation/query/settlement lifecycle. */
class MarketLipsyncAppRuntimeService extends MarketPicLipsyncAppRuntimeService
{
    public const APP_CODE = 'aigc_digital_human';
    public const UPSTREAM_APP_CODE = 'lipsync';

    protected static function acceptsSku(array $locked): bool
    {
        return trim((string)($locked['model'] ?? '')) !== '';
    }

    public static function buildPayload(array $snapshot, array $request): array
    {
        $model = trim((string)($snapshot['locked_params']['model'] ?? ''));
        if ($model === '') throw new \InvalidArgumentException('所选市场规格缺少对口型模型');
        $payload = ['model'=>$model, 'mode'=>'async_query'];
        foreach (['video_url', 'audio_url'] as $field) {
            $value = trim((string)($request[$field] ?? ''));
            if (!filter_var($value, FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($value, PHP_URL_SCHEME)), ['http','https'], true)) {
                throw new \InvalidArgumentException('请提供有效的视频形象和驱动音频地址');
            }
            $payload[$field] = $value;
        }
        return $payload;
    }
}
