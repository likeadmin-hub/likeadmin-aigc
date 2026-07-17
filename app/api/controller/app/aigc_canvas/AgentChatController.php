<?php

namespace app\api\controller\app\aigc_canvas;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;
use app\common\service\app\aigc_canvas\agent\batch\EcommerceAgentBatchService;
use Exception;
use Throwable;
use think\facade\Log;

class AgentChatController extends BaseApiController
{
    public function threadCreate()
    {
        try {
            return $this->success('created', AigcCanvasAgentRuntimeService::createThread((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function threadLists()
    {
        try {
            return $this->success('success', AigcCanvasAgentRuntimeService::threadLists((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function threadDetail()
    {
        try {
            return $this->success('success', AigcCanvasAgentRuntimeService::threadDetail((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function messageLists()
    {
        try {
            return $this->success('success', AigcCanvasAgentRuntimeService::messageLists((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function runStatus()
    {
        try {
            return $this->success('success', AigcCanvasAgentRuntimeService::runStatus(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->get()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function threadDelete()
    {
        try {
            AigcCanvasAgentRuntimeService::deleteThread((int)$this->request->tenantId, $this->userId, $this->request->post());
            return $this->success('deleted', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function send()
    {
        try {
            return $this->success('success', AigcCanvasAgentRuntimeService::send((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function stream()
    {
        $this->prepareStream();
        try {
            AigcCanvasAgentRuntimeService::send(
                (int)$this->request->tenantId,
                $this->userId,
                $this->streamParams(),
                function (string $event, array $data) {
                    $this->emitStreamEvent($event, $data);
                }
            );
        } catch (Exception $e) {
            Log::write('AI canvas agent stream failed: ' . $e->getMessage(), 'error');
            $this->emitStreamEvent('agent.error', ['message' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::write('AI canvas agent stream fatal: ' . $e->getMessage(), 'error');
            $this->emitStreamEvent('agent.error', ['message' => 'Agent failed. Please try again.']);
        }
        exit;
    }

    public function cancel()
    {
        try {
            return $this->success('canceled', AigcCanvasAgentRuntimeService::cancel((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function retry()
    {
        try {
            return $this->success('success', AigcCanvasAgentRuntimeService::retry((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function workspaceAction()
    {
        try {
            return $this->success('success', AigcCanvasAgentRuntimeService::recordWorkspaceActionResult((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function batchExecute()
    {
        try {
            return $this->success('success', EcommerceAgentBatchService::execute(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->post()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function batchStatus()
    {
        try {
            return $this->success('success', EcommerceAgentBatchService::status(
                (int)$this->request->tenantId,
                $this->userId,
                $this->request->get()
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    private function prepareStream(): void
    {
        @ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
    }

    private function emitStreamEvent(string $event, array $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush();
        @flush();
    }

    private function streamParams(): array
    {
        $params = $this->request->post();
        if (!empty($params)) {
            return $params;
        }
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $json = json_decode($raw, true);
        return is_array($json) ? $json : [];
    }
}
