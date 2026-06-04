<?php

namespace app\common\service\app\aigc_digital_human;

use app\common\model\app\aigc_digital_human\AigcDigitalHumanConfig;
use app\common\model\app\aigc_digital_human\AigcDigitalHumanChannel;
use Exception;
use Throwable;

class AigcDigitalHumanPricingService
{
    public const TYPE_GENERATE = 'generate';
    public const TYPE_AVATAR_CLONE = 'avatar_clone';
    public const TYPE_VOICE_CLONE = 'voice_clone';
    private const TYPES = [
        self::TYPE_GENERATE,
        self::TYPE_AVATAR_CLONE,
        self::TYPE_VOICE_CLONE,
    ];

    public static function config(int $tenantId = 0): array
    {
        $platform = self::pricingFromConfig(0);
        if ($tenantId <= 0) {
            return self::withGenerateModels($platform, 0);
        }
        $tenant = self::pricingFromConfig($tenantId);
        foreach ([self::TYPE_AVATAR_CLONE, self::TYPE_VOICE_CLONE] as $type) {
            $tenant[$type]['platform_unit_cost'] = $platform[$type]['platform_unit_cost'];
            if (!isset($tenant[$type]['tenant_unit_price']) || (float)$tenant[$type]['tenant_unit_price'] < (float)$platform[$type]['platform_unit_cost']) {
                $tenant[$type]['tenant_unit_price'] = $platform[$type]['tenant_unit_price'];
            }
        }
        $platform = self::withGenerateModels($platform, 0);
        $tenant = self::withGenerateModels($tenant, $tenantId);
        $tenantModels = array_column($tenant['generate_models'], null, 'code');
        $models = [];
        foreach ($platform['generate_models'] as $platformModel) {
            $tenantModel = $tenantModels[$platformModel['code']] ?? [];
            $tenantPrice = self::formatPoints((float)($tenantModel['tenant_unit_price'] ?? $platformModel['tenant_unit_price']));
            if ((float)$tenantPrice < (float)$platformModel['platform_unit_cost']) {
                $tenantPrice = $platformModel['tenant_unit_price'];
            }
            $models[] = array_merge($platformModel, [
                'tenant_unit_price' => $tenantPrice,
            ]);
        }
        $tenant['generate_models'] = $models;
        $tenant[self::TYPE_GENERATE] = $models[0] ?? $platform[self::TYPE_GENERATE];
        return $tenant;
    }

    public static function savePlatform(array $params): void
    {
        self::savePricing(0, $params, true);
    }

    public static function saveTenant(int $tenantId, array $params): void
    {
        self::savePricing($tenantId, $params, false);
    }

    public static function estimateClone(int $tenantId, string $type): array
    {
        $config = self::config($tenantId);
        $row = $config[$type] ?? [];
        return [
            'billing_type' => $type,
            'billing_unit' => 'count',
            'quantity' => 1,
            'platform_unit_cost' => self::formatPoints((float)($row['platform_unit_cost'] ?? 0)),
            'tenant_unit_price' => self::formatPoints((float)($row['tenant_unit_price'] ?? 0)),
            'tenant_cost_points' => self::formatPoints((float)($row['platform_unit_cost'] ?? 0)),
            'user_charge_points' => self::formatPoints((float)($row['tenant_unit_price'] ?? 0)),
        ];
    }

    public static function estimateGenerate(int $tenantId, int $duration, string $modelCode = ''): array
    {
        $duration = max(1, $duration);
        $row = self::generateModel($tenantId, $modelCode);
        return [
            'billing_type' => self::TYPE_GENERATE,
            'billing_unit' => 'second',
            'model_code' => (string)($row['code'] ?? $modelCode),
            'model_name' => (string)($row['name'] ?? $modelCode),
            'quantity' => 1,
            'billable_quantity' => $duration,
            'duration' => $duration,
            'platform_unit_cost' => self::formatPoints((float)($row['platform_unit_cost'] ?? 0)),
            'tenant_unit_price' => self::formatPoints((float)($row['tenant_unit_price'] ?? 0)),
            'tenant_cost_points' => self::formatPoints((float)($row['platform_unit_cost'] ?? 0) * $duration),
            'user_charge_points' => self::formatPoints((float)($row['tenant_unit_price'] ?? 0) * $duration),
        ];
    }

