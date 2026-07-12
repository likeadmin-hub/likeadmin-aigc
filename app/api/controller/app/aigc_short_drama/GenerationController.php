<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use Exception;
use Throwable;
use think\facade\Log;

class GenerationController extends BaseApiController
{
    public function create()
    {
        try {
            return $this->success('success', AigcShortDramaService::createShotGenerationTask((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function estimate()
    {
        try {
            return $this->success('success', AigcShortDramaService::estimateShotGenerationTask((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function stream()
    {
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        $this->prepareStreamResponse();
        $this->emitStreamPing();

        try {
            AigcShortDramaService::streamGenerationTask(
                (int)$this->request->tenantId,
                $this->userId,
                $this->streamParams(),
                function (string $event, array $data) {
                    $this->emitStreamEvent($event, $data);
                }
            );
        } catch (Exception $e) {
            Log::write('AI short drama generation stream controller failed: ' . $e->getMessage(), 'error');
            $this->emitStreamEvent('error', ['message' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::write('AI short drama generation stream controller fatal: ' . $e->getMessage(), 'error');
            $this->emitStreamEvent('error', ['message' => '生成失败，请稍后重试']);
        }
        exit;
    }

    public function detail()
    {
        try {
            return $this->success('success', AigcShortDramaService::generationTaskDetail((int)$this->request->tenantId, $this->userId, (string)$this->request->get('task_id', '')));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function lists()
    {
        try {
            return $this->success('success', AigcShortDramaService::generationTaskLists((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function retry()
    {
        try {
            return $this->success('success', AigcShortDramaService::retryGenerationTask((int)$this->request->tenantId, $this->userId, (string)$this->request->post('task_id', '')));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function cancel()
    {
        try {
            return $this->success('success', AigcShortDramaService::cancelGenerationTask((int)$this->request->tenantId, $this->userId, (string)$this->request->post('task_id', '')));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    private function emitStreamEvent(string $event, array $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush();
        @flush();
    }

    private function prepareStreamResponse(): void
    {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
    }

    private function emitStreamPing(): void
    {
        echo ": ping\n\n";
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
