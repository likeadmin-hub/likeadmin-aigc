<?php

namespace app\common\service\app\aigc_llm;

use app\common\model\app\aigc_llm\AigcLlmChannel;
use app\common\model\app\aigc_llm\AigcLlmModel;
use app\common\service\app\UpstreamPricingService;
use Exception;

class AigcLlmChannelService
{
    public static function userConfig(int $tenantId): array
    {
        $channels = self::effectiveChannels($tenantId, true);
        $models = self::effectiveModels($tenantId, true, $channels);
        $defaultModel = $models[0] ?? [];
        return [
            'channels' => array_values(array_map(fn(array $item) => [
                'code' => $item['code'],
                'name' => $item['name'],
                'provider' => $item['provider'],
                'sort' => (int)$item['sort'],
            ], $channels)),
            'models' => array_values(array_map(fn(array $item) => [
                'code' => $item['code'],
                'name' => $item['name'],
                'channel_code' => $item['channel_code'],
                'provider' => $item['provider'],
                'model' => $item['model'],
                'context_limit' => (int)$item['context_limit'],
                'tenant_unit_price' => self::formatPoints((float)$item['tenant_unit_price']),
                'platform_unit_cost' => self::formatPoints((float)$item['platform_unit_cost']),
                'platform_input_unit_cost' => self::formatUnitPrice((float)($item['platform_input_unit_cost'] ?? $item['platform_unit_cost'] ?? 0)),
                'platform_output_unit_cost' => self::formatUnitPrice((float)($item['platform_output_unit_cost'] ?? $item['platform_unit_cost'] ?? 0)),
                'platform_input_unit_price' => self::formatUnitPrice((float)($item['platform_input_unit_price'] ?? $item['platform_input_unit_cost'] ?? $item['platform_unit_cost'] ?? 0)),
                'platform_output_unit_price' => self::formatUnitPrice((float)($item['platform_output_unit_price'] ?? $item['platform_output_unit_cost'] ?? $item['platform_unit_cost'] ?? 0)),
                'tenant_input_unit_price' => self::formatUnitPrice((float)($item['tenant_input_unit_price'] ?? $item['tenant_unit_price'] ?? 0)),
                'tenant_output_unit_price' => self::formatUnitPrice((float)($item['tenant_output_unit_price'] ?? $item['tenant_unit_price'] ?? 0)),
                'billing_unit' => (string)($item['billing_unit'] ?? 'tokens_1m'),
                'sort' => (int)$item['sort'],
            ], $models)),
            'defaults' => [
                'channel' => (string)($defaultModel['channel_code'] ?? ($channels[0]['code'] ?? '')),
                'model' => (string)($defaultModel['code'] ?? ''),
            ]
        ];
    }

    public static function resolveUserModel(int $tenantId, array $params, array $config = []): array
    {
        $channels = self::effectiveChannels($tenantId, true);
        $models = self::effectiveModels($tenantId, true, $channels);
        if (empty($models)) {
            throw new Exception('暂无可用对话模型');
        }
        $modelCode = trim((string)($params['model_code'] ?? $config['model'] ?? ''));
        if ($modelCode !== '') {
            foreach ($models as $model) {
                if ($model['code'] === $modelCode) {
                    return $model;
                }
            }
        }
        return $models[0];
    }

    public static function platformChannelLists(): array
    {
        return self::maskChannelSecrets(AigcLlmChannel::where('tenant_id', 0)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray());
    }

    public static function platformModelLists(): array
    {
        $rows = AigcLlmModel::where('tenant_id', 0)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
        $channelMap = array_column(self::platformChannelLists(), null, 'code');
        foreach ($rows as &$row) {
            $row['channel_name'] = $channelMap[$row['channel_code']]['name'] ?? $row['channel_code'];
        }
        return $rows;
    }

    public static function tenantChannelLists(int $tenantId): array
    {
        return self::maskChannelSecrets(self::effectiveChannels($tenantId, false));
    }

