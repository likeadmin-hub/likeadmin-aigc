<?php
namespace app\platformapi\controller\app\aigc_copywriting;
use app\common\service\app\aigc_copywriting\CopywritingRecovery;
class TaskController extends \app\platformapi\controller\BaseAdminController
{
    private function rootOnly(): void {if((int)($this->adminInfo['root']??0)!==1)throw new \RuntimeException('仅平台超级管理员可核对文案回执');}
    public function recheck(){try{$this->rootOnly();return $this->success('核对完成',CopywritingRecovery::reconcile((int)$this->request->post('tenant_id'),(int)$this->request->post('id')));}catch(\Throwable $e){return $this->fail($e->getMessage());}}
    public function review(){try{$this->rootOnly();return $this->success('已记录核对说明',CopywritingRecovery::review($this->adminId,(int)$this->request->post('tenant_id'),(int)$this->request->post('id'),(string)$this->request->post('note','')));}catch(\Throwable $e){return $this->fail($e->getMessage());}}
}
