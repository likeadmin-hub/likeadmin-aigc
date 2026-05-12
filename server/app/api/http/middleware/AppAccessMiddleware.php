<?php

namespace app\api\http\middleware;

use app\common\service\app\AppAccessService;
use Closure;

class AppAccessMiddleware
{
    public function handle($request, Closure $next)
    {
        $appCode = $this->resolveAppCode($request->controller());
        if ($appCode) {
            $response = AppAccessService::assertTenantCanUse((int)$request->tenantId, $appCode, (int)($request->userId ?? 0));
            if ($response) {
                return $response;
            }
        }
        return $next($request);
    }

    private function resolveAppCode(string $controller): string
    {
        $parts = explode('.', strtolower($controller));
        return ($parts[0] ?? '') === 'app' ? (string)($parts[1] ?? '') : '';
    }
}
