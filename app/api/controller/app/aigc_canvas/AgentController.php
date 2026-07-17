<?php

namespace app\api\controller\app\aigc_canvas;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_canvas\AigcCanvasAgentService;
use Exception;
use Throwable;
use think\facade\Log;

class AgentController extends BaseApiController
{
    public function scriptPlan()
    {
        try {
            return $this->success('创建成功', AigcCanvasAgentService::createScriptPlan((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function scriptDetail()
    {
        try {
            return $this->success('获取成功', AigcCanvasAgentService::scriptPlanDetail((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function scriptStream()
    {
        $this->prepareStream();
        try {
            AigcCanvasAgentService::streamScriptPlan(
                (int)$this->request->tenantId,
                $this->userId,
                $this->streamParams(),
                function (string $event, array $data) {
                    $this->emitStreamEvent($event, $data);
                }
            );
        } catch (Exception $e) {
            Log::write('AI canvas script agent stream failed: ' . $e->getMessage(), 'error');
            $this->emitStreamEvent('error', ['message' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::write('AI canvas script agent stream fatal: ' . $e->getMessage(), 'error');
            $this->emitStreamEvent('error', ['message' => '生成失败，请稍后重试']);
        }
        exit;
    }

    public function subjects()
    {
        try {
            return $this->success('获取成功', AigcCanvasAgentService::listSubjects((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function subjectSave()
    {
        try {
            return $this->success('保存成功', AigcCanvasAgentService::saveSubject((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function subjectDescribe()
    {
        try {
            return $this->success('识别成功', AigcCanvasAgentService::describeSubject((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function subjectGenerate()
    {
        try {
            return $this->success('生成任务已提交', AigcCanvasAgentService::generateSubject((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function subjectImageHistory()
    {
        try {
            return $this->success('获取成功', AigcCanvasAgentService::subjectImageHistory((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function subjectRegisterImage()
    {
        try {
            return $this->success('登记成功', AigcCanvasAgentService::registerSubjectImage((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function subjectSelectImage()
    {
        try {
            return $this->success('应用成功', AigcCanvasAgentService::selectSubjectImage((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function subjectThreeViewHistory()
    {
        try {
            return $this->success('获取成功', AigcCanvasAgentService::subjectThreeViewHistory((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function storyboardSave()
    {
        try {
            return $this->success('保存成功', AigcCanvasAgentService::saveStoryboard((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function visualPlanSave()
    {
        try {
            return $this->success('保存成功', AigcCanvasAgentService::saveVisualPlan((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function storyboardInsert()
    {
        try {
            return $this->success('新增成功', AigcCanvasAgentService::insertStoryboardShot((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function storyboardCopy()
    {
        try {
            return $this->success('复制成功', AigcCanvasAgentService::copyStoryboardShot((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function storyboardDelete()
    {
        try {
            return $this->success('删除成功', AigcCanvasAgentService::deleteStoryboardShot((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function generation()
    {
        try {
            return $this->success('生成任务已提交', AigcCanvasAgentService::createGeneration((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function generationEstimate()
    {
        try {
            return $this->success('估算成功', AigcCanvasAgentService::estimateGeneration((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function generationDetail()
    {
        try {
            return $this->success('获取成功', AigcCanvasAgentService::generationDetail((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function generationLists()
    {
        try {
            return $this->success('获取成功', AigcCanvasAgentService::generationLists((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function generationRetry()
    {
        try {
            return $this->success('重试任务已提交', AigcCanvasAgentService::retryGeneration((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function generationCancel()
    {
        try {
            return $this->success('取消成功', AigcCanvasAgentService::cancelGeneration((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function generationDelete()
    {
        try {
            AigcCanvasAgentService::deleteGeneration((int)$this->request->tenantId, $this->userId, $this->request->post());
            return $this->success('删除成功', [], 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function generationStream()
    {
        $this->prepareStream();
        try {
            AigcCanvasAgentService::streamGeneration(
                (int)$this->request->tenantId,
                $this->userId,
                $this->streamParams(),
                function (string $event, array $data) {
                    $this->emitStreamEvent($event, $data);
                }
            );
        } catch (Exception $e) {
            Log::write('AI canvas generation agent stream failed: ' . $e->getMessage(), 'error');
            $this->emitStreamEvent('error', ['message' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::write('AI canvas generation agent stream fatal: ' . $e->getMessage(), 'error');
            $this->emitStreamEvent('error', ['message' => '生成失败，请稍后重试']);
        }
        exit;
    }

    public function assetRegister()
    {
        try {
            return $this->success('登记成功', AigcCanvasAgentService::registerAsset((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function assetSelect()
    {
        try {
            return $this->success('应用成功', AigcCanvasAgentService::selectAsset((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function scriptMessage()
    {
        try {
            return $this->success('提交成功', AigcCanvasAgentService::scriptMessage((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function scriptRetry()
    {
        try {
            return $this->success('重试成功', AigcCanvasAgentService::retryScript((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function scriptCancel()
    {
        try {
            return $this->success('取消成功', AigcCanvasAgentService::cancelScript((int)$this->request->tenantId, $this->userId, $this->request->post()), 1, 1);
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