    public static function renameModelCode(string $originCode, string $newCode): void
    {
        if ($originCode === '' || $newCode === '' || $originCode === $newCode) {
            return;
        }
        $rows = AigcDigitalHumanConfig::select();
        foreach ($rows as $row) {
            $config = $row['config_json'] ?? [];
            if (!is_array($config) || !is_array($config['pricing']['generate_models'] ?? null)) {
                continue;
            }
            $changed = false;
            foreach ($config['pricing']['generate_models'] as &$model) {
                if (is_array($model) && (string)($model['code'] ?? '') === $originCode) {
                    $model['code'] = $newCode;
                    $changed = true;
                }
            }
            unset($model);
            if (!$changed) {
                continue;
            }
            $row->save([
                'config_json' => $config,
                'update_time' => time(),
            ]);
        }
    }

    private static function pricingFromConfig(int $tenantId): array
    {
        $defaults = self::defaults();
        try {
            $row = AigcDigitalHumanConfig::where('tenant_id', $tenantId)->findOrEmpty();
        } catch (Throwable $e) {
            return $defaults;
        }
        if ($row->isEmpty()) {
            return $defaults;
        }
        $config = $row['config_json'] ?? [];
        $pricing = is_array($config) ? ($config['pricing'] ?? []) : [];
        if (!is_array($pricing)) {
            return $defaults;
        }
        if (is_array($pricing['generate_models'] ?? null)) {
            $defaults['generate_models'] = $pricing['generate_models'];
        }
        foreach ([self::TYPE_GENERATE, self::TYPE_AVATAR_CLONE, self::TYPE_VOICE_CLONE] as $type) {
            if (!is_array($pricing[$type] ?? null)) {
                continue;
            }
            $defaults[$type] = array_merge($defaults[$type], [
                'platform_unit_cost' => self::formatPoints((float)($pricing[$type]['platform_unit_cost'] ?? $defaults[$type]['platform_unit_cost'])),
                'tenant_unit_price' => self::formatPoints((float)($pricing[$type]['tenant_unit_price'] ?? $defaults[$type]['tenant_unit_price'])),
            ]);
        }
        return $defaults;
    }

    private static function savePricing(int $tenantId, array $params, bool $platform): void
    {
        $row = AigcDigitalHumanConfig::where('tenant_id', $tenantId)->findOrEmpty();
        $config = $row->isEmpty() ? [] : (($row['config_json'] ?? []) ?: []);
        if (!is_array($config)) {
            $config = [];
        }
        $origin = self::config($tenantId);
        $pricing = [];
        $pricing['generate_models'] = self::normalizeGenerateModelsPricing($params['generate_models'] ?? [], $origin['generate_models'] ?? [], $platform);
        $pricing[self::TYPE_GENERATE] = $pricing['generate_models'][0] ?? ($origin[self::TYPE_GENERATE] ?? self::defaults()[self::TYPE_GENERATE]);
        foreach ([self::TYPE_AVATAR_CLONE, self::TYPE_VOICE_CLONE] as $type) {
            $input = $params[$type] ?? [];
            $platformCost = $platform
                ? self::formatPoints((float)($input['platform_unit_cost'] ?? $origin[$type]['platform_unit_cost']))
                : $origin[$type]['platform_unit_cost'];
            $tenantPrice = self::formatPoints((float)($input['tenant_unit_price'] ?? $origin[$type]['tenant_unit_price']));
            if ((float)$tenantPrice < (float)$platformCost) {
                throw new Exception('用户售价不能低于平台成本');
            }
            $pricing[$type] = [
                'platform_unit_cost' => $platformCost,
                'tenant_unit_price' => $tenantPrice,
            ];
        }
        $config['pricing'] = $pricing;
        $data = [
            'tenant_id' => $tenantId,
            'provider_mode' => (string)($row['provider_mode'] ?? 'platform'),
            'provider' => (string)($row['provider'] ?? 'xhadmin'),
            'model' => (string)($row['model'] ?? 'xiaojiayu1.0'),
            'config_json' => $config,
            'status' => (int)($row['status'] ?? 1),
            'update_time' => time(),
        ];
        if ($row->isEmpty()) {
            $data['create_time'] = time();
            AigcDigitalHumanConfig::create($data);
            return;
        }
        $row->save($data);
    }

