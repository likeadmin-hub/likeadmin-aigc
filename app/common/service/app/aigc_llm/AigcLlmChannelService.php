<?php

namespace app\common\service\app\aigc_llm;

use app\common\model\app\aigc_llm\AigcLlmChannel;
use app\common\model\app\aigc_llm\AigcLlmModel;
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
            'tenant_input_unit_price' => self::formatUnitPrice((float)($params['tenant_input_unit_price'] ?? $params['tenant_unit_price'] ?? 0)),
            'tenant_output_unit_price' => self::formatUnitPrice((float)($params['tenant_output_unit_price'] ?? $params['tenant_unit_price'] ?? 0)),
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
        foreach ($platform as $row) {
            $map[$row['code']] = $row;
        }
        foreach ($tenant as $row) {
            $map[$row['code']] = array_merge($map[$row['code']] ?? [], $row);
        }
        $rows = array_values($map);
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
        foreach ($platform as $row) {
            $map[$row['code']] = $row;
        }
        foreach ($tenant as $row) {
            $map[$row['code']] = array_merge($map[$row['code']] ?? [], $row);
        }
        $rows = [];
        foreach ($map as $row) {
            if (!isset($channelMap[$row['channel_code']])) {
                continue;
            }
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
