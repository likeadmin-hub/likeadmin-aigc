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
        if ($appCode === 'aigc_copywriting' && strtoupper($request->method()) === 'GET' && strtolower($request->controller()).'/'.strtolower($request->action()) === 'app.aigc_copywriting.config/preview') return true;
        if ($appCode === 'aigc_micro_video' && strtoupper($request->method()) === 'GET'
            && in_array(strtolower($request->controller()).'/'.strtolower($request->action()), ['app.aigc_micro_video.config/detail', 'app.aigc_micro_video.direction/lists'], true)) return true;
        return strtolower($request->controller()) === 'app.' . $appCode . '.config'
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
