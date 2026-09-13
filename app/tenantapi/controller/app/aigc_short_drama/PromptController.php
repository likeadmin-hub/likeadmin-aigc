<?php

namespace app\tenantapi\controller\app\aigc_short_drama;

use app\common\cache\TenantAdminAuthCache;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use app\common\service\app\aigc_short_drama\ShortDramaPromptWorkspace;
use app\tenantapi\controller\BaseAdminController;
use RuntimeException;

class PromptController extends BaseAdminController
{
    private function respond(bool $write, callable $callback)
    {
        try {
            // Existing installations may not yet have synced the new API declarations.
            // Explicitly require the existing config permission instead of allowing an unknown URI.
            if (empty($this->adminInfo['root'])) {
                $uris = (new TenantAdminAuthCache($this->adminId))->getAdminUri() ?? [];
                $required = 'app.aigc_short_drama.config/' . ($write ? 'setup' : 'detail');
                $normalize = static fn(string $uri): string => strtolower(\think\helper\Str::camel($uri));
                if (!in_array($normalize($required), array_map($normalize, $uris), true)) throw new RuntimeException('无提示词配置权限');
            }
            return $this->success('操作成功', $callback());
        } catch (\Throwable $e) {
            if ($e instanceof \think\db\exception\PDOException) return $this->fail('提示词配置存储暂不可用，请联系管理员检查数据库升级');
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        return $this->respond(false, fn() => ShortDramaPromptWorkspace::detail($this->tenantId, (int)$this->request->get('format_version', 0) === 3));
    }

    public function preview()
    {
        return $this->respond(false, fn() => AigcShortDramaService::previewPromptWorkspace($this->tenantId, $this->request->post()));
    }

    public function save()
    {
        return $this->respond(true, fn() => ShortDramaPromptWorkspace::save($this->tenantId, $this->adminId, $this->request->post()));
    }

    public function history()
    {
        return $this->respond(false, fn() => ShortDramaPromptWorkspace::history($this->tenantId, (int)$this->request->get('before', PHP_INT_MAX)));
    }

    public function version()
    {
        return $this->respond(false, fn() => ShortDramaPromptWorkspace::version($this->tenantId, (int)$this->request->get('revision', -1)));
    }

    public function rollback()
    {
        return $this->respond(true, fn() => ShortDramaPromptWorkspace::rollback($this->tenantId, $this->adminId, $this->request->post()));
    }

    public function restoreApplication()
    {
        return $this->respond(true, fn() => ShortDramaPromptWorkspace::restoreApplication($this->tenantId, $this->adminId, $this->request->post()));
    }

    public function requests()
    {
        return $this->respond(false, fn() => ShortDramaPromptWorkspace::requests($this->tenantId, (int)$this->request->get('before', PHP_INT_MAX)));
    }

    public function requestDetail()
    {
        return $this->respond(false, fn() => ShortDramaPromptWorkspace::request($this->tenantId, (int)$this->request->get('id', 0)));
    }
}