    public static function tenantModelLists(int $tenantId): array
    {
        $channels = self::effectiveChannels($tenantId, false);
        return self::effectiveModels($tenantId, false, $channels);
    }

    public static function savePlatformChannel(array $params): void
    {
        $code = self::normalizeCode((string)($params['code'] ?? ''));
        $data = [
            'tenant_id' => 0,
            'code' => $code,
            'name' => trim((string)($params['name'] ?? '')),
            'provider' => trim((string)($params['provider'] ?? 'openai_compatible')) ?: 'openai_compatible',
            'config_json' => self::mergeSecretConfig(self::normalizeJson($params['config_json'] ?? []), AigcLlmChannel::where(['tenant_id' => 0, 'code' => $code])->value('config_json')),
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];
        if ($data['name'] === '') {
            throw new Exception('请输入通道名称');
        }
        self::saveRow(AigcLlmChannel::class, ['tenant_id' => 0, 'code' => $code], $data);
    }

    public static function savePlatformModel(array $params): void
    {
        $channelCode = self::normalizeCode((string)($params['channel_code'] ?? ''));
        self::assertChannelExists(0, $channelCode);
        $code = self::normalizeCode((string)($params['code'] ?? ''));
        $data = [
            'tenant_id' => 0,
            'channel_code' => $channelCode,
            'code' => $code,
            'name' => trim((string)($params['name'] ?? '')),
            'provider' => trim((string)($params['provider'] ?? 'openai_compatible')) ?: 'openai_compatible',
            'model' => trim((string)($params['model'] ?? '')) ?: 'qwen3.6-plus',
            'context_limit' => max(1, (int)($params['context_limit'] ?? 12)),
            'platform_unit_cost' => self::formatPoints((float)($params['platform_unit_cost'] ?? 0)),
            'tenant_unit_price' => self::formatPoints((float)($params['tenant_unit_price'] ?? 0)),
            'platform_input_unit_cost' => self::formatUnitPrice((float)($params['platform_input_unit_cost'] ?? $params['platform_unit_cost'] ?? 0)),
            'platform_output_unit_cost' => self::formatUnitPrice((float)($params['platform_output_unit_cost'] ?? $params['platform_unit_cost'] ?? 0)),
            'platform_input_unit_price' => self::formatUnitPrice((float)($params['platform_input_unit_price'] ?? $params['tenant_input_unit_price'] ?? $params['platform_input_unit_cost'] ?? $params['platform_unit_cost'] ?? 0)),
            'platform_output_unit_price' => self::formatUnitPrice((float)($params['platform_output_unit_price'] ?? $params['tenant_output_unit_price'] ?? $params['platform_output_unit_cost'] ?? $params['platform_unit_cost'] ?? 0)),
            'tenant_input_unit_price' => self::formatUnitPrice((float)($params['tenant_input_unit_price'] ?? $params['platform_input_unit_price'] ?? $params['tenant_unit_price'] ?? 0)),
            'tenant_output_unit_price' => self::formatUnitPrice((float)($params['tenant_output_unit_price'] ?? $params['platform_output_unit_price'] ?? $params['tenant_unit_price'] ?? 0)),
            'billing_unit' => 'tokens_1m',
            'config_json' => self::normalizeJson($params['config_json'] ?? []),
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? 0),
            'update_time' => time(),
        ];
        if ($data['name'] === '') {
            throw new Exception('请输入模型名称');
        }
        self::saveRow(AigcLlmModel::class, ['tenant_id' => 0, 'code' => $code], $data);
    }

    public static function syncPlatformTextModelsFromUpstream(): array
    {
        $remoteModels = UpstreamPricingService::queryModels('text', true);
        if (empty($remoteModels)) {
            return ['added' => 0, 'exists' => 0, 'skipped' => 0, 'total' => 0];
        }

        $pricingMap = self::queryRemoteModelPricingMap($remoteModels);
        $added = 0;
        $updated = 0;
        $exists = 0;
        $skipped = 0;
        $enabled = 0;
        $disabled = 0;
        foreach ($remoteModels as $index => $item) {
            $modelCode = trim((string)($item['model_code'] ?? $item['id'] ?? ''));
            if ($modelCode === '') {
                $skipped++;
                continue;
            }
            $priceKey = self::remoteModelKey($item);
            $pricing = $pricingMap[$priceKey] ?? [];
            $channelCode = self::localChannelCode((string)($item['channel_code'] ?? ''));
            $localCode = self::resolveSyncedModelCode($modelCode, $channelCode);
            $existsRow = AigcLlmModel::where(['tenant_id' => 0, 'code' => $localCode])->findOrEmpty();
            $prices = self::pricesFromRemotePricing($pricing);
            $legacyPrice = max($prices['input'], $prices['output']);
            $hasPricing = ($pricing['available'] ?? false) === true && $legacyPrice > 0;
            self::ensureSyncedChannel($channelCode, (string)($item['channel_name'] ?? ''), (string)($item['channel_code'] ?? ''));
            if (!$existsRow->isEmpty()) {
                $exists++;
                $config = self::normalizeJson($existsRow['config_json'] ?? []);
                if (!array_key_exists('pricing_available', $config)) {
                    continue;
                }
                $config['pricing_available'] = $hasPricing ? 1 : 0;
                $existsRow->save([
                    'name' => trim((string)($item['model_name'] ?? $item['name'] ?? $modelCode)) ?: $modelCode,
                    'channel_code' => $channelCode,
                    'model' => $modelCode,
                    'platform_unit_cost' => self::formatPoints($legacyPrice),
                    'tenant_unit_price' => self::formatPoints($legacyPrice),
                    'platform_input_unit_cost' => self::formatUnitPrice($prices['input']),
                    'platform_output_unit_cost' => self::formatUnitPrice($prices['output']),
                    'config_json' => $config,
                    'status' => $hasPricing ? 1 : 0,
                    'update_time' => time(),
                ]);
                $updated++;
                $hasPricing ? $enabled++ : $disabled++;
                continue;
            }

            AigcLlmModel::create([
                'tenant_id' => 0,
                'channel_code' => $channelCode,
                'code' => $localCode,
                'name' => trim((string)($item['model_name'] ?? $item['name'] ?? $modelCode)) ?: $modelCode,
                'provider' => 'openai_compatible',
                'model' => $modelCode,
                'context_limit' => 24,
                'platform_unit_cost' => self::formatPoints($legacyPrice),
                'tenant_unit_price' => self::formatPoints($legacyPrice),
                'platform_input_unit_cost' => self::formatUnitPrice($prices['input']),
                'platform_output_unit_cost' => self::formatUnitPrice($prices['output']),
                'platform_input_unit_price' => self::formatUnitPrice($prices['input']),
                'platform_output_unit_price' => self::formatUnitPrice($prices['output']),
                'tenant_input_unit_price' => self::formatUnitPrice($prices['input']),
                'tenant_output_unit_price' => self::formatUnitPrice($prices['output']),
                'billing_unit' => 'tokens_1m',
                'config_json' => [
                    'upstream_channel_code' => (string)($item['channel_code'] ?? ''),
                    'max_tokens' => (int)($item['max_tokens'] ?? 0),
                    'params_schema' => is_array($item['params_schema'] ?? null) ? $item['params_schema'] : [],
                    'default_params' => is_array($item['default_params'] ?? null) ? $item['default_params'] : [],
                    'stream_options' => ['include_usage' => true],
                    'pricing_available' => $hasPricing ? 1 : 0,
                ],
                'status' => $hasPricing ? 1 : 0,
                'sort' => max(1, 900 - $index),
                'create_time' => time(),
                'update_time' => time(),
            ]);
            $added++;
            $hasPricing ? $enabled++ : $disabled++;
        }

        return [
            'added' => $added,
            'updated' => $updated,
            'enabled' => $enabled,
            'disabled' => $disabled,
            'exists' => $exists,
            'skipped' => $skipped,
            'total' => count($remoteModels),
        ];
    }

    public static function queryPlatformModelPricing(array $row): array
    {
        $modelCode = trim((string)($row['model'] ?? ''));
        if ($modelCode === '') {
            throw new Exception('模型编码不能为空');
        }

        $config = self::normalizeJson($row['config_json'] ?? []);
        $upstreamChannelCode = trim((string)($config['upstream_channel_code'] ?? $row['channel_code'] ?? ''));
        foreach (UpstreamPricingService::queryModels('text', true) as $remoteModel) {
            if (trim((string)($remoteModel['model_code'] ?? $remoteModel['id'] ?? '')) !== $modelCode) {
                continue;
            }
            $remoteChannelCode = trim((string)($remoteModel['channel_code'] ?? ''));
            if ($upstreamChannelCode !== '' && $remoteChannelCode !== '' && $remoteChannelCode !== $upstreamChannelCode) {
                continue;
            }
            $pricing = self::pricingFromRemoteModelList($remoteModel);
            if ($pricing !== null) {
                return $pricing;
            }
        }

        return UpstreamPricingService::queryModel($modelCode, $upstreamChannelCode);
    }

    public static function deletePlatformChannel(array $params): void
    {
        $row = AigcLlmChannel::where(['tenant_id' => 0, 'id' => (int)($params['id'] ?? 0)])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('通道不存在');
        }
        AigcLlmModel::where(['tenant_id' => 0, 'channel_code' => $row['code']])->delete();
        $row->delete();
    }

    public static function deletePlatformModel(array $params): void
    {
        $row = AigcLlmModel::where(['tenant_id' => 0, 'id' => (int)($params['id'] ?? 0)])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('模型不存在');
        }
        $row->delete();
    }

    public static function statusPlatformChannel(array $params): void
    {
        $row = AigcLlmChannel::where(['tenant_id' => 0, 'id' => (int)($params['id'] ?? 0)])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('通道不存在');
        }
        $row->save(['status' => (int)($params['status'] ?? 1), 'update_time' => time()]);
    }

    public static function statusPlatformModel(array $params): void
    {
        $row = AigcLlmModel::where(['tenant_id' => 0, 'id' => (int)($params['id'] ?? 0)])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('模型不存在');
        }
        $row->save(['status' => (int)($params['status'] ?? 1), 'update_time' => time()]);
    }

    public static function statusPlatformModels(array $params): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($params['ids'] ?? [])))));
        if (empty($ids)) {
            throw new Exception('请选择要操作的模型');
        }

        $status = (int)($params['status'] ?? -1);
        if (!in_array($status, [0, 1], true)) {
            throw new Exception('状态参数错误');
        }

        return (int)AigcLlmModel::where('tenant_id', 0)
            ->whereIn('id', $ids)
            ->update(['status' => $status, 'update_time' => time()]);
    }

    public static function saveTenantChannel(int $tenantId, array $params): void
    {
        $code = self::normalizeCode((string)($params['code'] ?? ''));
        $platform = AigcLlmChannel::where(['tenant_id' => 0, 'code' => $code])->findOrEmpty();
        if ($platform->isEmpty()) {
            throw new Exception('平台未开放该通道');
        }
        $data = [
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => trim((string)($params['name'] ?? $platform['name'])),
            'provider' => (string)$platform['provider'],
            'config_json' => self::mergeSecretConfig(self::normalizeJson($params['config_json'] ?? []), AigcLlmChannel::where(['tenant_id' => $tenantId, 'code' => $code])->value('config_json')),
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? $platform['sort']),
            'update_time' => time(),
        ];
        self::saveRow(AigcLlmChannel::class, ['tenant_id' => $tenantId, 'code' => $code], $data);
    }

    public static function saveTenantModel(int $tenantId, array $params): void
    {
        $code = self::normalizeCode((string)($params['code'] ?? ''));
        $platform = AigcLlmModel::where(['tenant_id' => 0, 'code' => $code])->findOrEmpty();
        if ($platform->isEmpty()) {
            throw new Exception('平台未开放该模型');
        }
        $data = [
            'tenant_id' => $tenantId,
            'channel_code' => (string)$platform['channel_code'],
            'code' => $code,
            'name' => trim((string)($params['name'] ?? $platform['name'])),
            'provider' => (string)$platform['provider'],
            'model' => trim((string)($params['model'] ?? $platform['model'])),
            'context_limit' => max(1, (int)($params['context_limit'] ?? $platform['context_limit'])),
            'platform_unit_cost' => self::formatPoints((float)$platform['platform_unit_cost']),
            'tenant_unit_price' => self::formatPoints((float)($params['tenant_unit_price'] ?? $platform['tenant_unit_price'])),
            'platform_input_unit_cost' => self::formatUnitPrice((float)($platform['platform_input_unit_cost'] ?? $platform['platform_unit_cost'] ?? 0)),
            'platform_output_unit_cost' => self::formatUnitPrice((float)($platform['platform_output_unit_cost'] ?? $platform['platform_unit_cost'] ?? 0)),
            'platform_input_unit_price' => self::formatUnitPrice((float)($platform['platform_input_unit_price'] ?? $platform['platform_input_unit_cost'] ?? $platform['platform_unit_cost'] ?? 0)),
            'platform_output_unit_price' => self::formatUnitPrice((float)($platform['platform_output_unit_price'] ?? $platform['platform_output_unit_cost'] ?? $platform['platform_unit_cost'] ?? 0)),
            'tenant_input_unit_price' => self::formatUnitPrice((float)($params['tenant_input_unit_price'] ?? $platform['tenant_input_unit_price'] ?? $platform['tenant_unit_price'] ?? 0)),
            'tenant_output_unit_price' => self::formatUnitPrice((float)($params['tenant_output_unit_price'] ?? $platform['tenant_output_unit_price'] ?? $platform['tenant_unit_price'] ?? 0)),
            'billing_unit' => 'tokens_1m',
            'config_json' => self::normalizeJson($params['config_json'] ?? ($platform['config_json'] ?? [])),
            'status' => (int)($params['status'] ?? 1),
            'sort' => (int)($params['sort'] ?? $platform['sort']),
            'update_time' => time(),
        ];
        self::saveRow(AigcLlmModel::class, ['tenant_id' => $tenantId, 'code' => $code], $data);
    }

    public static function statusTenantChannel(int $tenantId, array $params): void
    {
        $row = AigcLlmChannel::where(['tenant_id' => $tenantId, 'id' => (int)($params['id'] ?? 0)])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('通道不存在');
        }
        $row->save(['status' => (int)($params['status'] ?? 1), 'update_time' => time()]);
    }

    public static function statusTenantModel(int $tenantId, array $params): void
    {
        $row = AigcLlmModel::where(['tenant_id' => $tenantId, 'id' => (int)($params['id'] ?? 0)])->findOrEmpty();
        if ($row->isEmpty()) {
            throw new Exception('模型不存在');
        }
        $row->save(['status' => (int)($params['status'] ?? 1), 'update_time' => time()]);
    }

    public static function effectiveChannels(int $tenantId, bool $onlyEnabled = true): array
    {
        $platform = AigcLlmChannel::where('tenant_id', 0)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
        $tenant = $tenantId > 0
            ? AigcLlmChannel::where('tenant_id', $tenantId)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray()
            : [];
        $map = [];
        $platformMap = [];
        $tenantMap = [];
        foreach ($platform as $row) {
            $platformMap[$row['code']] = $row;
            $map[$row['code']] = $row;
        }
        foreach ($tenant as $row) {
            $tenantMap[$row['code']] = $row;
            $map[$row['code']] = array_merge($map[$row['code']] ?? [], $row);
        }
        $rows = [];
        foreach ($map as $code => $row) {
            $platformStatus = (int)($platformMap[$code]['status'] ?? $row['status'] ?? 0);
            $tenantStatus = (int)($tenantMap[$code]['status'] ?? 1);
            $row['platform_status'] = $platformStatus;
            $row['tenant_status'] = $tenantStatus;
            $row['status'] = $platformStatus === 1 && $tenantStatus === 1 ? 1 : 0;
            $rows[] = $row;
        }
        if ($onlyEnabled) {
            $rows = array_values(array_filter($rows, fn(array $row) => (int)($row['status'] ?? 0) === 1));
        }
        usort($rows, function (array $a, array $b) {
            $sort = (int)$b['sort'] <=> (int)$a['sort'];
            return $sort !== 0 ? $sort : ((int)$a['id'] <=> (int)$b['id']);
        });
        return $rows;
    }

    public static function effectiveModels(int $tenantId, bool $onlyEnabled = true, array $channels = []): array
    {
        $channels = $channels ?: self::effectiveChannels($tenantId, $onlyEnabled);
        $channelMap = array_column($channels, null, 'code');
        $platform = AigcLlmModel::where('tenant_id', 0)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray();
        $tenant = $tenantId > 0
            ? AigcLlmModel::where('tenant_id', $tenantId)->order(['sort' => 'desc', 'id' => 'asc'])->select()->toArray()
            : [];
        $map = [];
        $platformMap = [];
        $tenantMap = [];
        foreach ($platform as $row) {
            $platformMap[$row['code']] = $row;
            $map[$row['code']] = $row;
        }
        foreach ($tenant as $row) {
            $tenantMap[$row['code']] = $row;
            $map[$row['code']] = array_merge($map[$row['code']] ?? [], $row);
        }
        $rows = [];
        foreach ($map as $row) {
            $code = (string)($row['code'] ?? '');
            if (!isset($channelMap[$row['channel_code']])) {
                continue;
            }
            $platformStatus = (int)($platformMap[$code]['status'] ?? $row['status'] ?? 0);
            $tenantStatus = (int)($tenantMap[$code]['status'] ?? 1);
            if (isset($platformMap[$code])) {
                $row['platform_unit_cost'] = $platformMap[$code]['platform_unit_cost'];
                $row['platform_input_unit_cost'] = $platformMap[$code]['platform_input_unit_cost'] ?? $platformMap[$code]['platform_unit_cost'] ?? 0;
                $row['platform_output_unit_cost'] = $platformMap[$code]['platform_output_unit_cost'] ?? $platformMap[$code]['platform_unit_cost'] ?? 0;
                $row['platform_input_unit_price'] = $platformMap[$code]['platform_input_unit_price'] ?? $platformMap[$code]['platform_input_unit_cost'] ?? $platformMap[$code]['platform_unit_cost'] ?? 0;
                $row['platform_output_unit_price'] = $platformMap[$code]['platform_output_unit_price'] ?? $platformMap[$code]['platform_output_unit_cost'] ?? $platformMap[$code]['platform_unit_cost'] ?? 0;
                $row['billing_unit'] = $platformMap[$code]['billing_unit'] ?? $row['billing_unit'] ?? 'tokens_1m';
            }
            $row['platform_status'] = $platformStatus;
            $row['tenant_status'] = $tenantStatus;
            $row['status'] = $platformStatus === 1 && (int)($channelMap[$row['channel_code']]['status'] ?? 0) === 1 && $tenantStatus === 1 ? 1 : 0;
            if ($onlyEnabled && (int)($row['status'] ?? 0) !== 1) {
                continue;
            }
            $row['channel_name'] = $channelMap[$row['channel_code']]['name'] ?? $row['channel_code'];
            $rows[] = $row;
        }
        usort($rows, function (array $a, array $b) {
            $sort = (int)$b['sort'] <=> (int)$a['sort'];
            return $sort !== 0 ? $sort : ((int)$a['id'] <=> (int)$b['id']);
        });
        return $rows;
    }

    private static function assertChannelExists(int $tenantId, string $channelCode): void
    {
        $exists = AigcLlmChannel::where(['tenant_id' => $tenantId, 'code' => $channelCode])->count();
        if (!$exists) {
            throw new Exception('通道不存在');
        }
    }

    private static function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
            throw new Exception('编码必须为小写snake_case');
        }
        return $code;
    }

    private static function localModelCode(string $modelCode): string
    {
        return self::normalizeCodeFromRemote($modelCode, 'model');
    }

    private static function resolveSyncedModelCode(string $modelCode, string $channelCode): string
    {
        $baseCode = self::localModelCode($modelCode);
        $row = AigcLlmModel::where(['tenant_id' => 0, 'code' => $baseCode])->findOrEmpty();
        if ($row->isEmpty() || self::sameSyncedModel($row->toArray(), $modelCode, $channelCode)) {
            return $baseCode;
        }

        $candidate = self::fitCode($baseCode, '_' . $channelCode);
        $row = AigcLlmModel::where(['tenant_id' => 0, 'code' => $candidate])->findOrEmpty();
        if ($row->isEmpty() || self::sameSyncedModel($row->toArray(), $modelCode, $channelCode)) {
            return $candidate;
        }

        return self::fitCode($baseCode, '_' . substr(md5($modelCode . '|' . $channelCode), 0, 8));
    }

    private static function sameSyncedModel(array $row, string $modelCode, string $channelCode): bool
    {
        return strcasecmp((string)($row['model'] ?? ''), $modelCode) === 0
            && (string)($row['channel_code'] ?? '') === $channelCode;
    }

    private static function fitCode(string $baseCode, string $suffix): string
    {
        $maxLength = 64;
        if (strlen($baseCode . $suffix) <= $maxLength) {
            return $baseCode . $suffix;
        }
        return rtrim(substr($baseCode, 0, max(1, $maxLength - strlen($suffix))), '_') . $suffix;
    }

    private static function localChannelCode(string $channelCode): string
    {
        $channelCode = trim($channelCode) !== '' ? $channelCode : 'upstream_text';
        return self::normalizeCodeFromRemote($channelCode, 'channel');
    }

    private static function normalizeCodeFromRemote(string $value, string $prefix): string
    {
        $code = strtolower(trim($value));
        $code = preg_replace('/[^a-z0-9]+/', '_', $code) ?: '';
        $code = trim($code, '_');
        if ($code === '') {
            $code = $prefix;
        }
        if (!preg_match('/^[a-z]/', $code)) {
            $code = $prefix . '_' . $code;
        }
        return substr($code, 0, 100);
    }

    private static function ensureSyncedChannel(string $channelCode, string $channelName, string $upstreamChannelCode): void
    {
        $row = AigcLlmChannel::where(['tenant_id' => 0, 'code' => $channelCode])->findOrEmpty();
        if (!$row->isEmpty()) {
            return;
        }
        AigcLlmChannel::create([
            'tenant_id' => 0,
            'code' => $channelCode,
            'name' => trim($channelName) ?: $channelCode,
            'provider' => 'openai_compatible',
            'config_json' => [
                'base_url' => '',
                'stream_path' => '/api/v1/chat/completions',
                'api_key' => '',
                'timeout' => 120,
                'ssl_verify' => 0,
                'upstream_channel_code' => $upstreamChannelCode,
                'remark' => '由可用文本模型同步创建',
            ],
            'status' => 1,
            'sort' => 900,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private static function queryRemoteModelPricingMap(array $remoteModels): array
    {
        $map = [];
        $items = [];
        foreach ($remoteModels as $item) {
            $modelCode = trim((string)($item['model_code'] ?? $item['id'] ?? ''));
            if ($modelCode === '') {
                continue;
            }
            $inlinePricing = self::pricingFromRemoteModelList($item);
            if ($inlinePricing !== null) {
                $map[self::remoteModelKey($item)] = $inlinePricing;
                continue;
            }
            $items[] = [
                'type' => 'model',
                'model' => $modelCode,
                'channel' => (string)($item['channel_code'] ?? ''),
                'local_key' => self::remoteModelKey($item),
            ];
        }
        foreach (array_chunk($items, 100) as $chunk) {
            $response = UpstreamPricingService::queryBatch($chunk);
            foreach (($response['items'] ?? []) as $row) {
                if (is_array($row) && ($row['local_key'] ?? '') !== '') {
                    $map[(string)$row['local_key']] = $row;
                }
            }
        }
        return $map;
    }

    private static function pricingFromRemoteModelList(array $item): ?array
    {
        $pricing = is_array($item['pricing'] ?? null) ? $item['pricing'] : [];
        if (empty($pricing)) {
            return null;
        }

        $prices = self::pricesFromRemotePricing(['pricing' => $pricing]);
        $hasPricing = $prices['input'] > 0 || $prices['output'] > 0;
        return [
            'available' => $hasPricing,
            'type' => 'model',
            'resource' => [
                'model_code' => (string)($item['model_code'] ?? $item['id'] ?? ''),
                'model_name' => (string)($item['model_name'] ?? $item['name'] ?? ''),
                'channel_code' => (string)($item['channel_code'] ?? ''),
                'channel_name' => (string)($item['channel_name'] ?? ''),
            ],
            'pricing' => $pricing,
            'pricing_source' => ['name' => '模型列表渠道定价'],
            'message' => $hasPricing ? '' : '模型列表未提供有效渠道定价',
        ];
    }

    private static function remoteModelKey(array $item): string
    {
        return trim((string)($item['model_code'] ?? $item['id'] ?? '')) . '|' . trim((string)($item['channel_code'] ?? ''));
    }

    private static function pricesFromRemotePricing(array $pricingItem): array
    {
        $pricing = is_array($pricingItem['pricing'] ?? null) ? $pricingItem['pricing'] : [];
        $input = self::firstPositiveNumber([
            $pricing['per_1k_input'] ?? null,
            $pricing['points_per_1k_input'] ?? null,
            $pricing['input_price'] ?? null,
            $pricing['input_points'] ?? null,
        ]);
        $output = self::firstPositiveNumber([
            $pricing['per_1k_output'] ?? null,
            $pricing['points_per_1k_output'] ?? null,
            $pricing['output_price'] ?? null,
            $pricing['output_points'] ?? null,
        ]);
        $fixed = self::firstPositiveNumber([
            $pricing['fixed_points'] ?? null,
            $pricing['fixed_price'] ?? null,
        ]);
        if ($input <= 0 && $fixed > 0) {
            $input = $fixed;
        }
        if ($output <= 0) {
            $output = $input;
        }
        return [
            'input' => $input,
            'output' => $output,
        ];
    }

    private static function firstPositiveNumber(array $values): float
    {
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $number = (float)$value;
            if ($number > 0) {
                return $number;
            }
        }
        return 0.0;
    }

    private static function saveRow(string $modelClass, array $where, array $data): void
    {
        $row = $modelClass::where($where)->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            $modelClass::create($data);
            return;
        }
        $row->save($data);
    }

    private static function normalizeJson($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    private static function mergeSecretConfig(array $input, $current): array
    {
        $current = self::normalizeJson($current);
        if (isset($input['api_key']) && (str_contains((string)$input['api_key'], '*') || (string)$input['api_key'] === '')) {
            $input['api_key'] = (string)($current['api_key'] ?? '');
        }
        return array_merge($current, $input);
    }

    private static function maskChannelSecrets(array $rows): array
    {
        foreach ($rows as &$row) {
            $config = self::normalizeJson($row['config_json'] ?? []);
            if (!empty($config['api_key'])) {
                $key = (string)$config['api_key'];
                $config['api_key'] = strlen($key) > 8 ? substr($key, 0, 4) . '****' . substr($key, -4) : '******';
            }
            $row['config_json'] = $config;
        }
        return $rows;
    }

    private static function formatPoints(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private static function formatUnitPrice(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
