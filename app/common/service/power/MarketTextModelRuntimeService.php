<?php

namespace app\common\service\power;

use app\common\model\ai\AiAppTask;
use app\common\model\ai\AiConsumptionEvent;
use app\common\model\ai\AiConsumptionLog;
use app\common\model\power\PowerMarketProduct;
use app\common\model\power\PowerMarketSku;
use app\common\model\power\TenantPowerMarketSkuPrice;
use app\common\service\point\PointService;
use app\common\service\update\UpdateSourceClient;
use Exception;
use think\facade\Db;

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
        $options = self::options($tenantId, $requiresVision);
        foreach ($options as $option) {
            if (in_array($wanted, [(string)$option['id'], (string)$option['product_id'], (string)$option['model_code']], true)) {
                return $option;
            }
        }
        if ($wanted !== '') {
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
        $model = self::resolveModel($tenantId, $params['model_selection'] ?? $params['model_id'] ?? '', $referenceImages !== [] || !empty($params['requires_vision']));
        $maxTokens = self::resolveMaxTokens($model, (array)($params['model_config'] ?? []));
        $generationParams = self::generationParams($model, (array)($params['model_config'] ?? []));
        $action = (string)($params['action_code'] ?? $params['source_type'] ?? 'text_generate');
        $requestSummary = [
            'content_length' => mb_strlen($content, 'UTF-8'),
            'system_prompt_length' => mb_strlen((string)($params['system_prompt'] ?? ''), 'UTF-8'),
            'reference_image_count' => count($referenceImages),
            'model_code' => $model['model_code'],
            'generation_params' => $generationParams + ['max_tokens' => $maxTokens],
        ];
        $estimatedInput = self::estimateTokens($content . "\n" . (string)($params['system_prompt'] ?? ''));
        $reserve = self::quote($model, $estimatedInput, $maxTokens);
        $context = self::createConsumption($tenantId, $userId, $model, $action, $requestSummary, $reserve, (int)($params['parent_app_task_id'] ?? 0));
        if ($onEvent) {
            $onEvent('app_task', [
                'app_task_id' => (int)$context['appTask']['id'],
                'consumption_id' => (int)$context['consumption']['id'],
            ]);
        }

        $started = microtime(true);
        try {
            self::event((int)$context['consumption']['id'], 'submit', 'running', ['model_code' => $model['model_code']]);
            $result = self::request($model, $content, (string)($params['system_prompt'] ?? ''), $referenceImages, $maxTokens, $generationParams, $onEvent);
            $usage = self::normalizeUsage((array)($result['usage'] ?? []), $content, (string)($result['content'] ?? ''));
            $actual = self::quote($model, (int)$usage['prompt_tokens'], (int)$usage['completion_tokens']);
            self::settle($context, $actual, $usage, $result, (int)round((microtime(true) - $started) * 1000));
            return $result + [
                'model_code' => $model['model_code'],
                'channel_code' => $model['channel_code'],
                'provider' => 'power_market',
                'app_task_id' => (int)$context['appTask']['id'],
                'consumption_id' => (int)$context['consumption']['id'],
                'billing' => ['billing_status' => 'settled'] + $actual,
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
                if ((string)$consumption['billing_status'] !== 'reserved') {
                    continue;
                }
                $summary = (array)($consumption['request_summary'] ?? []);
                $prices = (array)($consumption['price_snapshot'] ?? []);
                $inputTokens = self::estimateTokens(str_repeat('x', max(1, (int)($summary['content_length'] ?? 0) + (int)($summary['system_prompt_length'] ?? 0))));
                $usage = [
                    'prompt_tokens' => $inputTokens,
                    'completion_tokens' => self::estimateTokens($content),
                    'total_tokens' => $inputTokens + self::estimateTokens($content),
                    'estimated' => true,
                ];
                $actual = self::quote([
                    'input' => (array)($prices['input'] ?? []),
                    'output' => (array)($prices['output'] ?? []),
                ], $usage['prompt_tokens'], $usage['completion_tokens']);
                PointService::settleReservedBusinessAmountsInCurrentTransaction(
                    (int)$consumption['tenant_id'],
                    (int)$consumption['user_id'],
                    (float)$consumption['reserved_tenant_cost'],
                    (float)$consumption['reserved_user_price'],
                    (float)$actual['tenant_cost_points'],
                    (float)$actual['user_charge_points'],
                    (string)$consumption['consume_no'],
                    '短剧文本模型流恢复结算',
                    self::extra($task, $consumption, 'settled')
                );
                $now = time();
                $consumption->save([
                    'upstream_request_id' => $providerRequestId ?: (string)$consumption['upstream_request_id'],
                    'run_status' => 'success',
                    'billing_status' => 'settled',
                    'usage_snapshot' => $usage,
                    'response_summary' => ['output_length' => mb_strlen($content, 'UTF-8'), 'recovered_from_stream' => true],
                    'actual_tenant_cost' => $actual['tenant_cost_points'],
                    'actual_user_price' => $actual['user_charge_points'],
                    'tenant_point_sn' => (string)$consumption['consume_no'],
                    'user_point_sn' => (string)$consumption['consume_no'],
                    'finish_time' => $now,
                    'update_time' => $now,
                ]);
                $tenantTotal += (float)$actual['tenant_cost_points'];
                $userTotal += (float)$actual['user_charge_points'];
                $settledAny = true;
                self::event((int)$consumption['id'], 'settle_recovered', 'success', $usage);
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
            $protocols = array_values(array_filter(array_map('strval', (array)($meta['protocols'] ?? []))));
            $options[] = [
                'id' => (string)$product['id'],
                'product_id' => (int)$product['id'],
                'name' => (string)$product['name'],
                'description' => (string)$product['description'],
                'model_code' => (string)$product['upstream_model_code'],
                'channel_code' => (string)$product['upstream_channel_code'],
                'provider_model' => (string)$product['upstream_model_code'],
                'protocol' => self::protocol((string)($meta['protocol'] ?? ''), $protocols),
                'protocols' => $protocols,
                'max_tokens' => max(0, (int)($meta['max_tokens'] ?? 0)),
                'default_params' => self::arrayValue($meta['default_params'] ?? []),
                'supports_vision' => self::supportsVision($snapshot),
                'input' => $prices['input'],
                'output' => $prices['output'],
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
                'sku_id' => (int)$sku['id'], 'sku_key' => (string)$sku['sku_key'], 'usage_unit' => (string)$sku['usage_unit'],
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
            'tenant_cost_points' => self::points($inputTokens * (float)$input['platform_price'] / 1000000 + $outputTokens * (float)$output['platform_price'] / 1000000),
            'user_charge_points' => self::points($inputTokens * (float)$input['tenant_price'] / 1000000 + $outputTokens * (float)$output['tenant_price'] / 1000000),
            'prompt_tokens' => $inputTokens, 'completion_tokens' => $outputTokens,
        ];
    }

    /** @return array{app_task: AiAppTask, consumption: AiConsumptionLog} */
    private static function createConsumption(int $tenantId, int $userId, array $model, string $action, array $summary, array $reserve, int $parentTaskId): array
    {
        return Db::transaction(function () use ($tenantId, $userId, $model, $action, $summary, $reserve, $parentTaskId) {
            $now = time();
            $appTask = $parentTaskId > 0 ? AiAppTask::lock(true)->findOrEmpty($parentTaskId) : AiAppTask::create([
                'task_no' => self::no('AT'), 'tenant_id' => $tenantId, 'user_id' => $userId, 'app_code' => 'aigc_short_drama',
                'action_code' => $action, 'business_table' => 'aigc_short_drama_script_task', 'business_id' => 0,
                'parent_task_id' => 0, 'status' => 'running', 'progress' => 10, 'request_summary' => $summary, 'result_summary' => [],
                'estimated_tenant_cost' => $reserve['tenant_cost_points'], 'estimated_user_price' => $reserve['user_charge_points'],
                'actual_tenant_cost' => 0, 'actual_user_price' => 0, 'idempotency_key' => self::no('IK'), 'create_time' => $now, 'update_time' => $now, 'finish_time' => 0,
            ]);
            if ($appTask->isEmpty()) throw new Exception('应用任务不存在');
            $consumeNo = self::no('C');
            $consumption = AiConsumptionLog::create([
                'consume_no' => $consumeNo, 'app_task_id' => (int)$appTask['id'], 'tenant_id' => $tenantId, 'user_id' => $userId,
                'app_code' => 'aigc_short_drama', 'action_code' => $action, 'resource_type' => 'model', 'product_id' => (int)$model['product_id'],
                'sku_id' => (int)$model['input']['sku_id'], 'model_code' => (string)$model['model_code'], 'api_code' => (string)$model['channel_code'],
                'protocol' => (string)$model['protocol'], 'provider' => 'power_market', 'upstream_request_id' => '', 'upstream_task_id' => '',
                'quantity' => 1, 'usage_unit' => 'tokens_1m', 'usage_snapshot' => [],
                'price_snapshot' => ['input' => $model['input'], 'output' => $model['output'], 'model_code' => $model['model_code']],
                'request_summary' => $summary, 'response_summary' => [], 'run_status' => 'submitting', 'billing_status' => 'reserved',
                'reserved_tenant_cost' => $reserve['tenant_cost_points'], 'reserved_user_price' => $reserve['user_charge_points'],
                'actual_tenant_cost' => 0, 'actual_user_price' => 0, 'tenant_point_sn' => $consumeNo . '-reserve', 'user_point_sn' => $consumeNo . '-reserve',
                'error_code' => '', 'error_message' => '', 'refresh_requested_at' => 0, 'create_time' => $now, 'update_time' => $now, 'finish_time' => 0,
            ]);
            PointService::reserveBusinessAmountsInCurrentTransaction($tenantId, $userId, (float)$reserve['tenant_cost_points'], (float)$reserve['user_charge_points'], $consumeNo . '-reserve', '短剧文本模型预占', self::extra($appTask, $consumption, 'reserved'));
            self::event((int)$consumption['id'], 'reserve', 'success', $reserve);
            return compact('appTask', 'consumption');
        });
    }

    private static function settle(array $context, array $actual, array $usage, array $result, int $elapsed): void
    {
        Db::transaction(function () use ($context, $actual, $usage, $result, $elapsed) {
            $c = AiConsumptionLog::lock(true)->findOrEmpty((int)$context['consumption']['id']); if ($c->isEmpty() || $c['billing_status'] === 'settled') return;
            $task = AiAppTask::lock(true)->findOrEmpty((int)$c['app_task_id']); $now = time();
            PointService::settleReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], (float)$actual['tenant_cost_points'], (float)$actual['user_charge_points'], (string)$c['consume_no'], '短剧文本模型结算', self::extra($task, $c, 'settled'));
            $c->save(['upstream_request_id' => (string)($result['provider_request_id'] ?? ''), 'run_status' => 'success', 'billing_status' => 'settled', 'usage_snapshot' => $usage, 'response_summary' => ['output_length' => mb_strlen((string)($result['content'] ?? ''), 'UTF-8')], 'actual_tenant_cost' => $actual['tenant_cost_points'], 'actual_user_price' => $actual['user_charge_points'], 'tenant_point_sn' => (string)$c['consume_no'], 'user_point_sn' => (string)$c['consume_no'], 'finish_time' => $now, 'update_time' => $now]);
            $task->save(['status' => 'success', 'progress' => 100, 'actual_tenant_cost' => (float)$task['actual_tenant_cost'] + (float)$actual['tenant_cost_points'], 'actual_user_price' => (float)$task['actual_user_price'] + (float)$actual['user_charge_points'], 'finish_time' => $now, 'update_time' => $now]);
            self::event((int)$c['id'], 'settle', 'success', $usage, $elapsed);
        });
    }

    private static function fail(array $context, string $message, string $code): void
    {
        Db::transaction(function () use ($context, $message, $code) {
            $c = AiConsumptionLog::lock(true)->findOrEmpty((int)$context['consumption']['id']); if ($c->isEmpty() || in_array((string)$c['billing_status'], ['settled', 'refunded'], true)) return;
            $task = AiAppTask::lock(true)->findOrEmpty((int)$c['app_task_id']);
            PointService::releaseReservedBusinessAmountsInCurrentTransaction((int)$c['tenant_id'], (int)$c['user_id'], (float)$c['reserved_tenant_cost'], (float)$c['reserved_user_price'], (string)$c['consume_no'] . '-release', '短剧文本模型失败退回', self::extra($task, $c, 'refunded'));
            $now = time(); $c->save(['run_status' => 'failed', 'billing_status' => 'refunded', 'error_code' => $code, 'error_message' => mb_substr($message, 0, 1000), 'finish_time' => $now, 'update_time' => $now]);
            $task->save(['status' => 'failed', 'progress' => 100, 'result_summary' => ['error' => mb_substr($message, 0, 500)], 'finish_time' => $now, 'update_time' => $now]); self::event((int)$c['id'], 'refund', 'success', ['reason' => $message]);
        });
    }

    /** @return array<string, mixed> */
    private static function request(array $model, string $content, string $system, array $images, int $maxTokens, array $generationParams, ?callable $onEvent): array
    {
        $source = UpdateSourceClient::getSource(); $base = self::sourceBaseUrl((string)($source['active_base_url'] ?? $source['base_url'] ?? '')); $key = (string)($source['active_api_key'] ?? $source['api_key'] ?? $source['license_key'] ?? '');
        if ($base === '' || $key === '') throw new Exception('文本模型 API 暂不可用');
        $protocol = (string)$model['protocol']; $path = match ($protocol) { 'openai_responses' => '/api/v1/responses', 'anthropic_messages' => '/api/v1/messages', default => '/api/v1/chat/completions' };
        $messageContent = $images === [] ? $content : array_merge([['type' => 'text', 'text' => $content]], array_map(static fn($url) => ['type' => 'image_url', 'image_url' => ['url' => $url]], $images));
        $messages = [['role' => 'user', 'content' => $messageContent]];
        $payload = $protocol === 'openai_responses' ? ['model' => $model['model_code'], 'instructions' => $system, 'input' => $messages, 'stream' => $onEvent !== null, 'max_output_tokens' => $maxTokens] : ($protocol === 'anthropic_messages' ? ['model' => $model['model_code'], 'system' => $system, 'messages' => $messages, 'stream' => $onEvent !== null, 'max_tokens' => $maxTokens] : ['model' => $model['model_code'], 'messages' => array_merge($system === '' ? [] : [['role' => 'system', 'content' => $system]], $messages), 'stream' => $onEvent !== null, 'max_tokens' => $maxTokens, 'channel' => $model['channel_code']]);
        foreach ($generationParams as $paramKey => $value) {
            if ($paramKey === 'stream_options' && $protocol !== 'openai_chat') {
                continue;
            }
            $payload[$paramKey] = $value;
        }
        try {
            return self::curl($base . $path, $key, $payload, $onEvent);
        } catch (Exception $e) {
            // Older market snapshots may not yet advertise this optional Qwen-style
            // parameter. Retry once without it when the selected provider rejects it
            // before producing an SSE response.
            if (array_key_exists('enable_thinking', $generationParams) && self::isOptionalParameterRejected($e)) {
                unset($payload['enable_thinking']);
                return self::curl($base . $path, $key, $payload, $onEvent);
            }
            throw $e;
        }
    }

    private static function resolveMaxTokens(array $model, array $overrides): int
    {
        $modelLimit = (int)($model['max_tokens'] ?? 0);
        $defaults = (array)($model['default_params'] ?? []);
        $default = (int)($defaults['max_tokens'] ?? 0);
        $requested = (int)($overrides['max_tokens'] ?? 0);
        $value = $requested > 0 ? $requested : ($default > 0 ? $default : self::DEFAULT_MAX_OUTPUT_TOKENS);
        if ($modelLimit > 0 && $requested <= 0) {
            $value = min($value, $modelLimit);
        }
        return max(256, min(32768, $value));
    }

    /**
     * Only forward known generation defaults declared by the selected market model.
     * This keeps provider-specific options isolated to models that actually expose them.
     */
    private static function generationParams(array $model, array $overrides): array
    {
        $defaults = (array)($model['default_params'] ?? []);
        $result = [];
        foreach (['temperature', 'top_p', 'presence_penalty', 'frequency_penalty'] as $key) {
            if (isset($defaults[$key]) && is_numeric($defaults[$key])) {
                $result[$key] = (float)$defaults[$key];
            }
        }
        if (array_key_exists('enable_thinking', $defaults) && is_bool($defaults['enable_thinking'])) {
            $result['enable_thinking'] = $defaults['enable_thinking'];
        }
        if (array_key_exists('enable_thinking', $overrides) && is_bool($overrides['enable_thinking'])) {
            $result['enable_thinking'] = $overrides['enable_thinking'];
        }
        if (isset($defaults['stream_options']) && is_array($defaults['stream_options'])) {
            $result['stream_options'] = $defaults['stream_options'];
        }
        foreach (['temperature', 'top_p', 'presence_penalty', 'frequency_penalty'] as $key) {
            if (array_key_exists($key, $overrides) && is_numeric($overrides[$key])) {
                $result[$key] = (float)$overrides[$key];
            }
        }
        return $result;
    }

    private static function isOptionalParameterRejected(Exception $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'http 400')
            || str_contains($message, 'invalid request')
            || str_contains($message, 'unknown parameter')
            || str_contains($message, 'unsupported parameter');
    }

    private static function curl(string $url, string $key, array $payload, ?callable $onEvent): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
            'Accept: ' . ($onEvent ? 'text/event-stream' : 'application/json'),
        ];
        if ($onEvent === null) {
            return self::requestJson($url, $payload, $headers);
        }

        $state = ['content' => '', 'usage' => [], 'request_id' => '', 'emitted_request_id' => '', 'error' => ''];
        $buffer = '';
        $body = '';
        $receivedEvent = false;
        $startedAt = microtime(true);
        $lastHeartbeatAt = $startedAt;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($curl, $downloadTotal, $downloadNow, $uploadTotal, $uploadNow) use ($onEvent, $startedAt, &$lastHeartbeatAt): int {
                $now = microtime(true);
                if ($now - $lastHeartbeatAt >= 5) {
                    $lastHeartbeatAt = $now;
                    $onEvent('heartbeat', ['elapsed_ms' => (int)round(($now - $startedAt) * 1000)]);
                }
                return 0;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$buffer, &$body, &$receivedEvent, &$state, $onEvent): int {
                $body .= $chunk;
                $buffer .= str_replace("\r\n", "\n", $chunk);
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $block = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    $event = self::parseSseBlock($block);
                    if ($event !== null) {
                        $receivedEvent = true;
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
                self::applyStreamEvent($event, $state, $onEvent);
            }
        }
        if (!$receivedEvent) {
            $event = self::parseJsonResponse($body, true);
            if ($event !== null) {
                $receivedEvent = true;
                self::applyStreamEvent($event, $state, $onEvent);
            }
        }

        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new Exception('文本模型网络请求失败：' . mb_substr($error ?: '连接异常', 0, 120));
        }
        if ($status >= 400) {
            throw new Exception(self::providerError($body) ?: '文本模型调用失败（HTTP ' . $status . '）');
        }
        if ($state['error'] !== '') {
            throw new Exception($state['error']);
        }
        if (!$receivedEvent || trim((string)$state['content']) === '') {
            throw new Exception('文本模型未返回有效内容');
        }
        return ['content' => $state['content'], 'usage' => $state['usage'], 'provider_request_id' => $state['request_id']];
    }

    /** @return array<string, mixed> */
    private static function requestJson(string $url, array $payload, array $headers): array
    {
        $body = '';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $body = is_string($response) ? $response : '';
        if ($errno) {
            throw new Exception('文本模型网络请求失败：' . mb_substr($error ?: '连接异常', 0, 120));
        }
        if ($status >= 400) {
            throw new Exception(self::providerError($body) ?: '文本模型调用失败（HTTP ' . $status . '）');
        }
        $event = self::parseJsonResponse($body, false);
        if ($event === null || ($event['type'] ?? '') === 'error') {
            throw new Exception((string)($event['message'] ?? '文本模型返回格式异常'));
        }
        $content = (string)($event['content'] ?? '');
        if (trim($content) === '') {
            throw new Exception('文本模型未返回有效内容');
        }
        return [
            'content' => $content,
            'usage' => (array)($event['usage'] ?? []),
            'provider_request_id' => (string)($event['provider_request_id'] ?? ''),
        ];
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
        if (($event['type'] ?? '') !== 'delta') {
            return;
        }
        $delta = (string)($event['content'] ?? '');
        if ($delta === '') {
            return;
        }
        $state['content'] .= $delta;
        $onEvent('delta', ['delta' => $delta]);
    }

    /** @return array<string, mixed>|null */
    private static function parseProviderPayload(array $json, string $eventName = '', bool $stream = false): ?array
    {
        if (isset($json['error'])) {
            return ['type' => 'error', 'message' => self::providerError($json) ?: '文本模型调用失败'];
        }
        $eventType = strtolower((string)($json['type'] ?? $json['event'] ?? $eventName));
        if (in_array($eventType, ['error', 'failed', 'fail', 'response.failed', 'response.incomplete'], true)) {
            return ['type' => 'error', 'message' => self::providerError($json) ?: '文本模型调用失败'];
        }
        foreach (['data', 'result'] as $key) {
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
        $terminal = !empty($choice['finish_reason']) || in_array($eventType, [
            'done', 'finish', 'finished', 'complete', 'completed', 'message_stop', 'response.completed', 'response.failed',
        ], true);
        if ($terminal) {
            return ['type' => 'done', 'finish_reason' => (string)($choice['finish_reason'] ?? $json['finish_reason'] ?? 'stop'), 'usage' => $usage, 'provider_request_id' => $requestId];
        }

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
        if ($delta !== '') {
            return ['type' => 'delta', 'content' => $delta, 'usage' => $usage, 'provider_request_id' => $requestId];
        }
        if ($usage !== []) {
            return ['type' => 'usage', 'usage' => $usage, 'provider_request_id' => $requestId];
        }
        return null;
    }

    private static function looksLikePayload(array $payload): bool
    {
        foreach (['choices', 'delta', 'content', 'text', 'answer', 'response', 'message', 'usage', 'output', 'error'] as $key) {
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
            $error = $payload['error'] ?? $payload;
            if (is_array($error)) {
                return mb_substr((string)($error['message'] ?? $error['msg'] ?? $error['code'] ?? $error['type'] ?? ''), 0, 160);
            }
            return mb_substr((string)($payload['message'] ?? $payload['msg'] ?? ''), 0, 160);
        }
        return mb_substr(trim(strip_tags((string)$payload)), 0, 160);
    }

    private static function normalizeUsage(array $usage, string $input, string $output): array
    {
        $prompt = (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0); $completion = (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $prompt = $prompt > 0 ? $prompt : self::estimateTokens($input);
        $completion = $completion > 0 ? $completion : self::estimateTokens($output);
        return ['prompt_tokens' => $prompt, 'completion_tokens' => $completion, 'total_tokens' => (int)($usage['total_tokens'] ?? 0) ?: ($prompt + $completion), 'estimated' => $usage === []];
    }

    private static function protocol(string $default, array $protocols): string
    {
        $value = strtolower(trim($default ?: ($protocols[0] ?? 'openai_chat'))); return match ($value) { 'responses', 'openai_responses' => 'openai_responses', 'messages', 'anthropic', 'anthropic_messages' => 'anthropic_messages', default => 'openai_chat' };
    }
    private static function sourceBaseUrl(string $baseUrl): string { $parts = parse_url(trim($baseUrl)); if (!is_array($parts) || empty($parts['host'])) return rtrim($baseUrl, '/'); return (string)($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int)$parts['port'] : ''); }
    private static function arrayValue($value): array { if (is_array($value)) return $value; $decoded = is_string($value) ? json_decode($value, true) : []; return is_array($decoded) ? $decoded : []; }
    private static function points($value): float { return round(max(0, (float)$value), 6); }
    private static function no(string $prefix): string { return $prefix . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10)); }
    private static function extra(AiAppTask $task, AiConsumptionLog $consumption, string $stage): array { return ['app_code' => (string)$task['app_code'], 'task_id' => (int)$task['id'], 'app_task_no' => (string)$task['task_no'], 'consumption_id' => (int)$consumption['id'], 'consume_no' => (string)$consumption['consume_no'], 'billing_stage' => $stage]; }
    private static function event(int $id, string $type, string $status, array $payload, int $elapsed = 0): void { AiConsumptionEvent::create(['consumption_id' => $id, 'event_type' => $type, 'event_status' => $status, 'attempt_no' => 1, 'payload_summary' => $payload, 'payload_ciphertext' => '', 'http_status' => 0, 'elapsed_ms' => $elapsed, 'create_time' => time()]); }
}
