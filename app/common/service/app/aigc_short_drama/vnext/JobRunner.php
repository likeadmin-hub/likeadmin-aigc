<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use app\common\service\app\aigc_short_drama\ShortDramaPlanningBudget;
use RuntimeException;

/** One reusable core for HTTP/episode/canvas workers. Enrollment remains outside this runner. */
final class JobRunner
{
    public static function run(ExecutionStore $store, string $owner, callable $provider, ?callable $progress = null, ?callable $publishProjection = null, ?callable $reconcile = null): array
    {
        $snapshot = $store->snapshot();
        if (!$snapshot) throw new RuntimeException('生成作业不存在', 404);
        if ($snapshot['execution_status'] === 'succeeded') return json_decode($snapshot['candidate_json'], true, 512, JSON_THROW_ON_ERROR);
        $input = json_decode($snapshot['input_json'], true, 512, JSON_THROW_ON_ERROR);
        $lease = (int)($input['execution']['lease_seconds'] ?? 0);
        $timeout = (int)($input['execution']['stage_timeout_seconds'] ?? 0);
        if ($timeout < 1 || $lease <= $timeout) throw new RuntimeException('该模型尚未配置经过验证的阶段超时和执行租约', 422);
        $fence = $store->acquire($owner, $lease);
        $notify = static function (string $event, array $payload) use ($progress): void {
            if ($progress) {
                try { $progress($event, $payload); }
                catch (\Throwable $ignored) { /* Display/audit delivery cannot discard persisted content. */ }
            }
        };
        try {
        $result = GenerationPipeline::run($input['context'],
            static function (string $key, array $messages, string $reservation) use ($store, $fence, $lease, $input, $provider, $notify, $timeout, $reconcile): array {
                $store->heartbeat($fence, $lease);
                // Capacity preflight precedes durable admission. No silent truncation of original inputs.
                $budget = ShortDramaPlanningBudget::stage($messages['system_prompt'] . $messages['content'], $input['model'], 'script', (int)($input['execution']['max_output_tokens'] ?? 8192));
                $params = $messages + ['model_selection' => $input['model'], 'model_config' => ['max_tokens' => $budget['max_tokens'], 'enable_thinking' => false],
                    'request_timeout_seconds' => $timeout, '_disable_transient_retry' => true, '_disable_compatibility_retry' => true,
                    '_disable_model_fallback' => true, '_require_final_content' => true];
                $notify('stage', ['stage' => $key]);
                return $store->call($fence, $key, $params, $provider, $reservation, $reconcile);
            },
            static fn(string $key, int $calls) => $store->reserveRepair($fence, $key, $calls),
            static function (array $candidate) use ($store, $fence, $notify): void {
                $store->checkpoint($fence, $candidate);
                $notify('snapshot', $store->publicSnapshot());
            });
        } catch (RuntimeException $error) {
            if (in_array($error->getCode(), [409, 413, 422, 429], true) && $store->snapshot()['execution_status'] === 'running') {
                try { $store->needsAttention($fence, $error->getCode() === 429 ? 'budget_exhausted' : 'content_unresolved'); }
                catch (\Throwable $ignored) { /* A newer fence/cancel must win. */ }
            }
            throw $error;
        }
        if ($result['coverage_report']['status'] !== 'ready') {
            $store->needsAttention($fence, $result['stop_reason'] ?? 'content_unresolved');
            return $result;
        }
        $store->publish($fence, $result['document'], $result['shot_plan'], $result['coverage_report'], $publishProjection);
        return $result;
    }
}
