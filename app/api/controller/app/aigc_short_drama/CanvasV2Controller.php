<?php
declare(strict_types=1);
namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\ShortDramaCanvasV2Service as Canvas;

final class CanvasV2Controller extends BaseApiController
{
    public function capabilities() { return $this->respond(fn()=>Canvas::capabilities((int)$this->request->tenantId)); }
    public function create() { return $this->respond(fn()=>Canvas::create((int)$this->request->tenantId,$this->userId,$this->request->post())); }
    public function projectWorkspace() { return $this->respond(fn()=>Canvas::projectWorkspace((int)$this->request->tenantId,$this->userId,(int)$this->request->get('project_id',0))); }
    public function detail() { return $this->respond(fn()=>Canvas::detail((int)$this->request->tenantId,$this->userId,(int)$this->request->get('workspace_id',0))); }
    public function saveGraph() { return $this->post('saveGraph'); }
    public function agent() { return $this->post('agent'); }
    public function quoteMedia() { return $this->post('quoteMedia'); }
    public function submitMedia() { return $this->post('submitMedia'); }
    public function applyNode() { return $this->post('applyNode'); }
    public function refreshTask() { return $this->respond(fn()=>Canvas::refreshTask((int)$this->request->tenantId,$this->userId,(int)$this->request->get('workspace_id',0),(int)$this->request->get('action_id',0))); }
    public function agentStatus() { return $this->respond(fn()=>Canvas::agentStatus((int)$this->request->tenantId,$this->userId,(int)$this->request->get('workspace_id',0),(int)$this->request->get('thread_id',0),(int)$this->request->get('run_id',0))); }
    private function post(string $method) { return $this->respond(fn()=>Canvas::$method((int)$this->request->tenantId,$this->userId,(int)$this->request->post('workspace_id',0),$this->request->post())); }
    private function respond(callable $action)
    {
        try { return $this->success('success',$action()); }
        catch (\Throwable $e) {
            $message=$e->getMessage();
            if (preg_match('/SQLSTATE|Stack trace|\\\\|class .*not found/i',$message)) $message='画布暂不可用，请稍后重试';
            return $this->fail($message);
        }
    }
}
