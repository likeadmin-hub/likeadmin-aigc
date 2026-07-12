<?php

namespace app\api\controller\app\aigc_short_drama;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_short_drama\AigcShortDramaService;
use Exception;
use Throwable;
use think\facade\Log;

class ScriptPlanController extends BaseApiController
{
    public function create()
    {
        try {
            return $this->success('创建成功', AigcShortDramaService::createScriptPlan((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function detail()
    {
        try {
            return $this->success('获取成功', AigcShortDramaService::scriptPlanDetail(
                (int)$this->request->tenantId,
                $this->userId,
                (string)$this->request->get('task_id', ''),
                (int)$this->request->get('project_id', 0)
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function stream()
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

        try {
            AigcShortDramaService::streamScriptPlan(
                (int)$this->request->tenantId,
                $this->userId,
                $this->streamParams(),
                function (string $event, array $data) {
                    $this->emitStreamEvent($event, $data);
                }
            );
        } catch (Exception $e) {
            Log::write('AI short drama script stream controller failed: ' . $e->getMessage(), 'error');
            $this->emitStreamEvent('error', ['message' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::write('AI short drama script stream controller fatal: ' . $e->getMessage(), 'error');
            $this->emitStreamEvent('error', ['message' => '生成失败，请稍后重试']);
        }
        exit;
    }

    public function saveStoryboard()
    {
        try {
            return $this->success('保存成功', AigcShortDramaService::saveStoryboard((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            Log::write('AI short drama save storyboard failed: ' . $e->getMessage(), 'error');
            return $this->fail('保存失败，请稍后重试');
        }
    }

    public function insertStoryboardShot()
    {
        try {
            return $this->success('新增成功', AigcShortDramaService::insertStoryboardShot((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            Log::write('AI short drama insert storyboard shot failed: ' . $e->getMessage(), 'error');
            return $this->fail('新增分镜失败，请稍后重试');
        }
    }

    public function copyStoryboardShot()
    {
        try {
            return $this->success('复制成功', AigcShortDramaService::copyStoryboardShot((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            Log::write('AI short drama copy storyboard shot failed: ' . $e->getMessage(), 'error');
            return $this->fail('复制分镜失败，请稍后重试');
        }
    }

    public function deleteStoryboardShot()
    {
        try {
            return $this->success('删除成功', AigcShortDramaService::deleteStoryboardShot((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            Log::write('AI short drama delete storyboard shot failed: ' . $e->getMessage(), 'error');
            return $this->fail('删除分镜失败，请稍后重试');
        }
    }

    public function saveVisualPlan()
    {
        try {
            return $this->success('保存成功', AigcShortDramaService::saveVisualPlan((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        } catch (Throwable $e) {
            Log::write('AI short drama save visual plan failed: ' . $e->getMessage(), 'error');
            return $this->fail('保存失败，请稍后重试');
        }
    }

    public function message()
    {
        try {
            return $this->success('提交成功', AigcShortDramaService::message((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function retry()
    {
        try {
            return $this->success('重试成功', AigcShortDramaService::retry((int)$this->request->tenantId, $this->userId, (string)$this->request->post('task_id', '')));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function cancel()
    {
        try {
            return $this->success('取消成功', AigcShortDramaService::cancel((int)$this->request->tenantId, $this->userId, (string)$this->request->post('task_id', '')), 1, 1);
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
