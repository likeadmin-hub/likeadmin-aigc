<?php

namespace app\api\http\middleware;

use app\common\service\app\AppAccessService;
use Closure;

class AppAccessMiddleware
{
    public function handle($request, Closure $next)
    {
        $appCode = $this->resolveAppCode($request->controller());
        if (in_array($appCode, ['ai_tool_download'], true)) {
            return $next($request);
        }
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
