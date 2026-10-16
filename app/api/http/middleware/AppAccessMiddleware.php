<?php

namespace app\api\http\middleware;

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
        if (in_array($appCode, ['ai_tool_download'], true)) {
            return $next($request);
        }
        if ($appCode) {
            $checkMembership = !$this->isPublicConfigDetail($request, $appCode);
            $response = AppAccessService::assertTenantCanUse((int)$request->tenantId, $appCode, (int)($request->userId ?? 0), $checkMembership);
            if ($response) {
                return $response;
            }
        }
        return $next($request);
    }

    private function isPublicConfigDetail($request, string $appCode): bool
    {
        return in_array($appCode, ['aigc_canvas', 'aigc_image', 'aigc_video'], true)
            && strtolower($request->controller()) === 'app.' . $appCode . '.config'
            && strtolower($request->action()) === 'detail'
            && strtoupper($request->method()) === 'GET';
    }

    private function resolveAppCode(string $controller): string
    {
        $parts = explode('.', strtolower($controller));
        if (($parts[0] ?? '') !== 'app') {
            return '';
        }
        $appCode = preg_replace('/controller$/', '', (string)($parts[1] ?? '')) ?: '';
        if (in_array($appCode, ['ai_tool_download', 'aitooldownload'], true)) {
            return 'ai_tool_download';
        }
        return $appCode;
    }
}
