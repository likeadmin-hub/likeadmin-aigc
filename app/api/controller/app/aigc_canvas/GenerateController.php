<?php

namespace app\api\controller\app\aigc_canvas;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_canvas\AigcCanvasService;
use Exception;

class GenerateController extends BaseApiController
{
    public function image()
    {
        try {
            $result = AigcCanvasService::generateImage((int)$this->request->tenantId, $this->userId, $this->request->post());
            if (($result['status'] ?? '') === 'failed') {
                throw new Exception((string)($result['error'] ?? '图片生成失败'));
            }
            return $this->success('生成成功', $this->formatImageResult($result));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function video()
    {
        try {
            $result = AigcCanvasService::generateVideo((int)$this->request->tenantId, $this->userId, $this->request->post());
            if (($result['status'] ?? '') === 'failed') {
                throw new Exception((string)($result['error'] ?? '视频生成失败'));
            }
            return $this->success('生成成功', $this->formatVideoResult($result));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function music()
    {
        try {
            $result = AigcCanvasService::generateMusic((int)$this->request->tenantId, $this->userId, $this->request->post());
            if (($result['status'] ?? '') === 'failed') {
                throw new Exception((string)($result['error'] ?? '音乐生成失败'));
            }
            return $this->success('鐢熸垚鎴愬姛', $this->formatMusicResult($result));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function text()
    {
        try {
            return $this->success('生成成功', $this->formatTextResult(AigcCanvasService::generateText((int)$this->request->tenantId, $this->userId, $this->request->post())));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function textStream()
    {
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        try {
            $result = AigcCanvasService::streamText((int)$this->request->tenantId, $this->userId, $this->request->post(), function (string $event, array $data) {
                $this->emitStreamEvent($event, $data);
            });
            $this->emitStreamEvent('done', $this->formatTextResult($result));
        } catch (Exception $e) {
            $this->emitStreamEvent('error', ['message' => $e->getMessage()]);
        }
        exit;
    }

    public function videoQuery()
    {
        try {
            $taskId = (int)$this->request->get('task_id', $this->request->post('task_id', 0));
            if ($taskId <= 0) {
                throw new Exception('缺少任务ID');
            }
            return $this->success('获取成功', $this->formatVideoTask(AigcCanvasService::videoTaskDetail((int)$this->request->tenantId, $this->userId, $taskId)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function musicQuery()
    {
        try {
            $taskId = (int)$this->request->get('task_id', $this->request->post('task_id', 0));
            if ($taskId <= 0) {
                throw new Exception('缂哄皯浠诲姟ID');
            }
            return $this->success('鑾峰彇鎴愬姛', $this->formatMusicTask(AigcCanvasService::musicTaskDetail((int)$this->request->tenantId, $this->userId, $taskId)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function imageQuery()
    {
        try {
            $taskId = (int)$this->request->get('task_id', $this->request->post('task_id', 0));
            if ($taskId <= 0) {
                throw new Exception('缺少任务ID');
            }
            return $this->success('获取成功', $this->formatImageTask(AigcCanvasService::imageTaskDetail((int)$this->request->tenantId, $this->userId, $taskId)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    private function formatImageResult(array $result): array
    {
        $items = [];
        foreach ((array)($result['results'] ?? []) as $row) {
            $url = (string)($row['image_url'] ?? $row['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'url' => $url,
                'image_url' => $url,
                'width' => (int)($row['width'] ?? 0),
                'height' => (int)($row['height'] ?? 0),
            ];
        }
        return [
            'task_id' => (int)($result['task_id'] ?? 0),
            'taskId' => (string)($result['task_id'] ?? ''),
            'status' => (string)($result['status'] ?? (empty($items) ? 'running' : 'success')),
            'error' => (string)($result['error'] ?? ''),
            'images' => $items,
            'results' => $items,
        ];
    }

    private function formatVideoResult(array $result): array
    {
        $items = [];
        foreach ((array)($result['results'] ?? []) as $row) {
            $url = (string)($row['video_url'] ?? $row['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'url' => $url,
                'video_url' => $url,
                'width' => (int)($row['width'] ?? 0),
                'height' => (int)($row['height'] ?? 0),
            ];
        }
        $taskId = (int)($result['task_id'] ?? 0);
        return [
            'task_id' => $taskId,
            'taskId' => (string)$taskId,
            'status' => (string)($result['status'] ?? (empty($items) ? 'running' : 'success')),
            'error' => (string)($result['error'] ?? ''),
            'url' => (string)($items[0]['url'] ?? ''),
            'videos' => $items,
            'results' => $items,
        ];
    }

    private function formatMusicResult(array $result): array
    {
        $items = $this->formatMusicItems((array)($result['results'] ?? []));
        $taskId = (int)($result['task_id'] ?? 0);
        return [
            'task_id' => $taskId,
            'taskId' => (string)$taskId,
            'provider_task_id' => (string)($result['provider_task_id'] ?? ''),
            'status' => (string)($result['status'] ?? (empty($items) ? 'running' : 'success')),
            'error' => (string)($result['error'] ?? ''),
            'url' => (string)($items[0]['url'] ?? ''),
            'audios' => $items,
            'music' => $items,
            'results' => $items,
        ];
    }

    private function formatTextResult(array $result): array
    {
        return [
            'content' => (string)($result['content'] ?? ''),
            'text' => (string)($result['content'] ?? ''),
            'model_code' => (string)($result['model_code'] ?? ''),
            'channel_code' => (string)($result['channel_code'] ?? ''),
            'finish_reason' => (string)($result['finish_reason'] ?? ''),
            'usage' => $result['usage'] ?? [],
            'billing' => $result['billing'] ?? [],
            'charge_points' => $result['charge_points'] ?? '0.00',
        ];
    }

    private function emitStreamEvent(string $event, array $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush();
        @flush();
    }

    private function formatImageTask(array $task): array
    {
        $items = [];
        foreach ((array)($task['results'] ?? []) as $row) {
            $url = (string)($row['image_url'] ?? $row['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'url' => $url,
                'image_url' => $url,
                'width' => (int)($row['width'] ?? 0),
                'height' => (int)($row['height'] ?? 0),
            ];
        }
        return [
            'task_id' => (int)($task['id'] ?? 0),
            'taskId' => (string)($task['id'] ?? ''),
            'status' => (string)($task['status'] ?? ''),
            'error' => (string)($task['error'] ?? ''),
            'url' => (string)($items[0]['url'] ?? ''),
            'images' => $items,
            'results' => $items,
        ];
    }

    private function formatVideoTask(array $task): array
    {
        $items = [];
        foreach ((array)($task['results'] ?? []) as $row) {
            $url = (string)($row['video_url'] ?? '');
            if ($url === '') {
                continue;
            }
            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'url' => $url,
                'video_url' => $url,
                'width' => (int)($row['width'] ?? 0),
                'height' => (int)($row['height'] ?? 0),
            ];
        }
        return [
            'task_id' => (int)($task['id'] ?? 0),
            'taskId' => (string)($task['id'] ?? ''),
            'status' => (string)($task['status'] ?? ''),
            'error' => (string)($task['error'] ?? ''),
            'url' => (string)($items[0]['url'] ?? ''),
            'videos' => $items,
            'results' => $items,
        ];
    }

    private function formatMusicTask(array $task): array
    {
        $items = $this->formatMusicItems((array)($task['results'] ?? []));
        return [
            'task_id' => (int)($task['id'] ?? 0),
            'taskId' => (string)($task['id'] ?? ''),
            'provider_task_id' => (string)($task['provider_task_id'] ?? ''),
            'status' => (string)($task['status'] ?? ''),
            'error' => (string)($task['error'] ?? ''),
            'url' => (string)($items[0]['url'] ?? ''),
            'audios' => $items,
            'music' => $items,
            'results' => $items,
        ];
    }

    private function formatMusicItems(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = (string)($row['audio_url'] ?? $row['url'] ?? $row['download_url'] ?? '');
            if ($url === '') {
                continue;
            }
            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'url' => $url,
                'audio_url' => $url,
                'wav_url' => (string)($row['wav_url'] ?? ''),
                'mp4_url' => (string)($row['mp4_url'] ?? ''),
                'midi_url' => (string)($row['midi_url'] ?? ''),
                'timing_url' => (string)($row['timing_url'] ?? ''),
                'vox_url' => (string)($row['vox_url'] ?? ''),
                'cover_url' => (string)($row['cover_url'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'duration' => (float)($row['duration'] ?? 0),
                'lyrics' => (string)($row['lyrics'] ?? ''),
                'mime_type' => (string)($row['mime_type'] ?? ''),
                'file_size' => (int)($row['file_size'] ?? 0),
            ];
        }
        return $items;
    }
}
