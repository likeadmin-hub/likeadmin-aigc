<?php

namespace app\common\service\power;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionEvent;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\power\TenantPowerMarketSkuPrice;
use app\common\service\ai\UpstreamErrorMessageService;
use app\common\service\point\PointService;
use app\common\service\update\UpdateSourceClient;
use Exception;
use think\facade\Db;
use think\facade\Log;

/**
 * Executes text models selected from the local power market. Business apps do
 * not depend on the AIGC chat application or its channel/model tables.
 */
class MarketTextModelRuntimeService
{
    public const APP_CODE = 'power_market_text';
    private const REQUEST_TIMEOUT_SECONDS = 1200;
    private const DEFAULT_MAX_OUTPUT_TOKENS = 8192;

    public static function modelGroups(int $tenantId): array
    {
        return [
            self::group($tenantId, 'script_plan', '剧本策划模型', false),
            self::group($tenantId, 'vision_describe', '视觉文本模型', true),
        ];
    }

    public static function estimateTokens(string $text): int
    {
        return max(1, (int)ceil(mb_strlen($text, 'UTF-8') / 1.5));
    }

    /** @return array<string, mixed> */
    public static function resolveModel(int $tenantId, $selection, bool $requiresVision = false): array
    {
        $wanted = is_array($selection)
            ? (string)($selection['id'] ?? $selection['product_id'] ?? $selection['model_code'] ?? '')
            : (string)$selection;
        $wantedSkuId = is_array($selection)
            ? (int)($selection['market_sku_id'] ?? $selection['sku_id'] ?? $selection['market_input_sku_id'] ?? 0)
            : 0;
        $options = self::options($tenantId, $requiresVision);
        foreach ($options as $option) {
            if ($wantedSkuId > 0 && in_array($wantedSkuId, [(int)($option['market_sku_id'] ?? 0), (int)($option['market_input_sku_id'] ?? 0), (int)($option['market_output_sku_id'] ?? 0)], true)) {
                return $option;
            }
            if (in_array($wanted, [(string)$option['id'], (string)$option['product_id'], (string)$option['model_code'], 'market_text_' . (string)$option['product_id']], true)) {
                return $option;
            }
        }
        if ($wanted !== '' || $wantedSkuId > 0) {
            throw new Exception('所选文本模型未上架或已不可用');
        }
        if ($options === []) {
            throw new Exception($requiresVision ? '暂无可用的视觉文本模型' : '暂无可用的文本模型');
        }
        return $options[0];
    }

    /**
     * @param array<string, mixed> $params content, system_prompt, model_selection, action_code
     * @return array<string, mixed>
     */
    public static function generate(int $tenantId, int $userId, array $params, ?callable $onEvent = null): array
    {
        $content = trim((string)($params['content'] ?? ''));
        if ($content === '') {
            throw new Exception('请输入文本内容');
        }
        $referenceImages = array_values(array_filter(array_map('strval', (array)($params['reference_images'] ?? []))));
        $messages = self::normalizeMessages($content, $referenceImages, $params['messages'] ?? []);
        $model = self::resolveModel($tenantId, $params['model_selection'] ?? $params['model_id'] ?? '', $referenceImages !== [] || !empty($params['requires_vision']));
        $modelConfig = self::modelConfig($params);
        $maxTokens = self::resolveMaxTokens($model, $modelConfig);
        $generationParams = self::generationParams($model, $modelConfig);
        // Agent runtimes may supply OpenAI-compatible function schemas. These
        // are capability metadata, not provider credentials, and must reach
        // the selected market text channel unchanged.
        foreach (['tools', 'tool_choice', 'response_format'] as $key) {
            if (array_key_exists($key, $params) && $params[$key] !== null && $params[$key] !== '') {
                $generationParams[$key] = $params[$key];
            }
        }
        $action = (string)($params['action_code'] ?? $params['source_type'] ?? 'text_generate');
        $appCode = self::safeCode((string)($params['source_app_code'] ?? $params['app_code'] ?? 'aigc_short_drama'), 'aigc_short_drama');
        $businessTable = self::safeCode((string)($params['business_table'] ?? ($appCode === 'aigc_canvas' ? 'aigc_canvas_run' : 'aigc_short_drama_script_task')), $appCode === 'aigc_canvas' ? 'aigc_canvas_run' : 'aigc_short_drama_script_task');
        $businessId = (int)($params['business_id'] ?? 0);
        $requestSummary = [
            'content_length' => array_sum(array_map(static fn(array $message): int => self::messageContentLength($message['content'] ?? ''), $messages)),
            'message_count' => count($messages),
            'system_prompt_length' => mb_strlen((string)($params['system_prompt'] ?? ''), 'UTF-8'),
            'reference_image_count' => count($referenceImages),
            'model_code' => $model['model_code'],
            'generation_params' => $generationParams + ['max_tokens' => $maxTokens],
        ];
        // Token SKU amounts must come from the upstream usage record. Showing a
        // locally guessed total or reserving max_tokens makes the price wrong.
        $reserve = self::quote($model, 0, 0);
        $context = self::createConsumption($tenantId, $userId, $model, $action, $requestSummary, $reserve, (int)($params['parent_app_task_id'] ?? 0), $appCode, $businessTable, $businessId);
        if ($onEvent) {
            $onEvent('app_task', [
                'app_task_id' => (int)$context['appTask']['id'],
                'consumption_id' => (int)$context['consumption']['id'],
            ]);
        }

        $started = microtime(true);
        $requestTimeout = self::resolveRequestTimeout($params);
        try {
            self::event((int)$context['consumption']['id'], 'submit', 'running', ['model_code' => $model['model_code']]);
            $compatibilityAttempts = 0;
            while (true) {
                try {
                    $result = self::request($model, $messages, (string)($params['system_prompt'] ?? ''), $maxTokens, $generationParams, $onEvent, $requestTimeout);
                    break;
                } catch (\Throwable $initialError) {
                    $retry = self::compatibleGenerationParams($initialError->getMessage(), $generationParams);
                    if ($retry === null && self::isTransientProviderFailure($initialError->getMessage())) {
                        $retry = [
                            'params' => $generationParams,
                            'reason' => 'provider_temporarily_unavailable',
                            'removed_params' => [],
                            'temperature' => null,
                        ];
                    }
                    if ($retry === null || $compatibilityAttempts >= 2) {
                        throw $initialError;
                    }
                    $generationParams = $retry['params'];
                    $compatibilityAttempts++;
                    self::event((int)$context['consumption']['id'], 'retry', 'running', [
                        'reason' => $retry['reason'],
                        'removed_params' => $retry['removed_params'],
                        'temperature' => $retry['temperature'],
                    ]);
                    if ($retry['reason'] === 'provider_temporarily_unavailable') {
                        usleep($compatibilityAttempts * 500000);
                    }
                }
            }
            $usage = self::normalizeUsage((array)($result['usage'] ?? []));
            if (self::canSettleUsage($model, $usage) && (int)$usage['prompt_tokens'] <= 0 && (int)$usage['completion_tokens'] <= 0) {
                $usage['prompt_tokens'] = (int)$usage['total_tokens'];
            }
            $actual = self::quote($model, (int)$usage['prompt_tokens'], (int)$usage['completion_tokens']);
            self::settle($context, $actual, $usage, $result, (int)round((microtime(true) - $started) * 1000));
            return $result + [
                'model_code' => $model['model_code'],
                'channel_code' => $model['channel_code'],
                'provider' => 'power_market',
                'app_task_id' => (int)$context['appTask']['id'],
                'consumption_id' => (int)$context['consumption']['id'],
                'billing' => ['billing_status' => (self::canSettleUsage($model, $usage) ? 'settled' : 'pending_usage')] + $actual,
                'usage' => $usage,
            ];
        } catch (\Throwable $e) {
            self::fail($context, $e->getMessage(), 'provider_error');
            throw $e instanceof Exception ? $e : new Exception('文本模型调用失败，请稍后重试');
        }
    }