    private static function defaults(): array
    {
        return [
            self::TYPE_GENERATE => [
                'code' => 'master',
                'name' => '大师版',
                'platform_unit_cost' => '0.20',
                'tenant_unit_price' => '0.30',
            ],
            'generate_models' => [],
            self::TYPE_AVATAR_CLONE => [
                'platform_unit_cost' => '2.00',
                'tenant_unit_price' => '3.00',
            ],
            self::TYPE_VOICE_CLONE => [
                'platform_unit_cost' => '1.00',
                'tenant_unit_price' => '2.00',
            ],
        ];
    }

    private static function formatPoints(float $points): string
    {
        return number_format(max(0, $points), 2, '.', '');
    }

    private static function withGenerateModels(array $pricing, int $tenantId): array
    {
        $configured = self::normalizeGenerateModelsSource($pricing['generate_models'] ?? []);
        $legacy = is_array($pricing[self::TYPE_GENERATE] ?? null) ? $pricing[self::TYPE_GENERATE] : self::defaults()[self::TYPE_GENERATE];
        $rows = self::platformModelRows();
        $models = [];
        foreach ($rows as $row) {
            $code = (string)$row['code'];
            $price = $configured[$code] ?? [];
            $models[] = [
                'code' => $code,
                'name' => (string)$row['name'],
                'provider' => (string)$row['provider'],
                'model' => (string)$row['model'],
                'status' => (int)$row['status'],
                'sort' => (int)$row['sort'],
                'billing_unit' => 'second',
                'platform_unit_cost' => self::formatPoints((float)($price['platform_unit_cost'] ?? $legacy['platform_unit_cost'] ?? 0)),
                'tenant_unit_price' => self::formatPoints((float)($price['tenant_unit_price'] ?? $legacy['tenant_unit_price'] ?? $legacy['platform_unit_cost'] ?? 0)),
            ];
        }
        if (empty($models)) {
            $models[] = array_merge(self::defaults()[self::TYPE_GENERATE], [
                'provider' => 'xhadmin',
                'model' => 'xiaojiayu1.0',
                'status' => 1,
                'sort' => 0,
                'billing_unit' => 'second',
            ]);
        }
        $pricing['generate_models'] = $models;
        $pricing[self::TYPE_GENERATE] = $models[0];
        return $pricing;
    }

    private static function generateModel(int $tenantId, string $modelCode): array
    {
        $config = self::config($tenantId);
        $models = $config['generate_models'] ?? [];
        foreach ($models as $model) {
            if ($modelCode === '' || (string)$model['code'] === $modelCode) {
                return $model;
            }
        }
        return $models[0] ?? ($config[self::TYPE_GENERATE] ?? self::defaults()[self::TYPE_GENERATE]);
    }

    private static function normalizeGenerateModelsPricing($input, array $origin, bool $platform): array
    {
        $originMap = array_column($origin, null, 'code');
        $inputMap = self::normalizeGenerateModelsSource($input);
        $models = [];
        foreach (self::platformModelRows() as $row) {
            $code = (string)$row['code'];
            $source = $inputMap[$code] ?? [];
            $originRow = $originMap[$code] ?? [];
            $platformCost = $platform
                ? self::formatPoints((float)($source['platform_unit_cost'] ?? $originRow['platform_unit_cost'] ?? 0))
                : self::formatPoints((float)($originRow['platform_unit_cost'] ?? 0));
            $tenantPrice = self::formatPoints((float)($source['tenant_unit_price'] ?? $originRow['tenant_unit_price'] ?? $platformCost));
            if ((float)$tenantPrice < (float)$platformCost) {
                throw new Exception('用户售价不能低于平台成本');
            }
            $models[] = [
                'code' => $code,
                'platform_unit_cost' => $platformCost,
                'tenant_unit_price' => $tenantPrice,
            ];
        }
        return $models;
    }

    private static function normalizeGenerateModelsSource($models): array
    {
        $map = [];
        if (!is_array($models)) {
            return $map;
        }
        foreach ($models as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = (string)($item['code'] ?? (is_string($key) ? $key : ''));
            if ($code === '') {
                continue;
            }
            $map[$code] = $item;
        }
        return $map;
    }

    private static function platformModelRows(): array
    {
        try {
            return AigcDigitalHumanChannel::where('tenant_id', 0)
                ->order(['sort' => 'desc', 'id' => 'asc'])
                ->field('code,name,provider,model,status,sort')
                ->select()
                ->toArray();
        } catch (Throwable $e) {
            return [];
        }
    }
}
