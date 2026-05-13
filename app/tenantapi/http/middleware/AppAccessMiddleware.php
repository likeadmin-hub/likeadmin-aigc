<?php

namespace app\tenantapi\http\middleware;

use app\common\service\app\AppAccessService;
use Closure;

class AppAccessMiddleware
{
    public function handle($request, Closure $next)
    {
        $appCode = $this->resolveAppCode($request->controller());
        if ($appCode) {
            $response = AppAccessService::assertTenantCanManage((int)$request->tenantId, $appCode);
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
