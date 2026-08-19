<?php

namespace app\common\service\ai;

use app\common\model\ai\AiMarketAppGate;
use InvalidArgumentException;
use RuntimeException;

/** Explicit per-tenant market admission. Missing rows always mean legacy. */
class MarketAppGateService
{
    public const LEGACY = 'legacy';
    public const MARKET = 'market';
    private const MANAGED_APP_CODES = [
        'aigc_music',
        'aigc_digital_human',
        'image_human',
        'smart_clip',
        'aigc_action_transfer',
        'aigc_person_replacement',
    ];

    public static function decide(int $tenantId, int $userId, string $appCode, string $idempotencyKey = ''): array
    {
        self::assertAppCode($appCode);
        if ($tenantId <= 0) {
            return self::decision(self::LEGACY, 'invalid_tenant');
        }
        $gate = AiMarketAppGate::where(['tenant_id' => $tenantId, 'app_code' => $appCode])->findOrEmpty();
        if ($gate->isEmpty() || (int)$gate['status'] !== 1) {
            return self::decision(self::LEGACY, 'market_disabled');
        }
        $users = self::userIds($gate['user_whitelist'] ?? []);
        if ($users !== [] && !in_array($userId, $users, true)) {
            return self::decision(self::LEGACY, 'user_not_whitelisted');
        }
        $percent = max(0, min(100, (int)$gate['rollout_percent']));
        if ($percent <= 0) {
            return self::decision(self::LEGACY, 'rollout_closed');
        }
        if ($percent < 100) {
            $key = $idempotencyKey !== '' ? $idempotencyKey : $tenantId . '|' . $userId . '|' . $appCode;
            $bucket = hexdec(substr(hash('sha256', $key), 0, 8)) % 100;
            if ($bucket >= $percent) {
                return self::decision(self::LEGACY, 'rollout_sample_miss');
            }
        }
        return self::decision(self::MARKET, 'enabled');
    }

    public static function route(int $tenantId, int $userId, string $appCode, string $idempotencyKey = ''): string
    {
        return self::decide($tenantId, $userId, $appCode, $idempotencyKey)['route'];
    }

    public static function requiresGate(string $appCode): bool
    {
        return in_array($appCode, self::MANAGED_APP_CODES, true);
    }

    /** @return array{route:string,reason:string,market:bool} */
    public static function requireMarket(int $tenantId, int $userId, string $appCode, string $idempotencyKey = ''): array
    {
        $decision = self::decide($tenantId, $userId, $appCode, $idempotencyKey);
        if (!$decision['market']) {
            throw new RuntimeException('Market entry is closed for ' . $appCode . ': ' . $decision['reason']);
        }
        return $decision;
    }

    public static function enable(int $tenantId, string $appCode, array $userWhitelist = [], int $rolloutPercent = 0): void
    {
        self::save($tenantId, $appCode, 1, $userWhitelist, $rolloutPercent);
    }

    public static function disable(int $tenantId, string $appCode): void
    {
        self::save($tenantId, $appCode, 0, [], 0);
    }

    public static function save(int $tenantId, string $appCode, int $status, array $userWhitelist = [], int $rolloutPercent = 0): void
    {
        self::assertAppCode($appCode);
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant market gate requires a tenant id');
        }
        $now = time();
        $data = [
            'tenant_id' => $tenantId,
            'app_code' => $appCode,
            'status' => $status ? 1 : 0,
            'rollout_percent' => max(0, min(100, $rolloutPercent)),
            'user_whitelist' => self::userIds($userWhitelist),
            'update_time' => $now,
        ];
        $row = AiMarketAppGate::where(['tenant_id' => $tenantId, 'app_code' => $appCode])->findOrEmpty();
        if ($row->isEmpty()) {
            $data['create_time'] = $now;
            AiMarketAppGate::create($data);
            return;
        }
        $row->save($data);
    }

    private static function decision(string $route, string $reason): array
    {
        return ['route' => $route, 'reason' => $reason, 'market' => $route === self::MARKET];
    }

    private static function assertAppCode(string $appCode): void
    {
        if (!preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $appCode)) {
            throw new InvalidArgumentException('Invalid market app code');
        }
    }

    private static function userIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true) ?: preg_split('/[,\s]+/', trim($value));
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $value), static fn(int $id): bool => $id > 0)));
    }
}
