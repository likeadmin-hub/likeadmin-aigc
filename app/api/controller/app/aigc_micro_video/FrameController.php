<?php
namespace app\api\controller\app\aigc_micro_video;
use app\api\controller\BaseApiController;
use app\common\service\app\aigc_micro_video\MicroVideoFrames;
use app\common\service\app\aigc_micro_video\MicroVideoService;
class FrameController extends BaseApiController
{
    private function perform(string $action){try{return $this->success('操作成功',MicroVideoFrames::$action((int)$this->request->tenantId,$this->userId,$this->request->post()));}catch(\Throwable $e){return $this->fail(MicroVideoService::friendly($e));}}
    public function configure(){return $this->perform('configure');}
    public function save(){return $this->perform('save');}
    public function estimate(){return $this->perform('estimate');}
    public function generate(){return $this->perform('generate');}
    public function confirm(){return $this->perform('confirm');}
}
