<?php
namespace app\api\controller\app\aigc_micro_video;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchRecords as R;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbench as W;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchJobs as J;
use app\common\service\app\aigc_micro_video\MicroVideoWorkbenchPolicy as P;
use app\common\service\app\aigc_micro_video\MicroVideoService;
class WorkbenchController extends BaseApiController
{
    public function lists()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->get();if(($p['kind']??'')==='template')W::seed($tenant);return $this->success("操作成功",R::lists($tenant,$user,$p,$admin));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function detail()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->get();return $this->success("操作成功",R::dto(R::owned($tenant,$user,(string)($p['kind']??'project'),(int)($p['id']??0),$admin)));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function save()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->post();return $this->success("操作成功",R::save($tenant,$user,$p,$admin));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function delete()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->post();return $this->success("操作成功",R::delete($tenant,$user,$p,$admin));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function copy()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->post();return $this->success("操作成功",R::copy($tenant,$user,$p,$admin));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function versions()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->get();return $this->success("操作成功",R::versions($tenant,$user,$p,$admin));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function restore()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->post();return $this->success("操作成功",R::restore($tenant,$user,$p,$admin));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function categories()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->get();return $this->success("操作成功",R::categories());}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function capabilities()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->get();return $this->success("操作成功",W::capabilities($tenant));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function jobs()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->get();return $this->success("操作成功",J::lists($tenant,$user,$p,$admin));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function jobDetail()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->get();return $this->success("操作成功",J::dto(J::owned($tenant,$user,(int)($p['id']??0),$admin),$admin));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function reconcile()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->post();return $this->success("操作成功",J::reconcile($tenant,$user,(int)($p['id']??0),$admin));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function estimate()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->post();return $this->success("操作成功",J::estimate($tenant,$user,$p));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function job()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->post();return $this->success("操作成功",J::create($tenant,$user,$p));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function retry()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->post();return $this->success("操作成功",J::retry($tenant,$user,(int)($p['id']??0)));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function adopt()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->post();return $this->success("操作成功",J::adopt($tenant,$user,$p));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function execution()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->post();return $this->success("操作成功",W::execution($tenant,$user,$p));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function assets()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->get();return $this->success("操作成功",W::assets($tenant,$user,$p));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function brief()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->post();return $this->success("操作成功",P::brief($p));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function variants()
    {
        try {$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$admin=false;MicroVideoService::available($tenant);$p=$this->request->post();return $this->success("操作成功",W::variants($tenant,$user,$p));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
    }
    public function subjects()
    {try{$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;MicroVideoService::available($tenant);$p=$this->request->get();return $this->success("操作成功",\app\common\service\app\aigc_micro_video\MicroVideoWorkbenchAssets::subjects($tenant,$user,$p));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}}
    public function importSubject()
    {try{$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;MicroVideoService::available($tenant);$p=$this->request->post();return $this->success("操作成功",\app\common\service\app\aigc_micro_video\MicroVideoWorkbenchAssets::importSubject($tenant,$user,(int)($p['id']??0)));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}}
    public function importAsset()
    {try{$tenant=(int)$this->request->tenantId;$user=(int)$this->userId;MicroVideoService::available($tenant);$p=$this->request->post();return $this->success("操作成功",\app\common\service\app\aigc_micro_video\MicroVideoWorkbenchAssets::importAsset($tenant,$user,$p));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}}
}
