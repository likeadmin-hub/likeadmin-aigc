<?php

namespace app\platformapi\controller;

use app\common\service\wechat\OpenPlatformService;

class OpenPlatformController extends BaseAdminController
{
    private function idempotencyKey(): string { return trim((string)$this->request->header('Idempotency-Key', '')); }
    public function config() { return $this->data(OpenPlatformService::config()); }
    public function saveConfig() { try { $p=(array)$this->request->post(); return $this->data(OpenPlatformService::runIdempotent('config.save',$this->idempotencyKey(),0,fn()=>OpenPlatformService::saveConfig($p))); } catch (\Throwable $e) { return $this->fail($e->getMessage()); } }
    public function startTicket() { try { return $this->data(OpenPlatformService::runIdempotent('ticket.start',$this->idempotencyKey(),0,fn()=>OpenPlatformService::startTicket())); } catch (\Throwable $e) { return $this->fail($e->getMessage()); } }
    public function authUrl()
    {
        try { return $this->data(OpenPlatformService::authUrl()); } catch (\Throwable $e) { return $this->fail($e->getMessage()); }
    }
    public function authorizers() { return $this->data(OpenPlatformService::authorizers()); }
    public function syncAuthorizers() { try { return $this->success('授权账号已同步', ['items'=>OpenPlatformService::syncAuthorizers()]); } catch (\Throwable $e) { return $this->fail($e->getMessage()); } }
    public function artifacts() { return $this->data(OpenPlatformService::artifacts()); }
    public function registerArtifact() { $p=(array)$this->request->post(); try{return $this->data(OpenPlatformService::runIdempotent('artifact.register',$this->idempotencyKey(),0,fn()=>OpenPlatformService::registerArtifact((string)($p['version']??''),(string)($p['source_sha']??''))));}catch(\Throwable $e){return $this->fail($e->getMessage());} }
    public function promoteArtifact() { try { $id=(int)$this->request->param('id'); OpenPlatformService::runIdempotent('artifact.promote',$this->idempotencyKey(),0,fn()=>OpenPlatformService::promote($id)); return $this->success('正式产物已提升'); } catch(\Throwable $e){ return $this->fail($e->getMessage()); } }
    public function templates() { return $this->data(OpenPlatformService::templates()); }
    public function uploadTemplate() { $p = (array)$this->request->post(); try { return $this->data(OpenPlatformService::runIdempotent('template.add',$this->idempotencyKey(),0,fn()=>OpenPlatformService::uploadTemplateForArtifact((int)($p['artifact_id'] ?? 0),(int)($p['draft_id'] ?? 0),(string)($p['description'] ?? '')))); } catch (\Throwable $e) { return $this->fail($e->getMessage()); } }
    public function versions() { return $this->data(OpenPlatformService::versions(0)); }
    public function logs() { return $this->data(OpenPlatformService::apiLogs()); }
}
