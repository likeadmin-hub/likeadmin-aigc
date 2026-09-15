<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\AppAccessService;
use app\common\service\app\aigc_short_drama\canvas_v2\ShortDramaCanvasV2Service;
use think\facade\Log;

/** User API for the independently stored short-drama Canvas V2. */
class CanvasV2Controller extends BaseApiController
{
    private function respond(callable $callback)
    {
        $tenantId = (int)$this->request->tenantId;
        $denied = AppAccessService::assertTenantCanUse($tenantId, 'aigc_short_drama', $this->userId);
        if ($denied) return $denied;
        try { return $this->success('success', $callback(new ShortDramaCanvasV2Service($tenantId, $this->userId))); }
        catch (\DomainException $e) {
            [$code, $message] = array_pad(explode(': ', $e->getMessage(), 2), 2, '短剧画布操作失败');
            return $this->fail($message, ['error_code' => $code]);
        } catch (\Throwable $e) {
            Log::error('ShortDramaCanvasV2: ' . $e->getMessage());
            return $this->fail('短剧画布暂时不可用，请保留内容后重试', ['error_code' => 'CANVAS_V2_UNAVAILABLE']);
        }
    }

    public function capabilities() { return $this->respond(fn(ShortDramaCanvasV2Service $s) => $s->capabilities()); }
    public function create() { return $this->respond(fn(ShortDramaCanvasV2Service $s) => $s->create($this->request->post())); }
    /** Resolve a canvas workspace for project-list and legacy plan entry links. */
    public function projectWorkspace() { return $this->respond(fn(ShortDramaCanvasV2Service $s) => $s->projectWorkspace((int)$this->request->get('project_id', 0))); }
    public function detail() { return $this->respond(fn(ShortDramaCanvasV2Service $s) => $s->detail((int)$this->request->get('workspace_id', 0))); }
    public function saveGraph() { return $this->respond(fn(ShortDramaCanvasV2Service $s) => $s->saveGraph((int)$this->request->post('workspace_id', 0), $this->request->post())); }
    public function messages() { return $this->respond(fn(ShortDramaCanvasV2Service $s) => ['lists' => $s->messages((int)$this->request->get('workspace_id', 0))]); }
    public function agent() { return $this->respond(fn(ShortDramaCanvasV2Service $s) => $s->agent((int)$this->request->post('workspace_id', 0), $this->request->post())); }
    public function quoteMedia() { return $this->respond(fn(ShortDramaCanvasV2Service $s) => $s->quoteMedia((int)$this->request->post('workspace_id', 0), $this->request->post())); }
    public function submitMedia() { return $this->respond(fn(ShortDramaCanvasV2Service $s) => $s->submitMedia((int)$this->request->post('workspace_id', 0), $this->request->post())); }
    public function refreshTask() { return $this->respond(fn(ShortDramaCanvasV2Service $s) => $s->refreshTask((int)$this->request->get('workspace_id', 0), (int)$this->request->get('action_id', 0))); }
    public function applyNode() { return $this->respond(fn(ShortDramaCanvasV2Service $s) => $s->applyNode((int)$this->request->post('workspace_id', 0), $this->request->post())); }
}
