<?php

namespace app\tenantapi\controller\channel;

use app\tenantapi\controller\BaseAdminController;
use app\common\service\wechat\OpenPlatformService;

class OpenPlatformController extends BaseAdminController
{
    private function idempotencyKey(): string { return trim((string)$this->request->header('Idempotency-Key', '')); }
    public function status() { return $this->data(OpenPlatformService::configStatus()); }
    public function miniprogramStatus() { return $this->data(OpenPlatformService::tenantMiniprogramStatus($this->tenantId)); }
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
    public function templates() { return $this->data(OpenPlatformService::templates()); }
    public function versions() { return $this->data(OpenPlatformService::versions($this->tenantId)); }
    public function reviews() { return $this->data(OpenPlatformService::reviews($this->tenantId)); }
    public function createVersion() { try { return $this->data(OpenPlatformService::runIdempotent('version.create', $this->idempotencyKey(), $this->tenantId, fn() => OpenPlatformService::createVersion($this->tenantId, (array)$this->request->post()))); } catch (\Throwable $e) { return $this->fail($e->getMessage()); } }
    public function experience() { return $this->versionAction('version.experience', fn(int $id) => OpenPlatformService::submitExperience($this->tenantId, $id)); }
    public function submitAudit() { return $this->versionAction('version.audit', fn(int $id) => OpenPlatformService::submitAudit($this->tenantId, $id)); }
    public function queryAudit() { return $this->versionAction('version.audit.query', fn(int $id) => OpenPlatformService::queryAudit($this->tenantId, $id)); }
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
