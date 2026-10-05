<?php
namespace app\api\controller;

use app\common\service\UserToolPinsService;

class ToolPinsController extends BaseApiController
{
    public function detail() { return $this->handle('detail'); }
    public function initializePins() { return $this->handle('initialize'); }
    public function save() { return $this->handle('save'); }

    private function handle(string $action)
    {
        try {
            $tenant = (int)($this->userInfo['tenant_id'] ?? 0);
            if ($this->userId <= 0 || $tenant <= 0 || (int)$this->request->tenantId !== $tenant) throw new \InvalidArgumentException('账号与租户不匹配，请重新登录');
            if ($action === 'detail') return $this->data(UserToolPinsService::read($tenant, $this->userId));
            if (!$this->request->isPost()) return $this->fail('请使用POST请求');
            $p = $this->request->post();
            if ($action === 'initialize') {
                if (!is_array($p['ids'] ?? null) || array_diff(array_keys($p), ['ids', 'tenant_id'])) return $this->fail('置顶工具格式无效');
                return $this->data(UserToolPinsService::initialize($tenant, $this->userId, $p['ids']));
            }
            if (!is_string($p['id'] ?? null) || !in_array($p['pinned'] ?? null, [true, false, 0, 1], true) || array_diff(array_keys($p), ['id', 'pinned', 'tenant_id'])) return $this->fail('置顶工具格式无效');
            return $this->data(UserToolPinsService::set($tenant, $this->userId, $p['id'], (bool)$p['pinned']));
        } catch (\Throwable $e) {
            return $this->fail($e instanceof \InvalidArgumentException ? $e->getMessage() : '置顶工具同步失败，请稍后重试');
        }
    }
}
