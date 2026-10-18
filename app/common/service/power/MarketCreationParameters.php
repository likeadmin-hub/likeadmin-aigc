<?php
namespace app\common\service\power;

use app\common\service\app\aigc_canvas\CanvasGenerationParameters;
use InvalidArgumentException;

/** Reuse the validated model contract for standalone image/video creation. */
final class MarketCreationParameters
{
    public static function describe(array $option, string $type): array
    {
        $option['generation_parameters'] = CanvasGenerationParameters::definitions($option, $type);
        return $option;
    }

    public static function apply(int $tenantId, array $request, string $type): array
    {
        if (!isset($request['advanced_params']) || $request['advanced_params'] === []) return $request;
        $options = MarketGenerationCatalogService::options($tenantId, $type)['channels'];
        return self::applyOptions($request, $options, $type);
    }

    public static function applyOptions(array $request, array $options, string $type): array
    {
        $id = (string)($request['model_id'] ?? $request['channel'] ?? '');
        foreach ($options as $model) {
            if ($id !== (string)($model['value'] ?? $model['id'] ?? $model['code'] ?? '')) continue;
            $skuId = (int)($request['market_sku_id'] ?? $request['sku_id'] ?? 0);
            if ($skuId > 0) {
                $sku = null;
                foreach ($model['skus'] ?? [] as $candidate) {
                    if ((int)($candidate['market_sku_id'] ?? $candidate['sku_id'] ?? 0) === $skuId) $sku = $candidate;
                }
                if ($sku === null) throw new InvalidArgumentException('当前模型不支持所选规格');
                $model['locked_params'] = array_replace_recursive($model['locked_params'] ?? [], $sku['locked_params'] ?? []);
            }
            return CanvasGenerationParameters::apply($request, $model, $type);
        }
        throw new InvalidArgumentException('当前模型不可用，请重新选择');
    }
}
