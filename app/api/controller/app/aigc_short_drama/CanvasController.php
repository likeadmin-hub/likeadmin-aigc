<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\enum\FileEnum;
use app\common\service\app\AppAccessService;
use app\common\service\app\aigc_short_drama\canvas\CanvasAgentService;
use app\common\service\app\aigc_short_drama\canvas\CanvasShortDramaBridgeService;
use app\common\service\app\aigc_short_drama\canvas\CanvasWorkspaceService;
use app\common\service\UploadService;
use think\facade\Log;

/** Dedicated entry; does not change any existing short-drama controller. */
class CanvasController extends BaseApiController
{
    private function respond(callable $action)
    {
        $denied = AppAccessService::assertTenantCanUse((int)$this->request->tenantId, 'aigc_short_drama', $this->userId);
        if ($denied) return $denied;
        try {
            $service = new CanvasWorkspaceService((int)$this->request->tenantId, $this->userId);
            return $this->success('success', $action($service));
        } catch (\DomainException $e) {
            [$code, $message] = array_pad(explode(': ', $e->getMessage(), 2), 2, '画布操作失败');
            return $this->fail($message, ['error_code' => $code]);
        } catch (\Throwable $e) {
            Log::error('ShortDramaCanvas: ' . $e->getMessage());
            return $this->fail('画布暂时不可用，请保留内容后重试', ['error_code' => 'CANVAS_UNAVAILABLE']);
        }
    }

    /** Agent endpoints are deliberately separate from normal workspace persistence. */
    private function respondAgent(callable $action)
    {
        $denied = AppAccessService::assertTenantCanUse((int)$this->request->tenantId, 'aigc_short_drama', $this->userId);
        if ($denied) return $denied;
        try {
            return $this->success('success', $action(new CanvasAgentService((int)$this->request->tenantId, $this->userId)));
        } catch (\DomainException $e) {
            [$code, $message] = array_pad(explode(': ', $e->getMessage(), 2), 2, '画布 Agent 操作失败');
            return $this->fail($message, ['error_code' => $code]);
        } catch (\Throwable $e) {
            Log::error('ShortDramaCanvasAgent: ' . $e->getMessage());
            return $this->fail('画布 Agent 暂时不可用，请保留内容后重试', ['error_code' => 'CANVAS_AGENT_UNAVAILABLE']);
        }
    }

    public function capabilities() { return $this->respond(fn($s) => $s->capabilities()); }
    public function create() { return $this->respond(fn($s) => $s->create($this->request->post())); }
    public function lists() { return $this->respond(fn($s) => $s->lists($this->request->get())); }
    public function detail() { return $this->respond(fn($s) => $s->detail((int)$this->request->get('workspace_id', 0))); }
    public function bind() { return $this->respond(fn($s) => $s->bind((int)$this->request->post('workspace_id', 0), $this->request->post())); }
    public function view() { return $this->respond(fn($s) => $s->readView((int)$this->request->get('workspace_id', 0), (string)$this->request->get('view_key', 'global'))); }
    public function saveView() { return $this->respond(fn($s) => $s->saveView((int)$this->request->post('workspace_id', 0), $this->request->post())); }
    public function messages() { return $this->respond(fn($s) => $s->messages((int)$this->request->get('workspace_id', 0), $this->request->get())); }
    public function saveIdea() { return $this->respond(fn($s) => $s->saveIdea((int)$this->request->post('workspace_id', 0), $this->request->post())); }
    public function events() { return $this->respond(fn($s) => $s->events((int)$this->request->get('workspace_id', 0), (int)$this->request->get('after_id', 0))); }
    public function submit()
    {
        return $this->respondAgent(function (CanvasAgentService $service) {
            $service->assertExecutionAvailable();
            return $service->enqueue((int)$this->request->post('workspace_id', 0), $this->request->post());
        });
    }
    public function runStatus() { return $this->respondAgent(fn(CanvasAgentService $s) => $s->status((int)$this->request->get('workspace_id', 0), (int)$this->request->get('run_id', 0))); }
    public function drafts() { return $this->respondAgent(fn(CanvasAgentService $s) => $s->drafts((int)$this->request->get('workspace_id', 0))); }
    public function proposals() { return $this->respondAgent(fn(CanvasAgentService $s) => $s->proposals((int)$this->request->get('workspace_id', 0))); }
    public function cancelRun() { return $this->respondAgent(fn(CanvasAgentService $s) => $s->cancel((int)$this->request->post('workspace_id', 0), (int)$this->request->post('run_id', 0))); }

    /** Formal short-drama changes are explicit confirmation actions, not Agent tools. */
    private function respondBridge(callable $action)
    {
        $denied = AppAccessService::assertTenantCanUse((int)$this->request->tenantId, 'aigc_short_drama', $this->userId);
        if ($denied) return $denied;
        try {
            return $this->success('success', $action(new CanvasShortDramaBridgeService((int)$this->request->tenantId, $this->userId)));
        } catch (\DomainException $e) {
            [$code, $message] = array_pad(explode(': ', $e->getMessage(), 2), 2, '短剧画布操作失败');
            return $this->fail($message, ['error_code' => $code]);
        } catch (\Throwable $e) {
            Log::error('ShortDramaCanvasBridge: ' . $e->getMessage());
            return $this->fail('短剧操作暂时不可用，请保留内容后重试', ['error_code' => 'SHORT_DRAMA_UNAVAILABLE']);
        }
    }
    public function createProject() { return $this->respondBridge(fn(CanvasShortDramaBridgeService $s) => $s->createProject((int)$this->request->post('workspace_id', 0), $this->request->post())); }
    public function previewDrafts() { return $this->respondBridge(fn(CanvasShortDramaBridgeService $s) => $s->previewDrafts((int)$this->request->get('workspace_id', 0), $this->request->get())); }
    public function applyDrafts() { return $this->respondBridge(fn(CanvasShortDramaBridgeService $s) => $s->applyDrafts((int)$this->request->post('workspace_id', 0), $this->request->post())); }
    public function quoteProposal() { return $this->respondBridge(fn(CanvasShortDramaBridgeService $s) => $s->quoteProposal((int)$this->request->post('workspace_id', 0), (int)$this->request->post('action_id', 0), $this->request->post())); }
    public function submitProposal() { return $this->respondBridge(fn(CanvasShortDramaBridgeService $s) => $s->submitProposal((int)$this->request->post('workspace_id', 0), (int)$this->request->post('action_id', 0), $this->request->post())); }

    public function upload()
    {
        return $this->respond(function ($s) {
            $s->assertWritable();
            $workspaceId = (int)$this->request->post('workspace_id', 0);
            $s->detail($workspaceId); // Ownership check BEFORE any file write.
            $file = $this->request->file('file');
            if (!$file || $file->getSize() > 10485760 || !in_array($file->getMime(), ['image/jpeg', 'image/png', 'image/webp'], true)
                || !@getimagesize($file->getPathname())) throw new \DomainException('INVALID_FILE: 仅支持 10MB 以内的 JPG、PNG、WebP 图片');
            $checksum = hash_file('sha256', $file->getPathname());
            if (!hash_equals($checksum, (string)$this->request->post('checksum', ''))) throw new \DomainException('INVALID_CHECKSUM: 附件内容与校验信息不一致');
            return $s->uploadImage($workspaceId, (string)$this->request->post('request_key', ''), $checksum, function () {
                $result = UploadService::image(0, $this->userId, FileEnum::SOURCE_USER, 'uploads/short_drama_canvas');
                return (int)$result['id'];
            });
        });
    }
}
