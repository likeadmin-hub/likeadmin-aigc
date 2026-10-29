<?php
namespace app\api\controller\distribution;
use app\api\controller\BaseApiController;
use app\common\service\distribution\PosterService;
class PosterController extends BaseApiController
{
    private function run(callable $action) { try { return $this->success('获取成功',$action()); } catch (\Exception $e) { return $this->fail($e->getMessage()); } }
    private function tenant(): int { return (int)($this->request->tenantId??0); }
    public function lists() { return $this->run(function(){ PosterService::profile($this->tenant(),$this->userId); return PosterService::lists($this->tenant(),$this->request->get(),true); }); }
    public function detail() { return $this->run(fn()=>['poster'=>PosterService::published($this->tenant(),(int)$this->request->get('id',0)),'profile'=>PosterService::profile($this->tenant(),$this->userId)]); }
    public function assets() { return $this->run(fn()=>PosterService::assets($this->tenant(),$this->userId,$this->request->get())); }
    public function code() { return $this->run(fn()=>PosterService::code($this->tenant(),$this->userId,$this->request->get())); }
    public function validateExport() { if (!$this->request->isPost()) return $this->fail('请使用 POST 请求'); return $this->run(fn()=>PosterService::validateExport($this->tenant(),$this->userId,$this->request->post('', [], null))); }
}
