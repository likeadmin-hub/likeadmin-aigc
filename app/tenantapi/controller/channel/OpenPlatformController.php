<?php

namespace app\tenantapi\controller\channel;

use app\tenantapi\controller\BaseAdminController;
use app\common\service\wechat\OpenPlatformService;

class OpenPlatformController extends BaseAdminController
{
    private function idempotencyKey(): string { return trim((string)$this->request->header('Idempotency-Key', '')); }
    public function status() { return $this->data(OpenPlatformService::configStatus()); }
    public function miniprogramStatus() { return $this->data(OpenPlatformService::tenantMiniprogramStatus($this->tenantId)); }
    public function miniprogramManagement() { try { return $this->data(OpenPlatformService::miniprogramManagement($this->tenantId)); } catch (\Throwable $e) { return $this->fail($e->getMessage()); } }
    public function miniprogramPrivacy() {
        try { return $this->data(OpenPlatformService::miniprogramPrivacy($this->tenantId, (int)$this->request->get('privacy_ver', 2))); }
        catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }
    public function miniprogramPrivacyAuditStatus() {
        try { return $this->data(OpenPlatformService::miniprogramPrivacyAuditStatus($this->tenantId)); }
        catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }
    public function saveMiniprogramPrivacy() {
        try { return $this->data(OpenPlatformService::runIdempotent('miniprogram.privacy.set', $this->idempotencyKey(), $this->tenantId, fn() => OpenPlatformService::setMiniprogramPrivacy($this->tenantId, (array)$this->request->post()))); }
        catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }
    public function uploadMiniprogramPrivacyFile() {
        try {
            $file = $this->request->file('file');
            if (!$file) throw new \InvalidArgumentException('请选择 TXT 文件');
            if (strtolower($file->getOriginalExtension()) !== 'txt' || $file->getSize() < 1 || $file->getSize() > 102400) {
                throw new \InvalidArgumentException('补充文档仅支持不超过100KB的 TXT 文件');
            }
            $content = file_get_contents($file->getPathname());
            if ($content === false) throw new \RuntimeException('读取补充文档失败');
            return $this->data(OpenPlatformService::uploadMiniprogramPrivacyFile($this->tenantId, $file->getOriginalName(), $content));
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }
    public function uploadMiniprogramCategoryImage() {
        try {
            $file = $this->request->file('file');
            if (!$file) throw new \InvalidArgumentException('请选择资质图片');
            if ($file->getSize() < 1 || $file->getSize() > 2 * 1024 * 1024) {
                throw new \InvalidArgumentException('资质图片大小不能超过 2MB');
            }
            $content = file_get_contents($file->getPathname());
            if ($content === false) throw new \RuntimeException('读取资质图片失败');
            return $this->data(OpenPlatformService::uploadMiniprogramCategoryImage($this->tenantId, $file->getOriginalName(), $content));
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }
    public function miniprogramManagementRead() {
        try {
            $operation = (string)$this->request->get('operation', '');
            if (!in_array($operation, ['illegal_records', 'appeal_records', 'testers', 'privacy_interfaces', 'all_categories', 'setting_categories', 'categories_by_type', 'category_names'], true)) throw new \InvalidArgumentException('不支持的查询操作');
            return $this->data(OpenPlatformService::miniprogramManagementApi($this->tenantId, $operation, (array)$this->request->get()));
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }
    public function miniprogramManagementWrite() {
        try {
            $operation = (string)$this->request->post('operation', '');
            if (!in_array($operation, ['bind_tester', 'unbind_tester', 'apply_privacy_interface', 'add_category', 'delete_category', 'modify_category'], true)) throw new \InvalidArgumentException('不支持的设置操作');
            return $this->data(OpenPlatformService::runIdempotent('miniprogram.management.' . $operation, $this->idempotencyKey(), $this->tenantId,
                fn() => OpenPlatformService::miniprogramManagementApi($this->tenantId, $operation, (array)$this->request->post())));
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }
    public function miniprogramEnvironment() { return $this->data(OpenPlatformService::manualUploadEnvironment($this->tenantId)); }
    public function credentials() { return $this->data(OpenPlatformService::credentials($this->tenantId)); }
    public function prepareManualUpload() {
        try { return $this->data(OpenPlatformService::runIdempotent('version.manual_upload', $this->idempotencyKey(), $this->tenantId, fn() => OpenPlatformService::prepareManualUpload($this->tenantId, (array)$this->request->post()))); }
        catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }
    public function saveCredentials() {
        try {
            return $this->data(OpenPlatformService::runIdempotent('credentials.save', $this->idempotencyKey(), $this->tenantId, fn() => OpenPlatformService::saveCredentials($this->tenantId, (array)$this->request->post())));
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }
    public function authUrl() { try { return $this->data(OpenPlatformService::authUrl($this->tenantId, (string)$this->request->param('authorizer_type', ''))); } catch (\Throwable $e) { return $this->fail($e->getMessage()); } }
    public function accounts() { return $this->data(OpenPlatformService::authorizers($this->tenantId)); }
    public function syncAccount() {
        try {
            $id = (int)$this->request->post('id');
            $row = \app\common\model\wechat\WechatAuthorizer::withoutGlobalScope()->where(['id' => $id, 'tenant_id' => $this->tenantId, 'authorization_status' => 1])->findOrEmpty();
            if ($row->isEmpty()) throw new \RuntimeException('授权账号不存在或已失效');
            OpenPlatformService::authorizerInfo($id);
            return $this->data(OpenPlatformService::authorizers($this->tenantId));
        } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }
    public function templates() { return $this->data(OpenPlatformService::availableTemplates()); }
    public function versions() { return $this->data(OpenPlatformService::versions($this->tenantId)); }
    public function reviews() { return $this->data(OpenPlatformService::reviews($this->tenantId)); }
    public function createVersion() { try { return $this->data(OpenPlatformService::runIdempotent('version.create', $this->idempotencyKey(), $this->tenantId, fn() => OpenPlatformService::createVersion($this->tenantId, (array)$this->request->post()))); } catch (\Throwable $e) { return $this->fail($e->getMessage()); } }
    public function experience() { return $this->versionAction('version.experience', fn(int $id) => OpenPlatformService::submitExperience($this->tenantId, $id)); }
    public function submitAudit() { return $this->versionAction('version.audit', fn(int $id) => OpenPlatformService::submitAudit($this->tenantId, $id)); }
    public function queryAudit() { return $this->versionAction('version.audit.query', fn(int $id) => OpenPlatformService::queryAudit($this->tenantId, $id)); }
    public function undoAudit() { return $this->versionAction('version.audit.undo', fn(int $id) => OpenPlatformService::undoAudit($this->tenantId, $id)); }
    public function release() { return $this->versionAction('version.release', fn(int $id) => OpenPlatformService::releaseVersion($this->tenantId, $id)); }
    public function rollback() { return $this->versionAction('version.rollback', fn(int $id) => OpenPlatformService::rollbackVersion($this->tenantId, $id)); }
    public function unbind() { try{$id=(int)$this->request->post('id'); OpenPlatformService::runIdempotent('authorizer.unbind',$this->idempotencyKey(),$this->tenantId,fn()=>OpenPlatformService::unbindAuthorizer($this->tenantId,$id));return $this->success('已解绑');}catch(\Throwable $e){return $this->fail($e->getMessage());} }

    private function versionAction(string $operation, callable $callback)
    {
        try {
            $id = (int)$this->request->post('id');
            return $this->data(OpenPlatformService::runIdempotent($operation, $this->idempotencyKey(), $this->tenantId, fn() => $callback($id)));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }
}
