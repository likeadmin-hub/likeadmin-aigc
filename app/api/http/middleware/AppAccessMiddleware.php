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
        $isDownload = $appCode === 'ai_tool_download';
        if ($isDownload) {
            // Existing single-use file tokens complete prior exports. New ZIPs belong
            // to the source application and obey its current availability switch.
            if (strtolower($request->action()) === 'export') return $next($request);
            $appCode = (string)$request->post('app_code', '');
            if ($appCode === '') return JsonService::fail('请选择应用');
        }
        if ($appCode && !$this->isHistoricalRead($request)) {
            $checkMembership = !$isDownload && !$this->isPublicConfigDetail($request, $appCode);
            $response = AppAccessService::assertTenantCanUse((int)$request->tenantId, $appCode, (int)($request->userId ?? 0), $checkMembership);
            if ($response) {
                return $response;
            }
        }
        return $next($request);
    }

    /** Existing tasks must remain readable while new generation is closed. */
    private function isHistoricalRead($request): bool
    {
        $method = strtoupper($request->method());
        $route = strtolower($request->controller()).'/'.strtolower($request->action());
        // Explicit aliases and compensation for already-created, owner-scoped tasks.
        if ($method === 'POST' && $route === 'app.aigc_video.task/refresh') return true;
        if ($method !== 'GET') return false;
        if (in_array($route, [
            'app.aigc_short_drama.generation/detail', 'app.aigc_short_drama.generation/lists',
            'app.aigc_music.task/query', 'app.aigc_copywriting.task/events',
            'app.aigc_llm.agent/detail', 'app.aigc_llm.agent/events',
            'app.aigc_canvas.conversation/detail', 'app.aigc_canvas.conversation/events',
            'app.aigc_short_drama.canvasagent/events', 'app.aigc_short_drama.canvasagent/messages',
            'app.aigc_short_drama.canvasagent/run',
        ], true)) return true;
        $parts = explode('.', strtolower($request->controller()));
        return in_array(end($parts), ['task', 'result'], true)
            && in_array(strtolower($request->action()), ['lists', 'detail', 'status', 'progress'], true);
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
