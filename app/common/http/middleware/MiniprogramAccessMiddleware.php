<?php

declare(strict_types=1);
namespace app\common\http\middleware;

use app\common\enum\AdminTerminalEnum;
use app\common\model\wechat\WechatAuthorizer;
use app\common\service\JsonService;
use app\common\service\license\MiniprogramAccessService;

class MiniprogramAccessMiddleware
{
    private MiniprogramAccessService $access;

    public function __construct(?MiniprogramAccessService $access = null)
    {
        $this->access = $access ?? new MiniprogramAccessService();
    }

    protected function accountType($request): string
    {
        return (string)WechatAuthorizer::withoutGlobalScope()->where([
            'id' => (int)$request->param('id'),
            'tenant_id' => (int)($request->adminInfo['tenant_id'] ?? 0),
        ])->value('authorizer_type');
    }

    public function handle($request, \Closure $next)
    {
        $scope = $request->source === AdminTerminalEnum::TENANT ? 'tenant' : 'platform';
        $controller = strtolower(str_replace(['_', '\\'], ['', '.'], $request->controller()));
        $action = strtolower(str_replace('_', '', $request->action()));
        $type = (string)$request->param('authorizer_type', '');
        // Shared account mutations must use the persisted account type, never a client-provided type.
        if ($scope === 'tenant' && $controller === 'channel.openplatform' && in_array($action, ['syncaccount', 'unbind'], true)) {
            $type = $this->accountType($request);
        }
        $required = MiniprogramAccessService::requiresAccess($scope, $controller, $action, $type);
        if ($scope === 'platform' && $controller === 'openplatform' && $action === 'saveconfig') {
            $params = (array)$request->post();
            $required = array_key_exists('developer_app_id', $params) || array_key_exists('upload_private_key', $params);
        }
        if ($required) {
            try { $this->access->assertEnabled(); }
            catch (\Throwable $e) { return JsonService::fail(MiniprogramAccessService::MESSAGE, ['reason_code' => 'MINIPROGRAM_COMMERCIAL_REQUIRED']); }
        }
        return $next($request);
    }
}
