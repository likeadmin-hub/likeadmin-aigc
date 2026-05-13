<?php

namespace app\common\service\app;

class AppApiRouterService
{
    public static function normalize(string $appCode, string $controller, string $action): string
    {
        AppRegistryService::assertValidCode($appCode);
        return 'app.' . $appCode . '.' . trim($controller, '/') . '/' . trim($action, '/');
    }
}