    public static function bindBusinessTask(int $appTaskId, string $table, int $businessId): void
    {
        if ($appTaskId > 0) {
            AiAppTask::where('id', $appTaskId)->update(['business_table' => $table, 'business_id' => $businessId, 'update_time' => time()]);
        }
    }

    /**
     * Releases reservations for an abandoned business task. This is idempotent
     * so stale-task recovery and a late provider failure cannot refund twice.
     */
    public static function failAppTask(int $appTaskId, string $message, string $code = 'abandoned'): void
    {
        if ($appTaskId <= 0) {
            return;
        }
        Db::transaction(function () use ($appTaskId, $message, $code) {
            $task = AiAppTask::lock(true)->findOrEmpty($appTaskId);
            if ($task->isEmpty()) {
                return;
            }
            $logs = AiConsumptionLog::where('app_task_id', $appTaskId)->lock(true)->select();
            foreach ($logs as $consumption) {
                if ((string)$consumption['billing_status'] !== 'reserved') {
                    continue;
                }
                PointService::releaseReservedBusinessAmountsInCurrentTransaction(
                    (int)$consumption['tenant_id'],
                    (int)$consumption['user_id'],
                    (float)$consumption['reserved_tenant_cost'],
                    (float)$consumption['reserved_user_price'],
                    (string)$consumption['consume_no'] . '-release',
                    '文本模型任务中断退回',
                    self::extra($task, $consumption, 'refunded')
                );
                $now = time();
                $consumption->save([
                    'run_status' => 'failed',
                    'billing_status' => 'refunded',
                    'error_code' => $code,
                    'error_message' => mb_substr($message, 0, 1000),
                    'finish_time' => $now,
                    'update_time' => $now,
                ]);
                self::event((int)$consumption['id'], 'refund', 'success', ['reason' => $message]);
            }
            $now = time();
            $task->save([
                'status' => 'failed',
                'progress' => 100,
                'result_summary' => ['error' => mb_substr($message, 0, 500)],
                'finish_time' => $now,
                'update_time' => $now,
            ]);
        });
    }

    /**
     * A browser connection can disappear after the provider has already sent a
     * complete response. When the business task recovers that response from its
     * stream snapshot, settle the still-reserved market consumption exactly once.
     */
    public static function settleRecoveredAppTask(int $appTaskId, string $content, string $providerRequestId = ''): void
    {
        if ($appTaskId <= 0 || trim($content) === '') {
            return;
        }
        Db::transaction(function () use ($appTaskId, $content, $providerRequestId) {
            $task = AiAppTask::lock(true)->findOrEmpty($appTaskId);
            if ($task->isEmpty()) {
                return;
            }
            $tenantTotal = 0.0;
            $userTotal = 0.0;
            $settledAny = false;
            foreach (AiConsumptionLog::where('app_task_id', $appTaskId)->lock(true)->select() as $consumption) {
                if (!in_array((string)$consumption['billing_status'], ['reserved', 'pending_usage'], true)) {
                    continue;
                }
                $usage = [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                    'settlement_basis' => 'awaiting_actual_usage',
                ];
                $now = time();
                $consumption->save([
                    'upstream_request_id' => $providerRequestId ?: (string)$consumption['upstream_request_id'],
                    'run_status' => 'success',
                    'billing_status' => 'pending_usage',
                    'usage_snapshot' => $usage,
                    'response_summary' => ['output_length' => mb_strlen($content, 'UTF-8'), 'recovered_from_stream' => true],
                    'finish_time' => $now,
                    'update_time' => $now,
                ]);
                $settledAny = true;
                self::event((int)$consumption['id'], 'await_usage', 'pending', $usage);
            }
            if (!$settledAny) {
                return;
            }
            $now = time();
            $task->save([
                'status' => 'success',
                'progress' => 100,
                'actual_tenant_cost' => (float)$task['actual_tenant_cost'] + $tenantTotal,
                'actual_user_price' => (float)$task['actual_user_price'] + $userTotal,
                'finish_time' => $now,
                'update_time' => $now,
            ]);
        });
    }

