<?php

namespace app\tenantapi\http\middleware;

use app\common\service\app\AppAccessService;
use Closure;
use app\common\service\app\aigc_canvas\CanvasLegacyRoutes;
use app\common\service\JsonService;

class AppAccessMiddleware
{
    public function handle($request, Closure $next)
    {
        if (CanvasLegacyRoutes::isRetired($request->controller(), $request->action())) {
            return JsonService::fail('旧版画布 Agent 接口已停用，请刷新后使用新版画布');
        }
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
