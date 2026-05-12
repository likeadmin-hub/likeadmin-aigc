<?php

namespace app\common\service\app;

use app\common\model\app\AppApi;

class AppPermissionService
{
    public static function resolveAppCodeByUri(string $uri, string $scene): ?string
    {
        $api = AppApi::where('api_path', $uri)
            ->where('scene', $scene)
            ->findOrEmpty();
        return $api->isEmpty() ? null : (string)$api['app_code'];
    }
}

