<?php
namespace app\api\controller;

use app\common\service\UserAppearanceService;
use app\common\service\UploadService;
use app\common\enum\FileEnum;

class AppearanceController extends BaseApiController
{
    public function detail() { return $this->handle(false); }
    public function save() { return $this->handle(true); }

    private function identity(): array
    {
        $tenant = (int)($this->userInfo['tenant_id'] ?? 0);
        if ($this->userId <= 0 || $tenant <= 0 || (int)$this->request->tenantId !== $tenant) throw new \InvalidArgumentException('账号与租户不匹配，请重新登录');
        return [$tenant, $this->userId];
    }

    private function handle(bool $write)
    {
        try {
            [$tenant, $user] = $this->identity();
            if (!$write) return $this->data(UserAppearanceService::read($tenant, $user));
            if (!$this->request->isPost()) return $this->fail('请使用POST请求');
            $params = $this->request->post();
            if (!is_array($params['preferences'] ?? null) || !isset($params['revision']) || filter_var($params['revision'], FILTER_VALIDATE_INT) === false) return $this->fail('皮肤配置格式无效');
            return $this->data(UserAppearanceService::save($tenant, $user, (int)$params['revision'], $params['preferences']));
        } catch (\Throwable $e) {
            return $this->fail(($e instanceof \InvalidArgumentException || $e instanceof \RuntimeException) && !str_contains($e->getMessage(), 'SQLSTATE') ? $e->getMessage() : '皮肤保存服务暂不可用，请稍后重试');
        }
    }

    public function upload()
    {
        try {
            $this->identity();
            if (!$this->request->isPost()) return $this->fail('请使用POST请求');
            $file = $this->request->file('file');
            if (!$file || $file->getSize() > 5 * 1024 * 1024) return $this->fail('背景图片不能超过5MB');
            $info = @getimagesize($file->getPathname());
            if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return $this->fail('请选择JPG、PNG或WEBP图片');
            return $this->data(UploadService::image(0, $this->userId, FileEnum::SOURCE_USER, 'uploads/appearance'));
        } catch (\Throwable $e) {
            return $this->fail('背景上传失败，请检查图片后重试');
        }
    }
}
