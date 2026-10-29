<?php

namespace app\common\service\app\aigc_canvas;

use app\common\service\power\MarketGenerationControls;

/** Compatibility entry for existing canvas integrations. */
final class CanvasGenerationParameters
{
    public static function definitions(array $model, string $type): array
    {
        return MarketGenerationControls::definitions($model, $type);
    }

    public static function apply(array $request, array $model, string $type): array
    {
        return MarketGenerationControls::apply($request, $model, $type);
    }
}
