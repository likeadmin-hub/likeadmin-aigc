<?php
namespace app\api\controller;

use app\common\service\WorkspaceAssetService;
use app\common\service\UploadService;
use app\common\enum\FileEnum;
use InvalidArgumentException;

/** Shared account asset library, independent of any single installed AI app. */
class WorkspaceAssetController extends BaseApiController
{
    public function state() { return $this->respond(fn($t, $u) => WorkspaceAssetService::read($t, $u)); }
    public function uploads() { return $this->respond(fn($t, $u) => WorkspaceAssetService::uploads($t, $u, min(10000, max(1, (int)$this->request->get('page_no', 1))))); }
    public function update() { return $this->respond(fn($t, $u) => WorkspaceAssetService::update($t, $u, $this->request->post()), true); }
    public function label() { return $this->respond(fn($t, $u) => \app\common\service\WorkspaceAssetLabelService::change($t, $u, $this->request->post()), true); }
    public function upload()
    {
        return $this->respond(function ($t, $u) {
            $file = $this->request->file('file');
            if (!$file || $file->getSize() > 100 * 1024 * 1024) throw new InvalidArgumentException('请选择100MB以内的图片或视频');
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
            $ext = strtolower($file->getOriginalExtension());
            if (in_array($ext, ['jpg','jpeg','png','webp','gif'], true) && @getimagesize($file->getPathname())) {
                return UploadService::image(0, $u, FileEnum::SOURCE_USER);
            }
            if (in_array($ext, ['mp4','mov','webm'], true) && str_starts_with($mime, 'video/')) {
                return $ext === 'webm' ? UploadService::file(0, $u, FileEnum::SOURCE_USER) : UploadService::video(0, $u, FileEnum::SOURCE_USER);
            }
            throw new InvalidArgumentException('请选择支持的图片或视频格式');
        }, true);
    }

    private function respond(callable $action, bool $write = false)
    {
        try {
            $tenant = (int)($this->userInfo['tenant_id'] ?? 0);
            if (!$this->userId || $tenant <= 0 || (int)$this->request->tenantId !== $tenant) throw new InvalidArgumentException('账号与租户不匹配，请重新登录');
            if ($write && !$this->request->isPost()) throw new InvalidArgumentException('请使用POST请求');
            return $this->data($action($tenant, $this->userId));
        } catch (\Throwable $e) {
            return $this->fail($e instanceof InvalidArgumentException ? $e->getMessage() : '资产服务暂不可用，请稍后重试');
        }
    }
}
