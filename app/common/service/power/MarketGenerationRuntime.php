<?php
namespace app\common\service\power;

use app\common\model\ai\AiConsumptionLog;
use app\common\model\ai\AiAppTask;
use app\common\service\ai\GenerationTaskResultService;
use InvalidArgumentException;

/** One tool-facing invocation contract. Existing adapters remain the sole owners of billing and transport. */
final class MarketGenerationRuntime
{
    private function __construct(private readonly array $selection, public readonly string $adapter, private readonly int $taskId = 0) {}

    public static function selection(string $type, array $selection): self
    {
        if (!in_array($type, ['image', 'video', 'audio', 'application'], true)) throw new InvalidArgumentException('该生成类型尚未接入统一运行接口');
        $id = (int)($selection['market_product_id'] ?? $selection['product_id'] ?? 0);
        if ($id>0) {
            $product = \app\common\model\power\PowerMarketProduct::find($id);
            if (!$product) throw new InvalidArgumentException('所选市场资源不存在');
            $resource = (string)$product['resource_type'];
            if (isset($selection['resource_type']) && $selection['resource_type']!==$resource) throw new InvalidArgumentException('市场资源类型与所选模型不一致');
            $selection['resource_type'] = $resource;
            if ($resource==='model') $selection['model_type']=(string)$product['model_type'];
            else {
                $app = (string)$product['upstream_app_code'];
                if (!empty($selection['upstream_app_code']) && $selection['upstream_app_code']!==$app) throw new InvalidArgumentException('市场应用与所选资源不一致');
                $selection['upstream_app_code']=$app;
            }
        }
        $adapter = $type === 'application' ? MarketGenerationAdapterRegistry::application($selection)
            : MarketGenerationAdapterRegistry::runtime($type, $selection);
        return new self($selection, $adapter);
    }

    /** Recovery uses the accepted receipt, never a new browser selection or mutable model defaults. */
    public static function consumption(int $id, int $tenantId, int $userId): self
    {
        $c = AiConsumptionLog::where(['id'=>$id, 'tenant_id'=>$tenantId, 'user_id'=>$userId, 'provider'=>'power_market'])->find();
        if (!$c) throw new InvalidArgumentException('生成任务不存在或无权访问');
        if (!AiAppTask::where(['id'=>(int)$c['app_task_id'],'tenant_id'=>$tenantId,'user_id'=>$userId])->find()) throw new InvalidArgumentException('生成任务关联不存在或无权访问');
        $snapshot = (array)$c['price_snapshot'];
        if ($c['protocol'] === 'application_api') return new self($snapshot, MarketApplicationApiRuntimeService::adapterForConsumption($id), (int)$c['app_task_id']);
        $type = match ($c['protocol']) {'image_generate'=>'image', 'video_generate'=>'video', default=>''};
        if ($type === '') throw new InvalidArgumentException('该任务协议尚未接入统一运行接口');
        // No live product lookup here: the task continues with its accepted protocol even if the catalogue changes.
        return new self($snapshot, MarketGenerationAdapterRegistry::runtime($type, $snapshot), (int)$c['app_task_id']);
    }

    public function quote(int $tenantId, int $quantity = 1, array $billingOverride = []): array
    {
        $adapter = $this->adapter;
        if (in_array($adapter, [MarketImageModelRuntimeService::class, MarketNanoBananaAppRuntimeService::class], true)) {
            return $adapter::quote($tenantId, $this->selection, $quantity, $billingOverride);
        }
        if ($billingOverride !== []) throw new InvalidArgumentException('该适配器的报价不接受应用售价覆盖');
        if (in_array($adapter, [MarketVideoModelRuntimeService::class, MarketVideoAppRuntimeService::class, MarketMusicAppRuntimeService::class], true)) {
            if ($quantity !== 1) throw new InvalidArgumentException('该生成能力每次仅支持一个任务');
            return $adapter::quote($tenantId, $this->selection);
        }
        return MarketApplicationApiRuntimeService::quote($tenantId, $this->selection, $quantity);
    }

    public function reserve(int $tenantId, int $userId, string $appCode, string $action, string $businessTable,
        string $businessId, array $request, int $quantity = 1, array $billingOverride = []): array
    {
        $adapter = $this->adapter;
        if (in_array($adapter, [MarketImageModelRuntimeService::class, MarketNanoBananaAppRuntimeService::class], true)) {
            return $adapter::reserve($tenantId, $userId, $action, $businessId, $this->selection, $request, $quantity, $appCode, $businessTable, $billingOverride);
        }
        if (in_array($adapter, [MarketVideoModelRuntimeService::class, MarketVideoAppRuntimeService::class], true)) {
            if ($quantity !== 1) throw new InvalidArgumentException('视频生成每次仅支持一个任务');
            return $adapter::reserve($tenantId, $userId, $appCode, $action, $businessTable, $businessId, $this->selection, $request, $billingOverride);
        }
        if ($billingOverride !== []) throw new InvalidArgumentException('该适配器尚未支持应用售价覆盖');
        return MarketApplicationApiRuntimeService::reserve($tenantId, $userId, $action, $businessId, $this->selection,
            $request, $quantity, $appCode, $businessTable);
    }

    public function submit(int $id, array $request, int $tenantId, int $userId): array
    {
        $frozen = self::consumption($id, $tenantId, $userId);
        if ($frozen->adapter !== $this->adapter) throw new InvalidArgumentException('任务与生成适配器不匹配');
        $adapter = $frozen->adapter;
        return $frozen->decorate($adapter::submit($id, $request), $tenantId, $userId);
    }

    public function refresh(int $id, int $tenantId, int $userId): array
    {
        $frozen = self::consumption($id, $tenantId, $userId);
        $adapter = $frozen->adapter;
        return $frozen->decorate($adapter::refresh($id), $tenantId, $userId);
    }

    public function cancel(int $id, int $tenantId, int $userId): void
    {
        $adapter = self::consumption($id, $tenantId, $userId)->adapter;
        if (!method_exists($adapter, 'cancel')) throw new InvalidArgumentException('该生成能力不支持取消');
        $adapter::cancel($id);
    }

    public function bind(int $appTaskId, int $businessId, int $tenantId, int $userId): void
    {
        \think\facade\Db::transaction(function()use($appTaskId,$businessId,$tenantId,$userId){
            $task=AiAppTask::where(['id'=>$appTaskId,'tenant_id'=>$tenantId,'user_id'=>$userId])->lock(true)->find();
            if (!$task || $businessId<=0 || ($task['business_id']>0 && (int)$task['business_id']!==$businessId)) throw new InvalidArgumentException('生成任务业务关联不匹配');
            $adapter=$this->adapter;
            $adapter::linkBusinessTask($appTaskId,$businessId);
        });
    }

    private function decorate(array $response, int $tenantId, int $userId): array
    {
        try { $response['generation_task']=GenerationTaskResultService::read($this->taskId,$tenantId,$userId); }
        catch (\Throwable) {
            // Provider acceptance is already durable. A delivery-view outage must never enter a caller's refund/re-submit catch.
            $response['generation_task']=GenerationTaskResultService::pending($this->taskId);
        }
        return $response;
    }
}
