<?php

namespace app\common\service\app\aigc_music;

use app\common\model\ai\AiConsumptionLog;
use app\common\model\app\aigc_music\{AigcMusicTask, AigcMusicResult, AigcMusicBilling};
use app\common\service\power\MarketMusicAppRuntimeService as Runtime;
use app\common\service\app\aigc_llm\agent\AgentToolContext;
use app\common\service\open_platform\{OpenCallContext, OpenStore};
use think\facade\Db;
use Exception;

/** Native music records are a projection of the shared market consumption. */
final class AigcMusicMarketService
{
    public const PROVIDER = 'market_music_generation';

    public static function config(int $tenantId): array
    {
        $channels = [];
        foreach (Runtime::options($tenantId) as $option) {
            if ($option['usage_unit'] !== 'per_call' || (float)$option['usage_unit_size'] !== 1.) continue;
            $code = 'market_music_' . $option['market_sku_id'];
            $channels[] = $option + ['code' => $code, 'status' => 1, 'specs' => [[
                'quality' => (string)$option['market_sku_id'], 'quality_label' => $option['label'],
                'ratio' => '', 'platform_unit_cost' => $option['platform_unit_cost'],
                'tenant_unit_price' => $option['tenant_unit_price'], 'billing_unit' => $option['usage_unit'],
                'market_sku_id' => $option['market_sku_id'], 'market_product_id' => $option['market_product_id'],
            ]]];
        }
        $first = $channels[0] ?? [];
        return ['price_source' => 'power_market_app_api', 'channels' => $channels,
            'defaults' => ['channel' => $first['code'] ?? '', 'quality' => (string)($first['market_sku_id'] ?? ''),
                'market_sku_id' => $first['market_sku_id'] ?? 0, 'market_product_id' => $first['market_product_id'] ?? 0],
            'export_types' => AigcMusicService::exportTypes()];
    }

    public static function selection(int $tenantId, array $params): array
    {
        if (!\app\common\model\power\PowerMarketProduct::where(['resource_type' => 'app_api', 'upstream_app_code' => 'music_generation', 'upstream_api_code' => 'query'])->count()) throw new Exception('音乐查询接口尚未同步，请联系管理员同步算力市场');
        $id = (int)($params['market_sku_id'] ?? $params['sku_id'] ?? 0);
        $code = (string)($params['channel'] ?? $params['model_id'] ?? '');
        if ($id <= 0 && preg_match('/^market_music_([1-9][0-9]*)$/D', $code, $match)) $id = (int)$match[1];
        foreach (Runtime::options($tenantId) as $option) {
            if ($option['usage_unit'] !== 'per_call' || (float)$option['usage_unit_size'] !== 1.) continue;
            if ($id > 0 && (int)$option['market_sku_id'] !== $id) continue;
            if ($code !== '' && $code !== 'market_music_' . $option['market_sku_id']) continue;
            if (!empty($params['market_product_id']) && (int)$params['market_product_id'] !== (int)$option['market_product_id']) continue;
            return ['market_sku_id' => (int)$option['market_sku_id'], 'market_product_id' => (int)$option['market_product_id'],
                'channel' => ['code' => 'market_music_' . $option['market_sku_id'], 'name' => $option['name'], 'provider' => self::PROVIDER, 'model' => 'music_generation'],
                'spec' => ['quality' => (string)$option['market_sku_id'], 'quality_label' => $option['label'], 'ratio' => '',
                    'platform_unit_cost' => $option['platform_unit_cost'], 'tenant_unit_price' => $option['tenant_unit_price']]];
        }
        throw new Exception('暂无可用的音乐生成规格，请在算力市场上架音乐生成 API');
    }

    public static function estimate(int $tenantId, array $params): array
    {
        $selection = self::selection($tenantId, $params);
        $quote = Runtime::quote($tenantId, $selection);
        // The admitted generation contract is a single per-call SKU. Do not
        // manufacture duration/token estimates for other billing contracts.
        if ($quote['billing_unit'] !== 'per_call' || (float)$quote['billing_unit_size'] !== 1.) throw new Exception('音乐生成规格暂不支持此计费单位');
        return $quote + ['channel' => $selection['channel']['code'], 'quality' => $selection['spec']['quality'],
            'platform_unit_cost' => $quote['tenant_unit_points'], 'tenant_unit_price' => $quote['user_unit_points'],
            'charge_source' => 'power_market_app_api', 'duration' => AigcMusicService::normalizeDuration($params['duration'] ?? 30)];
    }

