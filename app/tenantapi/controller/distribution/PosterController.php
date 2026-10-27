<?php
namespace app\tenantapi\controller\distribution;
use app\tenantapi\controller\BaseAdminController;
use app\common\service\distribution\PosterService;
class PosterController extends BaseAdminController
{
    private function run(callable $action) { try { return $this->success('操作成功',$action()); } catch (\Exception $e) { return $this->fail($e->getMessage()); } }
    private function mutation(string $action) { if (!$this->request->isPost()) return $this->fail('请使用 POST 请求'); return $this->run(fn()=>PosterService::mutate($this->tenantId,$action,$this->request->post())); }
    public function lists() { return $this->run(fn()=>PosterService::lists($this->tenantId,$this->request->get())); }
    public function detail() { return $this->run(fn()=>PosterService::detail($this->tenantId,(int)$this->request->get('id',0))); }
    public function saveDraft() { if (!$this->request->isPost()) return $this->fail('请使用 POST 请求'); return $this->run(fn()=>PosterService::save($this->tenantId,$this->request->post())); }
    public function publish() { if (!$this->request->isPost()) return $this->fail('请使用 POST 请求'); return $this->run(fn()=>PosterService::publish($this->tenantId,$this->adminId,$this->request->post())); }
    public function copy() { return $this->mutation('copy'); }
    public function sort() { return $this->mutation('sort'); }
    public function setStatus() { return $this->mutation('setStatus'); }
    public function delete() { return $this->mutation('delete'); }
}
