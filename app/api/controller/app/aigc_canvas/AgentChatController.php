<?php

namespace app\api\controller\app\aigc_canvas;

use app\api\controller\BaseApiController;
use app\common\service\app\aigc_canvas\AigcCanvasAgentRuntimeService;
use app\common\service\app\aigc_canvas\agent\batch\EcommerceAgentBatchService;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryItemService;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryPlanService;
use app\common\service\app\aigc_canvas\agent\delivery\DeliveryGraphExecutor;
use app\common\service\app\aigc_canvas\agent\delivery\PendingActionProtocol;
use app\common\service\app\aigc_canvas\agent\delivery\ExternalAssetImportService;
use app\common\service\app\aigc_canvas\agent\replay\CanvasAgentReplayService;
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

    public function planDetail()
    {
        try {
            return $this->success('success', EcommerceAgentBatchService::planDetail((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function planPatch()
    {
        try {
            return $this->success('success', EcommerceAgentBatchService::planPatch((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function claimConfirm()
    {
        try {
            return $this->success('success', EcommerceAgentBatchService::claimConfirm((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function planRegenerate()
    {
        try {
            return $this->success('success', EcommerceAgentBatchService::planRegenerate((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function replay()
    {
        try {
            return $this->success('success', CanvasAgentReplayService::detail((int)$this->request->tenantId, $this->userId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function share()
    {
        try {
            return $this->success('success', CanvasAgentReplayService::share((int)$this->request->tenantId, $this->userId, $this->request->post()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function sharedReplay()
    {
        try {
            return $this->success('success', CanvasAgentReplayService::shared((int)$this->request->tenantId, $this->request->get()));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function deliveryPlanDetail()
    {
        try {
            return $this->success('success', DeliveryPlanService::detail(
                (int)$this->request->tenantId,
                $this->userId,
                (int)$this->request->get('delivery_plan_id', $this->request->get('plan_id', 0))
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function deliveryItemDetail()
    {
        try {
            return $this->success('success', DeliveryItemService::find(
                (int)$this->request->tenantId,
                $this->userId,
                (int)$this->request->get('delivery_item_id', $this->request->get('item_id', 0))
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function deliveryItemTransition()
    {
        try {
            $params = $this->request->post();
            return $this->success('success', DeliveryItemService::transition(
                (int)$this->request->tenantId,
                $this->userId,
                (int)($params['delivery_item_id'] ?? $params['item_id'] ?? 0),
                (string)($params['status'] ?? ''),
                (array)($params['patch'] ?? [])
            ));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function deliveryItemAction()
    {
        try {
            $params = $this->request->post();
            $itemId = (int)($params['delivery_item_id'] ?? $params['item_id'] ?? 0);
            $item = DeliveryItemService::find((int)$this->request->tenantId, $this->userId, $itemId);
            if ($item === []) throw new Exception('Delivery item not found');
            $resolution = PendingActionProtocol::resolve($item, $params, (string)($params['content'] ?? ''));
            if ($resolution === []) throw new Exception('No matching pending action');
            $item = DeliveryItemService::transition((int)$this->request->tenantId, $this->userId, $itemId, (string)$resolution['status'], (array)$resolution['patch']);
            if (!empty($resolution['accepted'])) {
                $type = (string)($resolution['action']['type'] ?? '');
                if ($type === 'confirm_execution') $item = DeliveryGraphExecutor::execute((int)$this->request->tenantId, $this->userId, $itemId);
                if ($type === 'retry_item' || ($type === 'resolve_failure' && (string)(($params['structured_value']['resolution'] ?? '') ?: '') === 'retry')) {
                    $item = DeliveryGraphExecutor::retry((int)$this->request->tenantId, $this->userId, $itemId);
                }
            }
            return $this->success('success', $item);
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function deliveryItemExecute()
    {
        try {
            $params = $this->request->post();
            return $this->success('success', DeliveryGraphExecutor::execute((int)$this->request->tenantId, $this->userId, (int)($params['delivery_item_id'] ?? $params['item_id'] ?? 0), $params));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function deliveryItemRefresh()
    {
        try {
            return $this->success('success', DeliveryGraphExecutor::refresh((int)$this->request->tenantId, $this->userId, (int)$this->request->get('delivery_item_id', $this->request->get('item_id', 0))));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function deliveryItemCancel()
    {
        try {
            $params = $this->request->post();
            return $this->success('success', DeliveryGraphExecutor::cancel((int)$this->request->tenantId, $this->userId, (int)($params['delivery_item_id'] ?? $params['item_id'] ?? 0)));
        } catch (Exception $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function deliveryItemImportAsset()
    {
        try {
            $params = $this->request->post();
            return $this->success('success', ExternalAssetImportService::importImage(
                (int)$this->request->tenantId,
                $this->userId,
                (int)($params['delivery_item_id'] ?? $params['item_id'] ?? 0),
                (string)($params['url'] ?? ''),
                $params
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