    public static function generate(int $tenantId, int $userId, array $params, array $payload, ?array $frozen): array
    {
        $selection = $frozen['selection'] ?? self::selection($tenantId, $params);
        // Validate current sellability, even when executing an accepted price.
        self::selection($tenantId, ['market_sku_id' => $selection['market_sku_id'], 'market_product_id' => $selection['market_product_id']]);
        $quote = $frozen['quote'] ?? self::estimate($tenantId, array_replace($params, [
            'market_sku_id' => $selection['market_sku_id'], 'market_product_id' => $selection['market_product_id'], 'channel' => $selection['channel']['code'],
        ]));
        $bindings = $selection['resource_bindings'] ?? AigcMusicService::referenceBindings($tenantId, $userId, $params);
        $payload = array_replace($payload, $bindings);
        $requestKey = OpenCallContext::id() ?: (AgentToolContext::musicStepId() > 0
            ? 'agent-step-' . AgentToolContext::musicStepId() : (string)($params['idempotency_key'] ?? ''));
        $fingerprint = hash('sha256', json_encode([$payload, $selection['market_product_id'], $selection['market_sku_id']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $key = $requestKey !== '' ? hash('sha256', $requestKey) : $fingerprint;
        $context = Db::transaction(function () use ($tenantId, $userId, $payload, $selection, $quote, $key, $fingerprint, $requestKey) {
            // Serialize only local admission. Never hold this lock during HTTP.
            if (!Db::name('user')->where(['tenant_id' => $tenantId, 'id' => $userId])->lock(true)->find()) throw new Exception('用户不存在');
            $query = AigcMusicTask::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'provider' => self::PROVIDER, 'idempotency_key' => $key]);
            if ($requestKey === '') $query->where('create_time', '>=', time() - 6);
            $existing = $query->order('id', 'desc')->findOrEmpty();
            if (!$existing->isEmpty()) {
                if (($existing['provider_payload']['fingerprint'] ?? '') !== $fingerprint) throw new Exception('同一幂等键的音乐参数不一致');
                return ['task' => $existing, 'new' => false];
            }
            $task = AigcMusicTask::create([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'app_code' => 'aigc_music', 'action' => 'music_generation',
                'title' => $payload['title'], 'prompt' => $payload['prompt'], 'lyrics' => $payload['lyrics'], 'genre' => $payload['genre'],
                'mood' => $payload['mood'], 'instruments' => $payload['instruments'], 'style_id' => $payload['style_id'],
                'persona_id' => $payload['persona_id'], 'voice_clone_id' => $payload['voice_clone_id'], 'reference_asset_id' => $payload['reference_asset_id'],
                'channel' => $selection['channel']['code'], 'quality' => $selection['spec']['quality'], 'ratio' => '',
                'duration' => $payload['duration'], 'quantity' => 1, 'tenant_cost_points' => $quote['tenant_cost_points'], 'user_charge_points' => $quote['user_charge_points'],
                'provider' => self::PROVIDER, 'model' => 'music_generation', 'market_product_id' => $selection['market_product_id'], 'market_sku_id' => $selection['market_sku_id'],
                'idempotency_key' => $key, 'provider_payload' => ['fingerprint' => $fingerprint, 'generation' => array_intersect_key($payload, array_flip(['custom', 'instrumental', 'vocal_gender', 'language', 'style'])), 'home_agent_step_id' => AgentToolContext::musicStepId()],
                'status' => 'running', 'progress' => 5, 'billing_status' => 'prechecked', 'safety_status' => 'passed', 'error' => '',
                'create_time' => time(), 'update_time' => time(), 'delete_time' => 0,
            ]);
            $reserved = Runtime::reserve($tenantId, $userId, (string)$task['id'], $selection, $payload, 'aigc_music', 'music_generation', 'aigc_music_task', $quote['market_snapshot']);
            Runtime::linkBusinessTask((int)$reserved['app_task_id'], (int)$task['id']);
            $task->save(['app_task_id' => $reserved['app_task_id'], 'consumption_id' => $reserved['consumption_id'],
                'pricing_snapshot' => json_encode($reserved['market_snapshot'], JSON_UNESCAPED_UNICODE), 'billing_status' => 'reserved']);
            AgentToolContext::bindMusicTask($tenantId, $userId, (int)$task['id']);
            return ['task' => $task, 'new' => true];
        });
        $task = $context['task'];
        if ($context['new']) {
            try { Runtime::submit((int)$task['consumption_id'], $payload); }
            catch (\Throwable $error) {
                OpenCallContext::rethrowLostAuthority($error);
                $c = AiConsumptionLog::findOrEmpty((int)$task['consumption_id']);
                if ($c->isEmpty() || $c['billing_status'] !== 'refunded') throw $error;
            }
            self::syncConsumption((int)$task['consumption_id'], (int)$task['id']);
        }
        $task->refresh();
        return ['task_id' => (int)$task['id'], 'id' => (int)$task['id'], 'provider_task_id' => (string)$task['provider_task_id'],
            'status' => (string)$task['status'], 'billing_status' => (string)$task['billing_status'], 'error' => (string)$task['error'],
            'estimate' => $quote, 'market_sku_id' => (int)$task['market_sku_id'], 'consumption_id' => (int)$task['consumption_id'],
            'results' => $task['status'] === 'success' ? AigcMusicService::taskDetail($tenantId, (int)$task['id'], $userId)['results'] : []];
    }

    public static function syncConsumption(int $consumptionId, int $taskId): void
    {
        Db::transaction(function () use ($consumptionId, $taskId) {
            $c = AiConsumptionLog::where('id', $consumptionId)->lock(true)->findOrEmpty();
            if ($c->isEmpty()) throw new Exception('音乐消耗记录不存在');
            $task = AigcMusicTask::where(['id' => $taskId, 'tenant_id' => $c['tenant_id'], 'user_id' => $c['user_id'], 'consumption_id' => $consumptionId, 'provider' => self::PROVIDER])->lock(true)->findOrEmpty();
            if ($task->isEmpty()) throw new Exception('音乐业务任务归属不一致');
            if (in_array($task['status'], ['success', 'failed', 'canceled'], true)) return;
            $status = (string)$c['run_status'];
            $data = ['provider_task_id' => $c['upstream_task_id'], 'market_request_id' => $c['upstream_request_id'], 'billing_status' => $c['billing_status'], 'update_time' => time()];
            $resultId = 0;
            $snapshot = (array)$c['price_snapshot'];
            if ($status === 'success' && $c['billing_status'] === 'settled') {
                foreach ((array)($c['response_summary']['items'] ?? []) as $item) {
                    if (empty($item['audio_uri'])) continue;
                    $result = AigcMusicResult::create([
                        'tenant_id' => $task['tenant_id'], 'user_id' => $task['user_id'], 'task_id' => $taskId,
                        'title' => $item['title'] ?? $task['title'], 'audio_uri' => $item['audio_uri'], 'cover_uri' => $item['cover_uri'] ?? '',
                        'storage_scope' => $item['storage_scope'], 'storage_engine' => $item['storage_engine'], 'storage_domain' => $item['storage_domain'],
                        'mime_type' => $item['mime_type'] ?? 'audio/mpeg', 'file_size' => $item['file_size'] ?? 0,
                        'duration' => $item['duration'] ?? 0, 'lyrics' => $item['lyrics'] ?? $task['lyrics'], 'timing_json' => $item['timing_json'] ?? [],
                        'tenant_cost_points' => $c['actual_tenant_cost'], 'user_charge_points' => $c['actual_user_price'],
                        'provider_task_id' => $c['upstream_task_id'], 'result_json' => $item['raw'] ?? [], 'create_time' => time(), 'delete_time' => 0,
                    ]);
                    $resultId = (int)$result['id'];
                }
                if ($resultId <= 0) throw new Exception('音乐结果文件尚未持久化');
                $data += ['status' => 'success', 'progress' => 100, 'finish_time' => time(), 'error' => '', 'tenant_cost_points' => $c['actual_tenant_cost'], 'user_charge_points' => $c['actual_user_price']];
            } elseif ($c['billing_status'] === 'refunded') {
                $data += ['status' => $status === 'canceled' ? 'canceled' : 'failed', 'progress' => 100, 'finish_time' => time(), 'error' => $c['error_message'], 'error_code' => $c['error_code']];
            } else {
                $data += ['status' => $status === 'pending_confirmation' ? 'pending_confirmation' : 'running', 'progress' => 20];
            }
            $task->save($data);
            if (in_array($data['status'], ['success', 'failed', 'canceled'], true)) AigcMusicBilling::create([
                'tenant_id' => $task['tenant_id'], 'user_id' => $task['user_id'], 'task_id' => $taskId, 'result_id' => $resultId,
                'channel' => $task['channel'], 'quality' => $task['quality'], 'ratio' => '', 'quantity' => 1,
                'platform_unit_cost' => $snapshot['platform_price'], 'tenant_unit_price' => $snapshot['tenant_price'],
                'tenant_cost_points' => $c['actual_tenant_cost'], 'user_charge_points' => $c['actual_user_price'], 'billing_status' => $c['billing_status'],
                'tenant_point_sn' => $c['tenant_point_sn'], 'user_point_sn' => $c['user_point_sn'], 'refund_time' => $c['billing_status'] === 'refunded' ? time() : 0,
                'create_time' => time(), 'update_time' => time(),
            ]);
        });
    }
}