    /** @return array<string, mixed> */
    private static function group(int $tenantId, string $key, string $label, bool $vision): array
    {
        $options = self::options($tenantId, $vision);
        return [
            'key' => $key,
            'label' => $label,
            'app_code' => self::APP_CODE,
            'type' => 'llm',
            'description' => $vision ? '支持参考图理解与中文描述生成' : '通过算力市场文本模型生成剧本与分镜',
            'options' => $options,
            'default' => (string)($options[0]['id'] ?? ''),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function options(int $tenantId, bool $vision): array
    {
        $products = PowerMarketProduct::where(['resource_type' => PowerMarketService::TYPE_MODEL, 'model_type' => 'text', 'status' => 1])
            ->order(['update_time' => 'desc', 'id' => 'desc'])->select()->toArray();
        TenantPowerMarketService::applyProductDisplays($tenantId, $products);
        $options = [];
        foreach ($products as $product) {
            $snapshot = self::arrayValue($product['source_payload'] ?? []);
            if ($vision && !self::supportsVision($snapshot)) {
                continue;
            }
            $prices = self::marketPrices($tenantId, (int)$product['id']);
            if ($prices === []) {
                continue;
            }
            $meta = (array)($snapshot['market_metadata'] ?? []);
            $protocols = self::protocolList($meta['protocols'] ?? []);
            $options[] = [
                'id' => (string)$product['id'],
                'product_id' => (int)$product['id'],
                'resource_type' => PowerMarketService::TYPE_MODEL,
                'model_type' => 'text',
                'category_code' => 'text',
                'category_name' => '文本生成',
                'name' => (string)$product['name'],
                'description' => (string)$product['description'],
                'display_icon' => (string)($product['display_icon'] ?? ''),
                'model_code' => (string)$product['upstream_model_code'],
                'channel_code' => (string)$product['upstream_channel_code'],
                'provider_model' => (string)$product['upstream_model_code'],
                'protocol' => self::protocol((string)($meta['protocol'] ?? ''), $protocols, (string)$product['upstream_model_code']),
                'protocols' => $protocols,
                'max_tokens' => max(0, (int)($meta['max_tokens'] ?? 0)),
                'default_params' => self::arrayValue($meta['default_params'] ?? []),
                'params_schema' => self::arrayValue($meta['params_schema'] ?? []),
                'capabilities' => self::arrayValue($meta['capabilities'] ?? []),
                'developer_doc_slug' => (string)($meta['developer_doc_slug'] ?? ''),
                'api_doc' => (string)($meta['api_doc'] ?? ''),
                'supports_vision' => self::supportsVision($snapshot),
                'input' => $prices['input'],
                'output' => $prices['output'],
                'market_product_id' => (int)$product['id'],
                'market_sku_id' => (int)$prices['input']['sku_id'],
                'sku_id' => (int)$prices['input']['sku_id'],
                'market_input_sku_id' => (int)$prices['input']['sku_id'],
                'market_output_sku_id' => (int)$prices['output']['sku_id'],
                'market_input_sku_key' => (string)$prices['input']['sku_key'],
                'market_output_sku_key' => (string)$prices['output']['sku_key'],
                'price_source' => 'power_market_text_model',
                'billing_unit' => 'token',
                'billing_unit_size' => max(
                    MarketUsageSettlementService::unitSize($prices['input']),
                    MarketUsageSettlementService::unitSize($prices['output'])
                ),
                'settlement_mode' => 'actual_usage',
                'platform_unit_cost' => $prices['input']['platform_price'],
                'tenant_unit_price' => $prices['input']['tenant_price'],
                'platform_input_unit_cost' => $prices['input']['platform_price'],
                'platform_output_unit_cost' => $prices['output']['platform_price'],
                'tenant_input_unit_price' => $prices['input']['tenant_price'],
                'tenant_output_unit_price' => $prices['output']['tenant_price'],
                'enabled' => true,
                'sort' => (int)($product['id'] ?? 0),
            ];
        }
        return $options;
    }

    /** @return array<string, array<string, mixed>> */
    private static function marketPrices(int $tenantId, int $productId): array
    {
        $rows = PowerMarketSku::where(['product_id' => $productId, 'status' => 1, 'sale_status' => 1])->select()->toArray();
        $valid = [];
        foreach ($rows as $sku) {
            $tenant = TenantPowerMarketSkuPrice::where(['tenant_id' => $tenantId, 'sku_id' => (int)$sku['id']])->findOrEmpty();
            if (!$tenant->isEmpty() && (int)$tenant['sale_status'] !== 1) {
                continue;
            }
            $valid[] = [
                'sku_id' => (int)$sku['id'], 'sku_key' => (string)$sku['sku_key'], 'usage_unit' => (string)$sku['usage_unit'], 'usage_unit_size' => MarketUsageSettlementService::unitSize($sku),
                'upstream_price' => (float)$sku['upstream_price'], 'platform_price' => (float)$sku['sale_points'],
                'tenant_price' => $tenant->isEmpty() ? (float)$sku['sale_points'] : (float)$tenant['sale_points'],
            ];
        }
        if ($valid === []) return [];
        $input = $valid[0]; $output = $valid[0];
        foreach ($valid as $item) {
            $hint = strtolower($item['sku_key'] . ' ' . $item['usage_unit']);
            if (str_contains($hint, 'input') || str_contains($hint, 'prompt')) $input = $item;
            if (str_contains($hint, 'output') || str_contains($hint, 'completion')) $output = $item;
        }
        return compact('input', 'output');
    }

    private static function supportsVision(array $snapshot): bool
    {
        $meta = (array)($snapshot['market_metadata'] ?? []); $resource = (array)($snapshot['resource'] ?? []);
        foreach ([$meta['capabilities'] ?? [], $meta['input_modalities'] ?? [], $resource['capabilities'] ?? [], $resource['input_modalities'] ?? []] as $value) {
            foreach ((array)$value as $item) if (in_array(strtolower((string)$item), ['vision', 'image', 'image_input'], true)) return true;
        }
        return !empty($meta['supports_vision']) || !empty($resource['supports_vision']);
    }

    private static function quote(array $model, int $inputTokens, int $outputTokens): array
    {
        $input = (array)$model['input']; $output = (array)$model['output'];
        return [
            'tenant_cost_points' => self::points($inputTokens * (float)$input['platform_price'] / MarketUsageSettlementService::unitSize($input) + $outputTokens * (float)$output['platform_price'] / MarketUsageSettlementService::unitSize($output)),
            'user_charge_points' => self::points($inputTokens * (float)$input['tenant_price'] / MarketUsageSettlementService::unitSize($input) + $outputTokens * (float)$output['tenant_price'] / MarketUsageSettlementService::unitSize($output)),
            'prompt_tokens' => $inputTokens, 'completion_tokens' => $outputTokens,
        ];
    }

    /** @return array{app_task: AiAppTask, consumption: AiConsumptionLog} */
    private static function createConsumption(int $tenantId, int $userId, array $model, string $action, array $summary, array $reserve, int $parentTaskId, string $appCode, string $businessTable, int $businessId): array
    {
        return Db::transaction(function () use ($tenantId, $userId, $model, $action, $summary, $reserve, $parentTaskId, $appCode, $businessTable, $businessId) {
            $now = time();
            $appTask = $parentTaskId > 0 ? AiAppTask::lock(true)->findOrEmpty($parentTaskId) : AiAppTask::create([
                'task_no' => self::no('AT'), 'tenant_id' => $tenantId, 'user_id' => $userId, 'app_code' => $appCode,
                'action_code' => $action, 'business_table' => $businessTable, 'business_id' => $businessId,
                'parent_task_id' => 0, 'status' => 'running', 'progress' => 10, 'request_summary' => $summary, 'result_summary' => [],
                'estimated_tenant_cost' => $reserve['tenant_cost_points'], 'estimated_user_price' => $reserve['user_charge_points'],
                'actual_tenant_cost' => 0, 'actual_user_price' => 0, 'idempotency_key' => self::no('IK'), 'create_time' => $now, 'update_time' => $now, 'finish_time' => 0,
            ]);
            if ($appTask->isEmpty()) throw new Exception('应用任务不存在');
            $consumeNo = self::no('C');
            $consumption = AiConsumptionLog::create([
                'consume_no' => $consumeNo, 'app_task_id' => (int)$appTask['id'], 'tenant_id' => $tenantId, 'user_id' => $userId,
                'app_code' => $appCode, 'action_code' => $action, 'resource_type' => 'model', 'product_id' => (int)$model['product_id'],
                'sku_id' => (int)$model['input']['sku_id'], 'model_code' => (string)$model['model_code'], 'api_code' => (string)$model['channel_code'],
                'protocol' => (string)$model['protocol'], 'provider' => 'power_market', 'upstream_request_id' => '', 'upstream_task_id' => '',
                'quantity' => 0, 'usage_unit' => 'token', 'usage_snapshot' => ['settlement_basis' => 'awaiting_actual_usage'],
                'price_snapshot' => ['input' => $model['input'], 'output' => $model['output'], 'model_code' => $model['model_code'], 'usage_unit' => 'token', 'usage_unit_size' => (float)$model['billing_unit_size']],
                'request_summary' => $summary, 'response_summary' => [], 'run_status' => 'submitting', 'billing_status' => 'pending_usage',
                'reserved_tenant_cost' => 0, 'reserved_user_price' => 0,
                'actual_tenant_cost' => 0, 'actual_user_price' => 0, 'tenant_point_sn' => $consumeNo . '-reserve', 'user_point_sn' => $consumeNo . '-reserve',
                'error_code' => '', 'error_message' => '', 'refresh_requested_at' => 0, 'create_time' => $now, 'update_time' => $now, 'finish_time' => 0,
            ]);
            self::event((int)$consumption['id'], 'reserve', 'success', $reserve + ['settlement_mode' => 'actual_usage']);
            return compact('appTask', 'consumption');
        });
    }

    private static function settle(array $context, array $actual, array $usage, array $result, int $elapsed): void
    {
        Db::transaction(function () use ($context, $actual, $usage, $result, $elapsed) {
            $c = AiConsumptionLog::lock(true)->findOrEmpty((int)$context['consumption']['id']); if ($c->isEmpty() || $c['billing_status'] === 'settled') return;
            $task = AiAppTask::lock(true)->findOrEmpty((int)$c['app_task_id']); $now = time();
            $hasActualUsage = self::canSettleUsageFromSnapshot(self::arrayValue($c['price_snapshot'] ?? []), $usage);
            if ($hasActualUsage) {
                PointService::assertCanConsumeAmounts((int)$c['tenant_id'], (int)$c['user_id'], (float)$actual['tenant_cost_points'], (float)$actual['user_charge_points']);
                PointService::consumeBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$actual['tenant_cost_points'], (float)$actual['user_charge_points'], (string)$c['consume_no'], self::billingRemark((string)$c['app_code'], 'settled'), self::extra($task, $c, 'settled'));
            }
            $c->save(['upstream_request_id' => (string)($result['provider_request_id'] ?? ''), 'run_status' => 'success', 'billing_status' => $hasActualUsage ? 'settled' : 'pending_usage', 'usage_snapshot' => $usage + ['settlement_basis' => $hasActualUsage ? 'actual_usage' : 'awaiting_actual_usage'], 'response_summary' => ['output_length' => mb_strlen((string)($result['content'] ?? ''), 'UTF-8')], 'actual_tenant_cost' => $hasActualUsage ? $actual['tenant_cost_points'] : 0, 'actual_user_price' => $hasActualUsage ? $actual['user_charge_points'] : 0, 'tenant_point_sn' => $hasActualUsage ? (string)$c['consume_no'] : '', 'user_point_sn' => $hasActualUsage ? (string)$c['consume_no'] : '', 'finish_time' => $now, 'update_time' => $now]);
            $task->save(['status' => 'success', 'progress' => 100, 'actual_tenant_cost' => (float)$task['actual_tenant_cost'] + ($hasActualUsage ? (float)$actual['tenant_cost_points'] : 0), 'actual_user_price' => (float)$task['actual_user_price'] + ($hasActualUsage ? (float)$actual['user_charge_points'] : 0), 'finish_time' => $now, 'update_time' => $now]);
            self::event((int)$c['id'], $hasActualUsage ? 'settle' : 'await_usage', $hasActualUsage ? 'success' : 'pending', $usage, $elapsed);
        });
    }

    private static function fail(array $context, string $message, string $code): void
    {
        Db::transaction(function () use ($context, $message, $code) {
            $c = AiConsumptionLog::lock(true)->findOrEmpty((int)$context['consumption']['id']); if ($c->isEmpty() || in_array((string)$c['billing_status'], ['settled', 'refunded'], true)) return;
            $task = AiAppTask::lock(true)->findOrEmpty((int)$c['app_task_id']);
            PointService::releaseReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], (string)$c['consume_no'] . '-release', self::billingRemark((string)$c['app_code'], 'failed'), self::extra($task, $c, 'refunded'));
            $now = time(); $c->save(['run_status' => 'failed', 'billing_status' => 'refunded', 'error_code' => $code, 'error_message' => mb_substr($message, 0, 1000), 'finish_time' => $now, 'update_time' => $now]);
            $task->save(['status' => 'failed', 'progress' => 100, 'result_summary' => ['error' => mb_substr($message, 0, 500)], 'finish_time' => $now, 'update_time' => $now]); self::event((int)$c['id'], 'refund', 'success', ['reason' => $message]);
        });
    }

    /** @return array<string, mixed> */
    private static function request(array $model, array $messages, string $system, int $maxTokens, array $generationParams, ?callable $onEvent, int $requestTimeout): array
    {
        $source = UpdateSourceClient::getSource(); $base = self::sourceBaseUrl((string)($source['active_base_url'] ?? $source['base_url'] ?? '')); $key = (string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? '');
        $sslVerify = UpdateSourceClient::sslVerify($source);
        if ($base === '' || $key === '') throw new Exception('文本模型 API 暂不可用');
        $protocol = self::protocolForModel($model); $path = match ($protocol) { 'openai_responses' => '/api/v1/responses', 'anthropic_messages' => '/api/v1/messages', default => '/api/v1/chat/completions' };
        if ($messages === []) {
            throw new Exception('请输入文本内容');
        }
        $payload = self::requestPayload($protocol, $model, $messages, $system, $maxTokens, $generationParams);
        $channelCode = trim((string)($model['channel_code'] ?? ''));
        if ($channelCode !== '') {
            $payload['channel'] = $channelCode;
        }
        $payload = array_merge(self::marketContext($model), $payload);
        // Do this last so neither a model default nor an application override
        // can disable SSE for a market text request.
        $payload['stream'] = true;
        return self::curl($base . $path, $key, $payload, $onEvent, $sslVerify, $requestTimeout);
    }

    /** Build the provider-specific request body without leaking protocol details to business apps. */
    private static function requestPayload(string $protocol, array $model, array $messages, string $system, int $maxTokens, array $generationParams): array
    {
        if ($protocol === 'openai_responses') {
            return array_merge([
                'model' => $model['model_code'],
                'instructions' => $system,
                'input' => $messages,
                'max_output_tokens' => $maxTokens,
            ], self::protocolGenerationParams($protocol, $generationParams));
        }
        if ($protocol === 'anthropic_messages') {
            $payload = [
                'model' => $model['model_code'],
                'messages' => $messages,
                'max_tokens' => $maxTokens,
            ];
            if ($system !== '') {
                $payload['system'] = $system;
            }
            return array_merge($payload, self::protocolGenerationParams($protocol, $generationParams));
        }
        return array_merge([
            'model' => $model['model_code'],
            'messages' => array_merge($system === '' ? [] : [['role' => 'system', 'content' => $system]], $messages),
            'max_tokens' => $maxTokens,
        ], self::protocolGenerationParams($protocol, $generationParams));
    }

    /** Remove or translate options which are not valid for the selected protocol. */
    private static function protocolGenerationParams(string $protocol, array $generationParams): array
    {
        if ($protocol !== 'anthropic_messages') {
            if ($protocol !== 'openai_chat') {
                unset($generationParams['stream_options']);
            }
            return $generationParams;
        }

        // Claude Messages does not accept OpenAI-only penalties, response_format,
        // stream_options, or the application's boolean thinking switch.
        foreach (['stream_options', 'presence_penalty', 'frequency_penalty', 'response_format', 'enable_thinking'] as $key) {
            unset($generationParams[$key]);
        }
        // Anthropic accepts temperature or top_p, but not both in one request.
        if (array_key_exists('temperature', $generationParams)) {
            unset($generationParams['top_p']);
        }
        if (isset($generationParams['tools']) && is_array($generationParams['tools'])) {
            $generationParams['tools'] = self::anthropicTools($generationParams['tools']);
            if ($generationParams['tools'] === []) {
                unset($generationParams['tools'], $generationParams['tool_choice']);
            }
        }
        if (isset($generationParams['tool_choice'])) {
            $toolChoice = self::anthropicToolChoice($generationParams['tool_choice']);
            if ($toolChoice === []) {
                unset($generationParams['tool_choice']);
            } else {
                $generationParams['tool_choice'] = $toolChoice;
            }
        }
        return $generationParams;
    }

    /** @return array<int, array<string, mixed>> */
    private static function anthropicTools(array $tools): array
    {
        $result = [];
        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }
            $function = is_array($tool['function'] ?? null) ? $tool['function'] : $tool;
            $name = trim((string)($function['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $item = ['name' => $name, 'input_schema' => is_array($function['parameters'] ?? null) ? $function['parameters'] : ['type' => 'object', 'properties' => []]];
            $description = trim((string)($function['description'] ?? ''));
            if ($description !== '') {
                $item['description'] = $description;
            }
            $result[] = $item;
        }
        return $result;
    }

    /** @return array<string, string> */
    private static function anthropicToolChoice($value): array
    {
        if (is_string($value)) {
            return match (strtolower($value)) {
                'auto' => ['type' => 'auto'],
                'required', 'any' => ['type' => 'any'],
                default => [],
            };
        }
        if (!is_array($value)) {
            return [];
        }
        $function = is_array($value['function'] ?? null) ? $value['function'] : $value;
        $name = trim((string)($function['name'] ?? ''));
        return $name === '' ? [] : ['type' => 'tool', 'name' => $name];
    }

    /** @return array<int, array{role:string, content:mixed}> */
    private static function normalizeMessages(string $content, array $referenceImages, $value): array
    {
        $messages = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $role = strtolower(trim((string)($item['role'] ?? 'user')));
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            $messageContent = $item['content'] ?? '';
            if (is_string($messageContent) || is_numeric($messageContent)) {
                $messageContent = trim((string)$messageContent);
            }
            if ($messageContent === '' || $messageContent === []) {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $messageContent];
        }
        if ($messages !== []) {
            return $messages;
        }
        $messageContent = $referenceImages === []
            ? $content
            : array_merge([['type' => 'text', 'text' => $content]], array_map(static fn($url) => ['type' => 'image_url', 'image_url' => ['url' => $url]], $referenceImages));
        return [['role' => 'user', 'content' => $messageContent]];
    }

    private static function messageContentLength($content): int
    {
        return mb_strlen(self::messageText($content), 'UTF-8');
    }

    /** Extract textual message parts without counting structured media URLs. */
    private static function messageText($value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string)$value;
        }
        if (!is_array($value)) {
            return '';
        }
        if (array_key_exists('text', $value)) {
            return self::messageText($value['text']);
        }
        if (array_key_exists('content', $value)) {
            return self::messageText($value['content']);
        }

        $parts = [];
        foreach ($value as $key => $item) {
            if (is_int($key)) {
                $text = self::messageText($item);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }
        return implode('', $parts);
    }

    private static function resolveMaxTokens(array $model, array $overrides): int
    {
        $modelLimit = (int)($model['max_tokens'] ?? 0);
        $defaults = (array)($model['default_params'] ?? []);
        $default = (int)($defaults['max_tokens'] ?? 0);
        $requested = (int)($overrides['max_tokens'] ?? 0);
        $value = $requested > 0 ? $requested : ($default > 0 ? $default : self::DEFAULT_MAX_OUTPUT_TOKENS);
        if ($modelLimit > 0) {
            $value = min($value, $modelLimit);
        }
        return max(256, min(32768, $value));
    }

    private static function resolveRequestTimeout(array $params): int
    {
        $requested = (int)($params['request_timeout_seconds'] ?? 0);
        if ($requested <= 0) {
            return self::REQUEST_TIMEOUT_SECONDS;
        }
        return max(10, min(self::REQUEST_TIMEOUT_SECONDS, $requested));
    }

    /** @return array{params:array<string,mixed>, reason:string, removed_params:array<int,string>, temperature:?float}|null */
    private static function compatibleGenerationParams(string $message, array $generationParams): ?array
    {
        $temperature = self::requiredTemperature($message, $generationParams);
        if ($temperature !== null) {
            $params = $generationParams;
            $params['temperature'] = $temperature;
            return [
                'params' => $params,
                'reason' => 'provider_temperature_constraint',
                'removed_params' => [],
                'temperature' => $temperature,
            ];
        }

        $message = strtolower($message);
        if (!str_contains($message, 'unsupported')
            && !str_contains($message, 'unknown parameter')
            && !str_contains($message, 'invalid parameter')
            && !str_contains($message, 'not support')) {
            return null;
        }
        $parameterHints = [
            'enable_thinking' => ['enable_thinking', 'thinking'],
            'response_format' => ['response_format', 'json_object', 'json schema'],
            'tools' => ['tools', 'tool_calls', 'function calling', 'function_call'],
            'tool_choice' => ['tool_choice'],
        ];
        $params = $generationParams;
        $removed = [];
        foreach ($parameterHints as $parameter => $hints) {
            if (!array_key_exists($parameter, $params)) {
                continue;
            }
            foreach ($hints as $hint) {
                if (str_contains($message, $hint)) {
                    unset($params[$parameter]);
                    $removed[] = $parameter;
                    break;
                }
            }
        }
        if ($removed === []) {
            return null;
        }
        if (in_array('tools', $removed, true) && array_key_exists('tool_choice', $params)) {
            unset($params['tool_choice']);
            $removed[] = 'tool_choice';
        }
        return [
            'params' => $params,
            'reason' => 'provider_unsupported_optional_parameter',
            'removed_params' => array_values(array_unique($removed)),
            'temperature' => null,
        ];
    }

    private static function requiredTemperature(string $message, array $generationParams): ?float
    {
        if (!preg_match('/(?:only|must be)\s+([01](?:\.\d+)?)\s+is\s+allowed\s+for\s+this\s+model/i', $message, $matches)) {
            return null;
        }
        $temperature = (float)$matches[1];
        if ($temperature < 0 || $temperature > 2 || (isset($generationParams['temperature']) && abs((float)$generationParams['temperature'] - $temperature) < 0.000001)) {
            return null;
        }
        return $temperature;
    }

    private static function isTransientProviderFailure(string $message): bool
    {
        $message = strtolower($message);
        foreach ([
            'currently overloaded',
            'server overloaded',
            'service unavailable',
            'temporarily unavailable',
            'too many requests',
            'rate limit',
            'http 429',
            'http 503',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function safeCode(string $value, string $fallback): string
    {
        $value = trim($value);
        return preg_match('/^[a-z][a-z0-9_]{1,63}$/', $value) ? $value : $fallback;
    }

    private static function billingRemark(string $appCode, string $status): string
    {
        $appName = match ($appCode) {
            'aigc_canvas' => 'infinite_canvas',
            'aigc_llm' => 'aigc_chat',
            default => 'short_drama',
        };
        return $appName . '_text_model_' . ($status === 'failed' ? 'refund' : 'settle');
    }

    /** @return array<string, mixed> */
    private static function modelConfig(array $params): array
    {
        $config = (array)($params['model_config'] ?? []);
        foreach (['max_tokens', 'temperature', 'top_p', 'presence_penalty', 'frequency_penalty', 'enable_thinking'] as $key) {
            if (array_key_exists($key, $params)) {
                $config[$key] = $params[$key];
            }
        }
        if (self::validResponseFormat($params['response_format'] ?? null)) {
            $config['response_format'] = $params['response_format'];
        }
        if (!empty($params['tools']) && is_array($params['tools'])) {
            $config['tools'] = array_values($params['tools']);
        }
        if (isset($params['tool_choice']) && (is_string($params['tool_choice']) || is_array($params['tool_choice']))) {
            $config['tool_choice'] = $params['tool_choice'];
        }
        return $config;
    }

    /**
     * Only forward known generation defaults declared by the selected market model.
     * This keeps provider-specific options isolated to models that actually expose them.
     */
    private static function generationParams(array $model, array $overrides): array
    {
        $defaults = self::arrayValue($model['default_params'] ?? []);
        $allowed = array_fill_keys(self::declaredGenerationParamKeys($model, $defaults), true);
        $result = [];
        foreach ($defaults as $key => $value) {
            $key = (string)$key;
            if (!isset($allowed[$key]) || !self::isGenerationParamValue($value)) {
                continue;
            }
            $result[$key] = $value;
        }
        foreach ($overrides as $key => $value) {
            $key = (string)$key;
            if (!isset($allowed[$key]) || !self::isGenerationParamValue($value)) {
                continue;
            }
            if ($key === 'response_format' && !self::validResponseFormat($value)) {
                continue;
            }
            $result[$key] = $key === 'tools' && is_array($value) ? array_values($value) : $value;
        }
        return $result;
    }

    /** @return array<int,string> */
    private static function declaredGenerationParamKeys(array $model, array $defaults): array
    {
        $excluded = array_fill_keys([
            'model', 'messages', 'prompt', 'input', 'content', 'system', 'system_prompt',
            'max_tokens', 'callback_url', 'callback', 'idempotency_key',
            'market_product_id', 'market_sku_id', 'sku_id'
        ], true);
        $keys = [];
        foreach (array_keys($defaults) as $key) {
            $key = (string)$key;
            if (!isset($excluded[$key])) {
                $keys[] = $key;
            }
        }
        $schema = self::arrayValue($model['params_schema'] ?? []);
        $properties = self::arrayValue($schema['properties'] ?? []);
        foreach (array_keys($properties !== [] ? $properties : $schema) as $key) {
            $key = (string)$key;
            if (!isset($excluded[$key])) {
                $keys[] = $key;
            }
        }
        foreach (['tools', 'tool_choice', 'response_format'] as $key) {
            if (isset($defaults[$key]) || array_key_exists($key, $properties) || array_key_exists($key, $schema)) {
                $keys[] = $key;
            }
        }
        return array_values(array_unique($keys));
    }

    private static function isGenerationParamValue($value): bool
    {
        return $value !== null && $value !== '' && (is_scalar($value) || is_array($value));
    }

    private static function validResponseFormat($value): bool
    {
        if (!is_array($value) || empty($value['type']) || !is_string($value['type'])) {
            return false;
        }
        if ($value['type'] === 'json_schema' && isset($value['json_schema']) && !is_array($value['json_schema'])) {
            return false;
        }
        return true;
    }

    private static function curl(string $url, string $key, array $payload, ?callable $onEvent, bool $sslVerify, int $requestTimeout): array
    {
        // Upstream text generation is always SSE. Callers without a UI stream
        // still consume SSE here and receive the assembled result afterwards.
        $onEvent = $onEvent ?? static function (string $event, array $data): void {
        };
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
            'Accept: text/event-stream',
        ];

        $state = ['content' => '', 'reasoning' => '', 'usage' => [], 'request_id' => '', 'emitted_request_id' => '', 'error' => '', 'tool_calls' => [], 'delta_count' => 0];
        $buffer = '';
        $body = '';
        $receivedEvent = false;
        $receivedSseEvent = false;
        $startedAt = microtime(true);
        $lastHeartbeatAt = $startedAt;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($curl, $downloadTotal, $downloadNow, $uploadTotal, $uploadNow) use ($onEvent, $startedAt, &$lastHeartbeatAt): int {
                $now = microtime(true);
                if ($now - $lastHeartbeatAt >= 5) {
                    $lastHeartbeatAt = $now;
                    $onEvent('heartbeat', ['elapsed_ms' => (int)round(($now - $startedAt) * 1000)]);
                }
                return 0;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$buffer, &$body, &$receivedEvent, &$receivedSseEvent, &$state, $onEvent): int {
                $body .= $chunk;
                $buffer .= str_replace("\r\n", "\n", $chunk);
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $block = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    $event = self::parseSseBlock($block);
                    if ($event !== null) {
                        $receivedEvent = true;
                        $receivedSseEvent = true;
                        self::applyStreamEvent($event, $state, $onEvent);
                    }
                }
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);

        if ($buffer !== '') {
            $event = self::parseSseBlock($buffer);
            if ($event !== null) {
                $receivedEvent = true;
                $receivedSseEvent = true;
                self::applyStreamEvent($event, $state, $onEvent);
            }
        }
        if (!$receivedEvent) {
            $event = self::parseJsonResponse($body, true);
            // A successful buffered JSON response violates the streaming
            // contract. Only decode it to retain a provider error message.
            if (($event['type'] ?? '') === 'error') {
                self::applyStreamEvent($event, $state, $onEvent);
            }
        }

        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new Exception(self::networkError($error));
        }
        if ($status >= 400) {
            if (UpstreamErrorMessageService::isClaudeClientRestricted($body)) {
                $response = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? $body);
                Log::write('Market text model rejected by Claude client policy: http=' . $status . ' response=' . mb_substr($response, 0, 1500, 'UTF-8'));
            }
            throw new Exception(self::providerError($body) ?: '文本模型调用失败（HTTP ' . $status . '）');
        }
        if ($state['error'] !== '') {
            throw new Exception($state['error']);
        }
        // Some OpenAI-compatible reasoning models emit their only textual output
        // under reasoning_content. Preserve it as a last-resort response instead
        // of treating a completed provider request as an empty result.
        if (trim((string)$state['content']) === '' && trim((string)$state['reasoning']) !== '') {
            $state['content'] = $state['reasoning'];
        }
        if (!$receivedEvent || (trim((string)$state['content']) === '' && empty($state['tool_calls']))) {
            throw new Exception('文本模型未返回有效内容');
        }
        return [
            'content' => $state['content'],
            'usage' => $state['usage'],
            'provider_request_id' => $state['request_id'],
            'tool_calls' => $state['tool_calls'],
            'stream_mode' => $receivedSseEvent ? 'sse' : 'buffered_response',
            'stream_delta_count' => (int)$state['delta_count'],
        ];
    }

    /** @return array<string, mixed> */
    private static function requestJson(string $url, array $payload, array $headers, bool $sslVerify, int $requestTimeout): array
    {
        $body = '';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $sslVerify,
            CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $body = is_string($response) ? $response : '';
        if ($errno) {
            throw new Exception(self::networkError($error));
        }
        if ($status >= 400) {
            throw new Exception(self::providerError($body) ?: '文本模型调用失败（HTTP ' . $status . '）');
        }
        $event = self::parseJsonResponse($body, false);
        if ($event === null || ($event['type'] ?? '') === 'error') {
            throw new Exception((string)($event['message'] ?? '文本模型返回格式异常'));
        }
        $content = (string)($event['content'] ?? '');
        $toolCalls = (array)($event['tool_calls'] ?? []);
        if (trim($content) === '' && empty($toolCalls)) {
            throw new Exception('文本模型未返回有效内容');
        }
        return [
            'content' => $content,
            'usage' => (array)($event['usage'] ?? []),
            'provider_request_id' => (string)($event['provider_request_id'] ?? ''),
            'tool_calls' => $toolCalls,
        ];
    }

    private static function networkError(string $error): string
    {
        $error = trim($error);
        $lower = strtolower($error);
        if (str_contains($lower, 'certificate key usage') || str_contains($lower, 'certificate verify failed') || str_contains($lower, 'ssl certificate')) {
            return '接口渠道 SSL 证书校验失败，请在系统服务 > 接口渠道关闭 SSL 校验，或更换符合规范的 HTTPS 证书';
        }
        return '文本模型网络请求失败：' . mb_substr($error ?: '连接异常', 0, 120);
    }

    /** @return array<string, mixed>|null */
    private static function parseSseBlock(string $block): ?array
    {
        $eventName = '';
        $data = [];
        foreach (preg_split('/\r?\n/', trim($block)) ?: [] as $line) {
            if (str_starts_with($line, 'event:')) {
                $eventName = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data[] = trim(substr($line, 5));
            }
        }
        $payload = trim(implode("\n", $data));
        if ($payload === '') {
            return null;
        }
        if ($payload === '[DONE]') {
            return ['type' => 'done'];
        }
        $json = json_decode($payload, true);
        return is_array($json) ? self::parseProviderPayload($json, $eventName, true) : null;
    }

    /** @return array<string, mixed>|null */
    private static function parseJsonResponse(string $body, bool $stream): ?array
    {
        $json = json_decode(trim($body), true);
        if (is_array($json['data'] ?? null)) {
            $json = $json['data'];
        }
        return is_array($json) ? self::parseProviderPayload($json, '', $stream) : null;
    }

    /** @param array<string, mixed> $state */
    private static function applyStreamEvent(array $event, array &$state, callable $onEvent): void
    {
        $requestId = trim((string)($event['provider_request_id'] ?? ''));
        if ($requestId !== '') {
            $state['request_id'] = $requestId;
            if ($requestId !== $state['emitted_request_id']) {
                $state['emitted_request_id'] = $requestId;
                $onEvent('provider_request', ['provider_request_id' => $requestId]);
            }
        }
        if (($event['type'] ?? '') === 'error') {
            $state['error'] = (string)($event['message'] ?? '文本模型调用失败');
            return;
        }
        if (!empty($event['usage']) && is_array($event['usage'])) {
            $state['usage'] = $event['usage'];
        }
        if (!empty($event['tool_calls']) && is_array($event['tool_calls'])) {
            $state['tool_calls'] = self::mergeToolCalls((array)$state['tool_calls'], $event['tool_calls']);
            $onEvent('tool_calls', ['tool_calls' => $state['tool_calls']]);
        }
        if (($event['type'] ?? '') !== 'delta') {
            return;
        }
        $delta = (string)($event['content'] ?? '');
        if ($delta === '') {
            return;
        }
        if (!empty($event['reasoning'])) {
            $state['reasoning'] .= $delta;
        } else {
            $state['content'] .= $delta;
        }
        $state['delta_count'] = (int)($state['delta_count'] ?? 0) + 1;
        if (empty($event['reasoning'])) {
            $onEvent('delta', ['delta' => $delta]);
        }
    }

    /** @return array<string, mixed>|null */
    private static function parseProviderPayload(array $json, string $eventName = '', bool $stream = false): ?array
    {
        if (isset($json['code']) && is_numeric($json['code']) && empty($json['choices']) && empty($json['output'])) {
            $providerMessage = self::providerError($json);
            if ((int)$json['code'] !== 0 || $providerMessage !== '' || array_key_exists('data', $json)) {
                return ['type' => 'error', 'message' => $providerMessage ?: 'Provider request failed'];
            }
        }
        if (isset($json['error'])) {
            return ['type' => 'error', 'message' => self::providerError($json) ?: '文本模型调用失败'];
        }
        $eventType = strtolower((string)($json['type'] ?? $json['event'] ?? $eventName));
        if (in_array($eventType, ['error', 'failed', 'fail', 'response.failed', 'response.incomplete'], true)) {
            return ['type' => 'error', 'message' => self::providerError($json) ?: '文本模型调用失败'];
        }
        foreach (['data', 'result', 'output', 'response'] as $key) {
            if (!empty($json[$key]) && is_array($json[$key]) && self::looksLikePayload($json[$key])) {
                $event = self::parseProviderPayload($json[$key], $eventName, $stream);
                if ($event !== null) {
                    if (empty($event['provider_request_id'])) {
                        $event['provider_request_id'] = self::providerRequestId($json);
                    }
                    return $event;
                }
            }
        }

        $requestId = self::providerRequestId($json);
        $usage = self::usageFromPayload($json);
        $choice = is_array($json['choices'][0] ?? null) ? $json['choices'][0] : [];
        $output = is_array($json['output'][0] ?? null) ? $json['output'][0] : [];
        $outputContent = is_array($output['content'][0] ?? null) ? $output['content'][0] : [];
        $delta = self::extractText([
            $choice['delta']['content'] ?? null,
            $choice['message']['content'] ?? null,
            $choice['text'] ?? null,
            $json['delta']['text'] ?? null,
            $json['delta']['content'] ?? null,
            $json['delta'] ?? null,
            $json['content'] ?? null,
            $json['output_text'] ?? null,
            $json['text'] ?? null,
            $json['answer'] ?? null,
            $json['message']['content'] ?? null,
            $json['result']['content'] ?? null,
            $json['result']['text'] ?? null,
            $stream ? null : ($outputContent['text'] ?? $outputContent['content'] ?? null),
            $stream ? null : ($json['response']['output_text'] ?? $json['response']['output'] ?? null),
        ]);
        $reasoning = $delta === '' ? self::extractText([
            $choice['delta']['reasoning_content'] ?? null,
            $choice['message']['reasoning_content'] ?? null,
            $choice['delta']['reasoning'] ?? null,
            $choice['message']['reasoning'] ?? null,
            $json['reasoning_content'] ?? null,
            $json['reasoning'] ?? null,
        ]) : '';
        $toolCalls = self::toolCallsFromPayload($json, $choice, $output);
        if ($delta !== '') {
            return ['type' => 'delta', 'content' => $delta, 'usage' => $usage, 'provider_request_id' => $requestId, 'tool_calls' => $toolCalls];
        }
        if ($reasoning !== '') {
            return ['type' => 'delta', 'content' => $reasoning, 'reasoning' => true, 'usage' => $usage, 'provider_request_id' => $requestId, 'tool_calls' => $toolCalls];
        }
        if (!empty($toolCalls)) {
            return ['type' => 'tool_calls', 'tool_calls' => $toolCalls, 'usage' => $usage, 'provider_request_id' => $requestId];
        }
        $terminal = !empty($choice['finish_reason']) || in_array($eventType, [
            'done', 'finish', 'finished', 'complete', 'completed', 'message_stop', 'response.completed', 'response.failed',
        ], true);
        if ($terminal) {
            return ['type' => 'done', 'finish_reason' => (string)($choice['finish_reason'] ?? $json['finish_reason'] ?? 'stop'), 'usage' => $usage, 'provider_request_id' => $requestId];
        }
        if ($usage !== []) {
            return ['type' => 'usage', 'usage' => $usage, 'provider_request_id' => $requestId];
        }
        return null;
    }

    /** @return array<int, array<string, mixed>> */
    private static function toolCallsFromPayload(array $json, array $choice, array $output): array
    {
        $calls = $choice['delta']['tool_calls'] ?? $choice['message']['tool_calls'] ?? $choice['tool_calls'] ?? $json['tool_calls'] ?? [];
        if (!is_array($calls)) {
            $calls = [];
        }
        // Responses API represents a function call as an output item instead.
        if ($calls === [] && (($output['type'] ?? '') === 'function_call' || !empty($output['name']))) {
            $calls[] = [
                'id' => (string)($output['call_id'] ?? $output['id'] ?? ''),
                'function' => [
                    'name' => (string)($output['name'] ?? ''),
                    'arguments' => $output['arguments'] ?? '{}',
                ],
            ];
        }
        return array_values(array_filter($calls, static fn($call): bool => is_array($call)));
    }

    /** @return array<int, array<string, mixed>> */
    private static function mergeToolCalls(array $current, array $incoming): array
    {
        foreach ($incoming as $position => $call) {
            if (!is_array($call)) {
                continue;
            }
            $index = (int)($call['index'] ?? $position);
            $existing = is_array($current[$index] ?? null) ? $current[$index] : [];
            $existingFunction = is_array($existing['function'] ?? null) ? $existing['function'] : [];
            $function = is_array($call['function'] ?? null) ? $call['function'] : [];
            if (isset($function['arguments'])) {
                $existingFunction['arguments'] = (string)($existingFunction['arguments'] ?? '') . (string)$function['arguments'];
            }
            foreach (['name'] as $key) {
                if (!empty($function[$key])) {
                    $existingFunction[$key] = $function[$key];
                }
            }
            $current[$index] = array_merge($existing, $call, ['function' => $existingFunction]);
        }
        ksort($current);
        return array_values($current);
    }

    private static function looksLikePayload(array $payload): bool
    {
        foreach (['choices', 'delta', 'content', 'text', 'answer', 'response', 'message', 'usage', 'output', 'reasoning', 'reasoning_content', 'error'] as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }
        return false;
    }

    private static function providerRequestId(array $json): string
    {
        $response = is_array($json['response'] ?? null) ? $json['response'] : [];
        $message = is_array($json['message'] ?? null) ? $json['message'] : [];
        return (string)($json['id'] ?? $json['request_id'] ?? $response['id'] ?? $message['id'] ?? '');
    }

    /** @return array<string, mixed> */
    private static function usageFromPayload(array $json): array
    {
        $response = is_array($json['response'] ?? null) ? $json['response'] : [];
        $message = is_array($json['message'] ?? null) ? $json['message'] : [];
        $usage = $json['usage'] ?? $response['usage'] ?? $message['usage'] ?? [];
        return is_array($usage) ? $usage : [];
    }

    private static function extractText(array $values): string
    {
        foreach ($values as $value) {
            $text = self::stringifyText($value);
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    private static function stringifyText($value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return (string)$value;
        }
        if (!is_array($value)) {
            return '';
        }
        foreach (['text', 'content', 'value'] as $key) {
            if (array_key_exists($key, $value)) {
                return self::stringifyText($value[$key]);
            }
        }
        $parts = [];
        foreach ($value as $item) {
            $text = self::stringifyText($item);
            if ($text !== '') {
                $parts[] = $text;
            }
        }
        return implode('', $parts);
    }

    private static function providerError($payload): string
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : $payload;
        }
        if (is_array($payload)) {
            return UpstreamErrorMessageService::fromResponse($payload, '');
        }
        return self::friendlyError(trim(strip_tags((string)$payload)));
    }

    private static function friendlyError(string $message): string
    {
        $message = trim($message);
        if ($message !== '' && str_contains($message, 'SKU') && str_contains($message, '定价') && str_contains($message, '未配置')) {
            return '算力市场文本模型 SKU 定价未配置，请先同步/上架文本模型 SKU 并确认租户售价后再生成';
        }
        return UpstreamErrorMessageService::normalize($message);
    }

    private static function marketContext(array $model): array
    {
        $input = (array)($model['input'] ?? []);
        $output = (array)($model['output'] ?? []);
        $inputSkuId = (int)($input['sku_id'] ?? 0);
        $outputSkuId = (int)($output['sku_id'] ?? 0);
        $inputSkuKey = trim((string)($input['sku_key'] ?? ''));
        $outputSkuKey = trim((string)($output['sku_key'] ?? ''));
        return array_filter([
            'market_product_id' => (int)($model['product_id'] ?? 0),
            'market_sku_id' => $inputSkuId,
            'sku_id' => $inputSkuId,
            'market_sku_key' => $inputSkuKey,
            'sku_key' => $inputSkuKey,
            'pricing_sku_key' => $inputSkuKey,
            'market_input_sku_id' => $inputSkuId,
            'input_sku_id' => $inputSkuId,
            'market_input_sku_key' => $inputSkuKey,
            'input_sku_key' => $inputSkuKey,
            'input_pricing_sku_key' => $inputSkuKey,
            'market_output_sku_id' => $outputSkuId,
            'output_sku_id' => $outputSkuId,
            'market_output_sku_key' => $outputSkuKey,
            'output_sku_key' => $outputSkuKey,
            'output_pricing_sku_key' => $outputSkuKey,
            'price_source' => 'power_market_text_model',
        ], static fn($value) => $value !== '' && $value !== 0 && $value !== null);
    }

    private static function normalizeUsage(array $usage): array
    {
        $prompt = (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0); $completion = (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        return ['prompt_tokens' => max(0, $prompt), 'completion_tokens' => max(0, $completion), 'total_tokens' => max(0, (int)($usage['total_tokens'] ?? $usage['totalTokens'] ?? ($prompt + $completion))), 'provider_reported' => $usage !== []];
    }

    private static function canSettleUsage(array $model, array $usage): bool
    {
        return self::canSettleUsageFromSnapshot([
            'input' => (array)($model['input'] ?? []),
            'output' => (array)($model['output'] ?? []),
        ], $usage);
    }

    private static function canSettleUsageFromSnapshot(array $snapshot, array $usage): bool
    {
        $prompt = (int)($usage['prompt_tokens'] ?? 0);
        $completion = (int)($usage['completion_tokens'] ?? 0);
        if ($prompt > 0 || $completion > 0) {
            return true;
        }
        $total = (int)($usage['total_tokens'] ?? 0);
        if ($total <= 0) {
            return false;
        }
        $input = (array)($snapshot['input'] ?? []);
        $output = (array)($snapshot['output'] ?? []);
        return (float)($input['platform_price'] ?? 0) === (float)($output['platform_price'] ?? 0)
            && (float)($input['tenant_price'] ?? 0) === (float)($output['tenant_price'] ?? 0);
    }

    private static function protocol(string $default, array $protocols, string $modelCode = ''): string
    {
        $modelCode = strtolower(trim($modelCode));
        // Marketplace metadata can lag behind a product migration. Claude and
        // Anthropic models must never be sent through Chat Completions.
        if (preg_match('/(^|\s)(claude|anthropic)([^a-z0-9]|$)/', $modelCode) === 1) {
            return 'anthropic_messages';
        }
        $protocols = self::protocolList($protocols);
        $defaults = self::protocolList($default);
        $value = $defaults[0] ?? '';
        if ($value !== '' && ($protocols === [] || in_array($value, $protocols, true))) {
            return match ($value) {
                'openai_responses' => 'openai_responses',
                'anthropic_messages' => 'anthropic_messages',
                default => 'openai_chat',
            };
        }
        if (preg_match('/^gpt-5(?:[._-]|$)/i', $modelCode) && ($protocols === [] || in_array('openai_responses', $protocols, true))) {
            return 'openai_responses';
        }
        foreach (['openai_chat', 'openai_responses', 'openai_completions', 'anthropic_messages'] as $protocol) {
            if (in_array($protocol, $protocols, true)) {
                return $protocol;
            }
        }
        return $value !== '' ? $value : 'openai_chat';
    }

    private static function protocolForModel(array $model): string
    {
        $identity = implode(' ', [
            (string)($model['model_code'] ?? ''),
            (string)($model['provider_model'] ?? ''),
            (string)($model['channel_code'] ?? ''),
            (string)($model['name'] ?? ''),
        ]);
        return self::protocol((string)($model['protocol'] ?? ''), self::protocolList($model['protocols'] ?? []), $identity);
    }
    /** @return array<int,string> */
    private static function protocolList($value): array
    {
        $aliases = [
            'responses' => 'openai_responses',
            'openai_responses' => 'openai_responses',
            'messages' => 'anthropic_messages',
            'anthropic' => 'anthropic_messages',
            'anthropic_messages' => 'anthropic_messages',
            'chat' => 'openai_chat',
            'openai_chat' => 'openai_chat',
            'completions' => 'openai_completions',
            'openai_completions' => 'openai_completions',
        ];
        $items = [];
        foreach (is_array($value) ? $value : [$value] as $item) {
            foreach (preg_split('/[,|\s]+/', strtolower(trim((string)$item)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $protocol) {
                $normalized = $aliases[$protocol] ?? '';
                if ($normalized !== '') {
                    $items[] = $normalized;
                }
            }
        }
        return array_values(array_unique($items));
    }
    private static function sourceBaseUrl(string $baseUrl): string { $parts = parse_url(trim($baseUrl)); if (!is_array($parts) || empty($parts['host'])) return rtrim($baseUrl, '/'); return (string)($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int)$parts['port'] : ''); }
    private static function arrayValue($value): array { if (is_array($value)) return $value; $decoded = is_string($value) ? json_decode($value, true) : []; return is_array($decoded) ? $decoded : []; }
    private static function points($value): float { return round(max(0, (float)$value), 6); }
    private static function no(string $prefix): string { return $prefix . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)); }
    private static function extra(AiAppTask $task, AiConsumptionLog $consumption, string $stage): array { return ['app_code' => (string)$task['app_code'], 'task_id' => (int)$task['id'], 'app_task_no' => (string)$task['task_no'], 'consumption_id' => (int)$consumption['id'], 'consume_no' => (string)$consumption['consume_no'], 'billing_stage' => $stage]; }
    private static function event(int $id, string $type, string $status, array $payload, int $elapsed = 0): void { AiConsumptionEvent::create(['consumption_id' => $id, 'event_type' => $type, 'event_status' => $status, 'attempt_no' => 1, 'payload_summary' => $payload, 'payload_ciphertext' => '', 'http_status' => 0, 'elapsed_ms' => $elapsed, 'create_time' => time()]); }
}
