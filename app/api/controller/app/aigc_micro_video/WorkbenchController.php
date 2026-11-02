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
    public function writeScript()
    {try{return $this->success('已开始写剧本',J::writeScript((int)$this->request->tenantId,(int)$this->userId,$this->request->post()));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}}
    public function confirmScript()
    {try{MicroVideoService::available((int)$this->request->tenantId);return $this->success('剧本已核对',R::confirmScript((int)$this->request->tenantId,(int)$this->userId,$this->request->post()));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}}
    /** Read-only SSE: reconnects cannot enqueue or repeat a paid generation. */
    public function scriptStream()
    {
        $tenant=(int)$this->request->tenantId;$user=(int)$this->userId;$id=(int)$this->request->post('id',0);
        try{MicroVideoService::available($tenant);$row=J::owned($tenant,$user,$id);if($row['action']!=='rewrite')throw new \InvalidArgumentException('请选择剧本生成记录');}
        catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}
        header('Content-Type: text/event-stream; charset=utf-8');header('Cache-Control: no-cache, no-transform');header('X-Accel-Buffering: no');header('Content-Encoding: none');
        echo ': ready '.str_repeat(' ',2048)."\n\n";@ob_flush();@flush();$started=microtime(true);$prior='';
        do{
            $dto=J::dto(J::owned($tenant,$user,$id));$data=json_encode($dto,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if($data!==$prior){echo 'event: snapshot'."\n".'data: '.$data."\n\n";$prior=$data;}else echo ": ping\n\n";
            @ob_flush();@flush();if(in_array($dto['status'],['success','failed','unknown'],true)||connection_aborted())break;
            usleep(300000);
        }while(microtime(true)-$started<15);
        exit;
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
