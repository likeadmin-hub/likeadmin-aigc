<?php

namespace app\common\service\app\aigc_canvas;

use Exception;
use think\facade\Db;

/** Platform-owned Agent orchestration limits with bounded tenant overrides. */
class AigcCanvasAgentOrchestrationPolicyService
{
    public const POLICY_RUNTIME_LIMITS = 'runtime_limits';

    private const DEFAULTS = [
        self::POLICY_RUNTIME_LIMITS => [
            'max_skill_candidates' => 3,
            'max_iterations' => 6,
            'timeout_seconds' => 180,
            'max_tool_calls' => 24,
            'max_parallel_generation_tasks' => 3,
            // Platform gates are descriptive here and cannot be disabled by a tenant.
            'media_provider' => 'power_market_only',
            'enforce_entitlement' => true,
            'enforce_content_safety' => true,
            'canvas_confirmation_required' => true,
        ],
    ];

    public static function lists(int $tenantId): array
    {
        self::seed($tenantId);
        try {
            return Db::name('aigc_canvas_agent_orchestration_policy')
                ->where(['tenant_id' => $tenantId, 'delete_time' => 0])
                ->order('policy_key')
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    public static function runtime(int $tenantId): array
    {
        $defaults = self::DEFAULTS[self::POLICY_RUNTIME_LIMITS];
        self::seed($tenantId);
        try {
            $row = Db::name('aigc_canvas_agent_orchestration_policy')
                ->where(['tenant_id' => $tenantId, 'policy_key' => self::POLICY_RUNTIME_LIMITS, 'enabled' => 1, 'delete_time' => 0])
                ->find();
            $config = self::decode($row['config_json'] ?? []);
            return array_merge($defaults, self::sanitizeRuntime($config), self::lockedRuntime());
        } catch (\Throwable) {
            return array_merge($defaults, self::lockedRuntime());
        }
    }

    public static function save(int $tenantId, int $adminId, array $params): array
    {
        $key = trim((string)($params['policy_key'] ?? self::POLICY_RUNTIME_LIMITS));
        if ($key !== self::POLICY_RUNTIME_LIMITS) {
            throw new Exception('Unsupported orchestration policy');
        }
        self::seed($tenantId);
        $config = self::sanitizeRuntime(self::decode($params['config_json'] ?? $params['config'] ?? []));
        $now = time();
        $row = Db::name('aigc_canvas_agent_orchestration_policy')
            ->where(['tenant_id' => $tenantId, 'policy_key' => $key, 'delete_time' => 0])
            ->find();
        if ($row) {
            Db::name('aigc_canvas_agent_orchestration_policy')->where('id', (int)$row['id'])->update([
                'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'enabled' => !empty($params['enabled']) ? 1 : 0,
                'version' => (int)$row['version'] + 1,
                'updated_by' => $adminId,
                'update_time' => $now,
            ]);
        }
        return self::runtime($tenantId);
    }

    public static function seed(int $tenantId): void
    {
        try {
            foreach (self::DEFAULTS as $key => $config) {
                $exists = Db::name('aigc_canvas_agent_orchestration_policy')
                    ->where(['tenant_id' => $tenantId, 'policy_key' => $key, 'delete_time' => 0])
                    ->find();
                if ($exists) {
                    continue;
                }
                $now = time();
                Db::name('aigc_canvas_agent_orchestration_policy')->insert([
                    'tenant_id' => $tenantId,
                    'policy_key' => $key,
                    'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'enabled' => 1,
                    'version' => 1,
                    'updated_by' => 0,
                    'create_time' => $now,
                    'update_time' => $now,
                    'delete_time' => 0,
                ]);
            }
        } catch (\Throwable) {
            // Older deployments continue with hard-coded safe defaults until migration runs.
        }
    }

    private static function sanitizeRuntime(array $config): array
    {
        return [
            'max_skill_candidates' => max(1, min(3, (int)($config['max_skill_candidates'] ?? 3))),
            'max_iterations' => max(1, min(6, (int)($config['max_iterations'] ?? 6))),
            'timeout_seconds' => max(15, min(300, (int)($config['timeout_seconds'] ?? 180))),
            'max_tool_calls' => max(1, min(24, (int)($config['max_tool_calls'] ?? 24))),
            'max_parallel_generation_tasks' => max(1, min(3, (int)($config['max_parallel_generation_tasks'] ?? 3))),
        ];
    }

    private static function lockedRuntime(): array
    {
        return [
            'media_provider' => 'power_market_only',
            'enforce_entitlement' => true,
            'enforce_content_safety' => true,
            'canvas_confirmation_required' => true,
        ];
    }

    private static function decode($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : [];
        return is_array($decoded) ? $decoded : [];
    }
}
