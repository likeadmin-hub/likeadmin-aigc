<?php

namespace app\common\service\app\aigc_canvas;

use think\Request;

/** Keep the existing trim policy without converting JSON false to an empty string. */
final class CanvasRequestInput
{
    public static function post(Request $request): array
    {
        return $request->post('', [], [static fn($value) => is_string($value) ? trim($value) : $value]);
    }
}
